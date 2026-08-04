<?php

namespace App\Services;

use App\Support\BitrixSoftDelete;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BitrixListSync
{
    public function __construct(
        private readonly BitrixRest $bitrixRestClient,
        private readonly EntitySync $syncService
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
        ?callable $onBatch = null
    ): array {
        $start = 0;
        $total = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $seenBitrixIds = [];

        while (true) {
            $response = $this->bitrixRestClient->postJson('crm.item.list.json', [
                'entityTypeId' => $entityTypeId,
                'select' => $select,
                'start' => $start,
            ]);

            $items = data_get($response, 'result.items', []);
            if (! is_array($items) || $items === []) {
                break;
            }

            $batchItems = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $bitrixId = (int) ($item['id'] ?? 0);
                if ($bitrixId > 0) {
                    $seenBitrixIds[] = $bitrixId;
                }
                $batchItems[] = $item;
            }

            $batch = $this->syncService->syncBatch($batchItems, $entityKey);
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
        }

        $markedDeleted = 0;
        if ($total > 0) {
            $markedDeleted = BitrixSoftDelete::markMissing(
                $modelClass,
                array_values(array_unique($seenBitrixIds)),
                $now
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
