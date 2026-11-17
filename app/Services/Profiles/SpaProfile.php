<?php

namespace App\Services\Profiles;

use App\Models\Portal;
use App\Services\BitrixRest;
use App\Services\Contracts\EntityProfile;
use App\Support\BitrixDiff;
use App\Support\BitrixEnums;
use App\Support\BitrixSync;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class SpaProfile implements EntityProfile
{
    /**
     * @var array<string, array<string, string>>|null
     */
    private ?array $enumCache = null;

    public function __construct(
        protected readonly BitrixRest $bitrix,
        protected readonly BitrixSync $syncContext
    ) {}

    abstract public function entity(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    abstract protected function typeId(): int;

    /**
     * Event prefix without suffix, e.g. bitrix.unit.
     */
    abstract protected function event(): string;

    /**
     * Local pipelines/stages entity_type value.
     */
    abstract protected function stageEntity(): string;

    /**
     * Business columns upserted from Bitrix (without sync meta).
     *
     * @return list<string>
     */
    abstract protected function fields(): array;

    /**
     * Maps and caches prepared once per batch.
     *
     * @return array<string, mixed>
     */
    abstract protected function context(): array;

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    abstract protected function normalizeItem(array $item, mixed $existingId, array $context, Carbon $now): array;

    /**
     * Default portal for AE sync profiles.
     */
    protected function portalId(): int
    {
        return Portal::idByCode('ae');
    }

    /**
     * @return list<string>
     */
    protected function upsertCols(): array
    {
        return array_merge($this->fields(), [
            'is_deleted',
            'bitrix_created_at',
            'bitrix_updated_at',
            'last_synced_at',
            'updated_at',
        ]);
    }

    /**
     * @return list<string>
     */
    protected function logKeys(): array
    {
        return array_merge(['bitrix_id'], $this->fields(), ['is_deleted']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatch(array $items): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $now = now();
        $modelClass = $this->model();
        $portalId = $this->portalId();
        $updatedEvent = $this->event().'.updated';
        $createdEvent = $this->event().'.created';
        $syncedEvent = $this->event().'.synced';

        $incomingIds = [];
        foreach ($items as $item) {
            $bitrixId = (int) ($item['id'] ?? 0);
            if ($bitrixId > 0) {
                $incomingIds[] = $bitrixId;
            }
        }

        $existing = $modelClass::query()
            ->where('portal_id', $portalId)
            ->whereIn('bitrix_id', $incomingIds)
            ->get(['id', 'bitrix_id', 'bitrix_updated_at']);
        $existingById = $existing->keyBy(static fn (Model $row): int => (int) $row->getAttribute('bitrix_id'));

        $context = $this->context();

        $rows = [];
        $events = [];
        $oldValues = [];

        foreach ($items as $item) {
            $processed++;

            try {
                $bitrixId = (int) ($item['id'] ?? 0);
                if ($bitrixId <= 0) {
                    throw new \RuntimeException('Invalid Bitrix item ID');
                }

                $existingRow = $existingById->get($bitrixId);
                $normalized = $this->normalizeItem($item, $existingRow?->getAttribute('id'), $context, $now);

                $incomingUpdatedAt = $normalized['bitrix_updated_at'] instanceof Carbon
                    ? $normalized['bitrix_updated_at']->getTimestamp()
                    : null;
                $existingUpdatedAt = $existingRow?->getAttribute('bitrix_updated_at')?->getTimestamp();

                if (
                    ! $this->syncContext->forcing()
                    && $existingUpdatedAt !== null
                    && $incomingUpdatedAt !== null
                    && $incomingUpdatedAt <= $existingUpdatedAt
                ) {
                    $skipped++;

                    continue;
                }

                if ($existingRow !== null) {
                    $updated++;
                    $events[$bitrixId] = $updatedEvent;
                    $oldValues[$bitrixId] = $this->snapshot($existingRow);
                } else {
                    $created++;
                    $events[$bitrixId] = $createdEvent;
                }

                $rows[] = $normalized;
                $successful++;
            } catch (\Throwable $e) {
                $failedIds[] = $item['id'] ?? 'unknown';
                Log::warning('SPA_PROFILE_ITEM_FAILED', [
                    'entity' => $modelClass,
                    'bitrix_id' => $item['id'] ?? null,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows, $events, $oldValues, $now, $modelClass, $portalId, $updatedEvent, $syncedEvent): void {
                $modelClass::query()->upsert($rows, ['portal_id', 'bitrix_id'], $this->upsertCols());

                $models = $modelClass::query()
                    ->where('portal_id', $portalId)
                    ->whereIn('bitrix_id', array_map(static fn (array $r): int => (int) $r['bitrix_id'], $rows))
                    ->get();
                $modelByBitrix = $models->keyBy(static fn (Model $row): int => (int) $row->getAttribute('bitrix_id'));

                $logRows = [];
                foreach ($rows as $row) {
                    $bitrixId = (int) $row['bitrix_id'];
                    $model = $modelByBitrix->get($bitrixId);
                    if (! $model instanceof Model) {
                        continue;
                    }

                    $newPayload = $this->snapshot($model);
                    $event = $events[$bitrixId] ?? $syncedEvent;
                    $oldPayload = $event === $updatedEvent ? ($oldValues[$bitrixId] ?? null) : null;
                    if ($event === $updatedEvent && ! BitrixDiff::changed($oldPayload, $newPayload)) {
                        continue;
                    }

                    $logRows[] = [
                        'id' => (string) Str::uuid(),
                        'event' => $event,
                        'subject_type' => $modelClass,
                        'subject_id' => $model->getKey(),
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

    /**
     * Fetch one SPA item and sync it.
     */
    public function syncOne(int $bitrixId): bool
    {
        $response = $this->bitrix->postJson('crm.item.get.json', [
            'entityTypeId' => $this->typeId(),
            'id' => $bitrixId,
        ]);
        $item = data_get($response, 'result.item', null);
        if (! is_array($item)) {
            return false;
        }

        $result = $this->syncBatch([$item]);

        return $result['successful'] > 0 || $result['skipped'] > 0;
    }

    /**
     * Soft-delete local row by Bitrix id.
     */
    public function markDeleted(int $bitrixId): int
    {
        return $this->model()::query()
            ->where('portal_id', $this->portalId())
            ->where('bitrix_id', $bitrixId)
            ->update([
                'is_deleted' => true,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, int>
     */
    protected function stageMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', $this->stageEntity())
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $bitrixStageId): array => [(string) $bitrixStageId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function idMap(string $table, bool $scopedPortal = false): array
    {
        $query = DB::table($table);
        if ($scopedPortal) {
            $query->where('portal_id', $this->portalId());
        }

        return $query
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
            ->all();
    }

    /**
     * @param  array<int, string>  $map
     */
    protected function requireMappedId(array $map, ?int $bitrixId, string $emptyMsg, string $missingMsg): string
    {
        if ($bitrixId === null) {
            throw new \RuntimeException($emptyMsg);
        }

        $id = $map[$bitrixId] ?? null;
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException($missingMsg);
        }

        return $id;
    }

    /**
     * Shared sync meta columns for normalizeItem.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function stamps(array $item, Carbon $now): array
    {
        return [
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['createdTime'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function loadEnums(bool $enumerationOnly = true, ?int $entityTypeId = null): array
    {
        if ($this->enumCache !== null) {
            return $this->enumCache;
        }

        $this->enumCache = BitrixEnums::load(
            $this->bitrix,
            $entityTypeId ?? $this->typeId(),
            $enumerationOnly
        );

        return $this->enumCache;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Model $model): array
    {
        return Arr::only($model->toArray(), $this->logKeys());
    }
}
