<?php

namespace App\Console\Commands;

use App\Services\CleaningHistorySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCleaningHistoryCommand extends Command
{
    protected $signature = 'cleaning:sync-history {--apartment= : Optional Bitrix apartment id}';

    protected $description = 'Backfill cleaning_reports from TR apartment timeline comments';

    /**
     * Execute the console command.
     */
    public function handle(CleaningHistorySync $service): int
    {
        $apartment = $this->option('apartment');
        $onlyId = is_numeric($apartment) ? (int) $apartment : null;

        $this->info('Cleaning history sync started...');

        try {
            $result = $service->sync($onlyId);
            $this->info(sprintf(
                'Completed. Apartments: %d, comments scanned: %d, upserted: %d, failed: %d.',
                $result['apartments'],
                $result['comments'],
                $result['upserted'],
                $result['failed']
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_cleaning')->error('CLEANING_HISTORY_SYNC_FAILED', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Cleaning history sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
