<?php

namespace App\Console\Commands;

use App\Services\CleaningStaffSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCleaningStaffCommand extends Command
{
    protected $signature = 'cleaning:sync-staff';

    protected $description = 'Sync Cleaning staff universal list from TR Bitrix24 into local cleaning_staff';

    /**
     * Execute the console command.
     */
    public function handle(CleaningStaffSync $service): int
    {
        $this->info('Cleaning staff sync started...');

        try {
            $result = $service->sync();

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d, marked deleted: %d.',
                $result['total'],
                $result['successful'],
                $result['failed'],
                $result['marked_deleted']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed element ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->error('CLEANING_STAFF_SYNC_FAILED', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Cleaning staff sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
