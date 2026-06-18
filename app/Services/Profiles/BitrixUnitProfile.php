<?php

namespace App\Services\Profiles;

use App\Models\Unit;
use App\Services\BitrixRestClient;
use App\Services\Contracts\BitrixEntityProfile;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BitrixUnitProfile implements BitrixEntityProfile
{
    private const ENTITY_TYPE_ID = 167;

    public function __construct(
        private readonly BitrixRestClient $bitrixRestClient
    ) {}

    public function entity(): string
    {
        return 'units';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatchItems(array $items): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $now = now();

        $incomingBitrixIds = [];
        foreach ($items as $item) {
            $bitrixId = (int) ($item['id'] ?? 0);
            if ($bitrixId > 0) {
                $incomingBitrixIds[] = $bitrixId;
            }
        }

        $existing = Unit::query()
            ->whereIn('bitrix_id', $incomingBitrixIds)
            ->get(['id', 'bitrix_id', 'bitrix_updated_at']);
        $existingByBitrixId = $existing->keyBy(fn (Unit $row): int => (int) $row->bitrix_id);

        $stageIdMap = $this->loadStageIdMap();
        $apartmentIdByBitrixId = $this->loadApartmentIdMap();

        $rows = [];
        $events = [];
        $oldValues = [];

        foreach ($items as $item) {
            $processed++;

            try {
                $bitrixId = (int) ($item['id'] ?? 0);
                if ($bitrixId <= 0) {
                    throw new \RuntimeException('Invalid unit ID');
                }

                $existingRow = $existingByBitrixId->get($bitrixId);
                $normalized = $this->normalizeItem($item, $existingRow?->id, $stageIdMap, $apartmentIdByBitrixId, $now);

                $incomingUpdatedAt = $normalized['bitrix_updated_at'] instanceof Carbon
                    ? $normalized['bitrix_updated_at']->getTimestamp()
                    : null;
                $existingUpdatedAt = $existingRow?->bitrix_updated_at?->getTimestamp();

                if ($existingUpdatedAt !== null && $incomingUpdatedAt !== null && $incomingUpdatedAt <= $existingUpdatedAt) {
                    $skipped++;

                    continue;
                }

                if ($existingRow !== null) {
                    $updated++;
                    $events[$bitrixId] = 'bitrix.unit.updated';
                    $oldValues[$bitrixId] = $this->buildActivityPayloadFromModel($existingRow);
                } else {
                    $created++;
                    $events[$bitrixId] = 'bitrix.unit.created';
                }

                $rows[] = $normalized;
                $successful++;
            } catch (\Throwable) {
                $failedIds[] = $item['id'] ?? 'unknown';
            }
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows, $events, $oldValues, $now): void {
                Unit::query()->upsert(
                    $rows,
                    ['bitrix_id'],
                    [
                        'apartment_id',
                        'title',
                        'stage_id',
                        'internal_number',
                        'is_deleted',
                        'bitrix_created_at',
                        'bitrix_updated_at',
                        'last_synced_at',
                        'updated_at',
                    ]
                );

                $models = Unit::query()
                    ->whereIn('bitrix_id', array_map(static fn (array $r): int => (int) $r['bitrix_id'], $rows))
                    ->get();
                $modelByBitrix = $models->keyBy(fn (Unit $row): int => (int) $row->bitrix_id);

                $logRows = [];
                foreach ($rows as $row) {
                    $bitrixId = (int) $row['bitrix_id'];
                    $model = $modelByBitrix->get($bitrixId);
                    if (! $model instanceof Unit) {
                        continue;
                    }

                    $newPayload = $this->buildActivityPayloadFromModel($model);
                    $event = $events[$bitrixId] ?? 'bitrix.unit.synced';
                    $oldPayload = $event === 'bitrix.unit.updated' ? ($oldValues[$bitrixId] ?? null) : null;
                    if ($event === 'bitrix.unit.updated' && ! $this->hasMeaningfulDiff($oldPayload, $newPayload)) {
                        continue;
                    }

                    $logRows[] = [
                        'id' => (string) Str::uuid(),
                        'event' => $event,
                        'subject_type' => Unit::class,
                        'subject_id' => $model->id,
                        'user_id' => null,
                        'old_values' => $oldPayload !== null ? json_encode($oldPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'new_values' => json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'happened_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($logRows !== []) {
                    DB::table('activity_logs')->insert($logRows);
                }
            });
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
        ];
    }

    public function syncSingleItemByBitrixId(int $bitrixId): bool
    {
        $response = $this->bitrixRestClient->postJson('crm.item.get.json', [
            'entityTypeId' => self::ENTITY_TYPE_ID,
            'id' => $bitrixId,
        ]);
        $item = data_get($response, 'result.item', null);
        if (! is_array($item)) {
            return false;
        }

        $result = $this->syncBatchItems([$item]);

        return $result['successful'] > 0 || $result['skipped'] > 0;
    }

    public function markItemDeleted(int $bitrixId): int
    {
        return Unit::query()
            ->where('bitrix_id', $bitrixId)
            ->update([
                'is_deleted' => true,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $stageIdMap
     * @param  array<int, string>  $apartmentIdByBitrixId
     * @return array<string, mixed>
     */
    private function normalizeItem(
        array $item,
        ?string $existingId,
        array $stageIdMap,
        array $apartmentIdByBitrixId,
        Carbon $now
    ): array {
        $bitrixId = (int) ($item['id'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid unit ID');
        }

        $apartBitrixId = $this->extractFirstInt($item['ufCrm8_1684429208'] ?? null);
        if ($apartBitrixId === null) {
            throw new \RuntimeException('Apartment link is empty');
        }
        $apartmentId = $apartmentIdByBitrixId[$apartBitrixId] ?? null;
        if (! is_string($apartmentId) || $apartmentId === '') {
            throw new \RuntimeException('Apartment not found locally');
        }

        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $internalNumber = $this->firstNonEmptyString($item['ufCrm8_1698838056004'] ?? null, $item['ufCrm6_1707234311507'] ?? null);

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'apartment_id' => $apartmentId,
            'title' => $this->toNullableString($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'internal_number' => $internalNumber,
            'is_deleted' => false,
            'bitrix_created_at' => $this->toNullableDateTime($item['createdTime'] ?? null),
            'bitrix_updated_at' => $this->toNullableDateTime($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function loadStageIdMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', 'unit')
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $bitrixStageId): array => [(string) $bitrixStageId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function loadApartmentIdMap(): array
    {
        return DB::table('apartments')
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
            ->all();
    }

    private function buildActivityPayloadFromModel(Unit $unit): array
    {
        return Arr::only($unit->toArray(), [
            'bitrix_id',
            'apartment_id',
            'title',
            'stage_id',
            'internal_number',
            'is_deleted',
        ]);
    }

    private function hasMeaningfulDiff(?array $oldValues, array $newValues): bool
    {
        if ($oldValues === null) {
            return true;
        }

        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        foreach ($keys as $key) {
            $old = $this->normalizeDiffValue($oldValues[$key] ?? null);
            $new = $this->normalizeDiffValue($newValues[$key] ?? null);
            if ($old !== $new) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDiffValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function toNullableDateTime(mixed $value): ?Carbon
    {
        $string = $this->toNullableString($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string);
    }

    private function extractFirstInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_numeric($first) ? (int) $first : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->toNullableString($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
