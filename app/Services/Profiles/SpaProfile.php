<?php

namespace App\Services\Profiles;

use App\Services\BitrixRest;
use App\Services\Contracts\EntityProfile;
use App\Support\BitrixDiff;
use App\Support\BitrixSync;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class SpaProfile implements EntityProfile
{
    public function __construct(
        protected readonly BitrixRest $bitrixRestClient,
        protected readonly BitrixSync $syncContext
    ) {}

    abstract public function entity(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    abstract protected function typeId(): int;

    /**
     * Event prefix without suffix, e.g. bitrix.unit.
     */
    abstract protected function eventBase(): string;

    /**
     * Local pipelines/stages entity_type value.
     */
    abstract protected function stageType(): string;

    /**
     * @return list<string>
     */
    abstract protected function updateCols(): array;

    /**
     * @return list<string>
     */
    abstract protected function logKeys(): array;

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
        $modelClass = $this->modelClass();
        $updatedEvent = $this->eventBase().'.updated';
        $createdEvent = $this->eventBase().'.created';
        $syncedEvent = $this->eventBase().'.synced';

        $incomingBitrixIds = [];
        foreach ($items as $item) {
            $bitrixId = (int) ($item['id'] ?? 0);
            if ($bitrixId > 0) {
                $incomingBitrixIds[] = $bitrixId;
            }
        }

        $existing = $modelClass::query()
            ->whereIn('bitrix_id', $incomingBitrixIds)
            ->get(['id', 'bitrix_id', 'bitrix_updated_at']);
        $existingByBitrixId = $existing->keyBy(static fn (Model $row): int => (int) $row->getAttribute('bitrix_id'));

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

                $existingRow = $existingByBitrixId->get($bitrixId);
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
                    $oldValues[$bitrixId] = $this->logPayload($existingRow);
                } else {
                    $created++;
                    $events[$bitrixId] = $createdEvent;
                }

                $rows[] = $normalized;
                $successful++;
            } catch (\Throwable) {
                $failedIds[] = $item['id'] ?? 'unknown';
            }
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows, $events, $oldValues, $now, $modelClass, $updatedEvent, $syncedEvent): void {
                $modelClass::query()->upsert($rows, ['bitrix_id'], $this->updateCols());

                $models = $modelClass::query()
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

                    $newPayload = $this->logPayload($model);
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
        $response = $this->bitrixRestClient->postJson('crm.item.get.json', [
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
        return $this->modelClass()::query()
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
    protected function loadStageIdMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', $this->stageType())
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $bitrixStageId): array => [(string) $bitrixStageId => (int) $id])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function logPayload(Model $model): array
    {
        return Arr::only($model->toArray(), $this->logKeys());
    }
}
