<?php

namespace App\Console\Commands;

use App\Services\BitrixUtilitiesSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncBitrixUtilitiesCommand extends Command
{
    protected $signature = 'bitrix:sync-utilities';

    protected $description = 'Sync Utilities list from Bitrix24 to local database';

    /**
     * Execute the console command.
     */
    public function handle(BitrixUtilitiesSyncService $service): int
    {
        $this->info('Bitrix utilities sync started...');

        try {
            $result = $service->sync();

            Log::channel('bitrix_utilities')->info('Bitrix utilities sync completed', $result);

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d, marked deleted: %d.',
                $result['total'],
                $result['successful'],
                $result['failed'],
                $result['marked_deleted']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed utility ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_utilities')->error('Bitrix utilities sync failed', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Bitrix utilities sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
