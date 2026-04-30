<?php

namespace App\Console\Commands;

use App\Services\BitrixUnitsSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Command;
use Throwable;

class SyncBitrixUnitsCommand extends Command
{
    protected $signature = 'bitrix:sync-units-snapshot';

    protected $description = 'Sync units from Bitrix24 to local database';

    /**
     * @return int
     */
    public function handle(BitrixUnitsSyncService $service): int
    {
        $this->info('Bitrix units sync started...');

        try {
            $result = $service->sync();

            Log::channel('bitrix_units_snapshot')->info('Bitrix units sync completed', $result);

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d.',
                $result['total'],
                $result['successful'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed unit ids: '.implode(', ', $result['failed_unit_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_units_snapshot')->error('Bitrix units sync failed', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Bitrix units sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
