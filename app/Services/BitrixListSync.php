<?php

namespace App\Services;

use App\Support\BitrixSoftDelete;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BitrixListSync
{
    public function __construct(
        private readonly BitrixRest $bitrix,
        private readonly EntitySync $sync
    ) {}

    /**
     * Paginate crm.item.list, sync batches via entity profile, reconcile soft-deletes.
     *
     * @param  list<string>  $select
     * @param  class-string<Model>  $modelClass
     * @param  callable(int $total, int $successful, int $failedCount): void|null  $onBatch
     * @return array{total:int,created:int,updated:int,successful:int,skipped:int,failed:int,failed_ids:list<int|string>,marked_deleted:int}
     */
    public function sync(
        int $entityTypeId,
        string $entityKey,
        array $select,
        string $modelClass,
        Carbon $now,
        ?callable $onBatch = null,
        ?int $portalId = null
    ): array {
        return $this->syncList(
            method: 'crm.item.list.json',
            payload: [
                'entityTypeId' => $entityTypeId,
                'select' => $select,
            ],
            itemsPath: 'result.items',
            idKey: 'id',
            entityKey: $entityKey,
            modelClass: $modelClass,
            now: $now,
            onBatch: $onBatch,
            portalId: $portalId,
        );
    }

    /**
     * Paginate a Bitrix list endpoint, sync batches, reconcile soft-deletes.
     *
     * @param  array<string, mixed>  $payload
     * @param  class-string<Model>  $modelClass
     * @param  callable(int $total, int $successful, int $failedCount): void|null  $onBatch
     * @return array{total:int,created:int,updated:int,successful:int,skipped:int,failed:int,failed_ids:list<int|string>,marked_deleted:int}
     */
    public function syncList(
        string $method,
        array $payload,
        string $itemsPath,
        string $idKey,
        string $entityKey,
        string $modelClass,
        Carbon $now,
        ?callable $onBatch = null,
        ?int $portalId = null,
        int $pauseUs = 0,
    ): array {
        $start = 0;
        $total = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $seenIds = [];

        while (true) {
            $response = $this->bitrix->postJson($method, array_merge($payload, [
                'start' => $start,
            ]));

            $items = data_get($response, $itemsPath, []);
            if (! is_array($items) || $items === []) {
                break;
            }

            $batchItems = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $bitrixId = (int) ($item[$idKey] ?? $item['ID'] ?? $item['id'] ?? 0);
                if ($bitrixId > 0) {
                    $seenIds[] = $bitrixId;
                }
                $batchItems[] = $item;
            }

            $batch = $this->sync->syncBatch($batchItems, $entityKey);
            $total += $batch['processed'];
            $created += $batch['created'];
            $updated += $batch['updated'];
            $successful += $batch['successful'];
            $skipped += $batch['skipped'];
            $failedIds = array_merge($failedIds, $batch['failed_ids']);

            if ($onBatch !== null) {
                $onBatch($total, $successful, count($failedIds));
            }

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
            if ($pauseUs > 0) {
                usleep($pauseUs);
            }
        }

        $markedDeleted = 0;
        if ($total > 0) {
            $markedDeleted = BitrixSoftDelete::markMissing(
                $modelClass,
                array_values(array_unique($seenIds)),
                $now,
                $portalId
            );
        }

        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
            'marked_deleted' => $markedDeleted,
        ];
    }
}
