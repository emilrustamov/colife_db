<?php

namespace App\Console\Commands;

use App\Jobs\SyncBitrixDiskListJob;
use App\Services\BitrixDiskSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBitrixDiskCommand extends Command
{
    protected $signature = 'bitrix:sync-disk
                            {listId? : Bitrix universal list IBLOCK_ID}
                            {--element= : Sync a single list element ID}
                            {--queue : Dispatch sync to the queue instead of running inline}';

    protected $description = 'Sync Bitrix list document files onto local disk storage';

    /**
     * Execute the console command.
     */
    public function handle(BitrixDiskSyncService $service): int
    {
        $listId = (int) ($this->argument('listId')
            ?: config('services.bitrix.lists.disk_iblock_id', 322));

        if ($listId <= 0) {
            $this->error('Invalid list ID.');

            return self::FAILURE;
        }

        $elementOption = $this->option('element');
        $elementId = is_numeric($elementOption) ? (int) $elementOption : null;

        if ($this->option('queue')) {
            SyncBitrixDiskListJob::dispatch($listId, $elementId);
            $this->info(sprintf(
                'Queued disk sync for list %d%s.',
                $listId,
                $elementId !== null ? ', element '.$elementId : ''
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Bitrix disk sync started for list %d%s...',
            $listId,
            $elementId !== null ? ', element '.$elementId : ''
        ));

        try {
            $result = $service->sync($listId, $elementId);

            $this->info(sprintf(
                'Completed. Elements: %d processed, %d skipped, downloaded: %d, unchanged: %d, marked deleted: %d, failed: %d.',
                $result['processed_elements'],
                $result['skipped_elements'],
                $result['downloaded'],
                $result['unchanged'],
                $result['marked_deleted'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed element ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_disk')->error('Bitrix disk sync failed', [
                'list_id' => $listId,
                'element_id' => $elementId,
                'error' => $e->getMessage(),
            ]);

            $this->error('Bitrix disk sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
