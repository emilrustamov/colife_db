<?php

namespace App\Console\Commands;

use App\Services\RepairDiskSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncRepairDiskCommand extends Command
{
    protected $signature = 'bitrix:sync-repair-disk
                            {--id= : Sync a single apartment SPA item ID}';

    protected $description = 'Sync files from apartment Repair/Photo, Estimate, Invoices folders. Scheduled daily 23:00 Europe/Moscow.';

    /**
     * Execute the console command.
     */
    public function handle(RepairDiskSync $service): int
    {
        $idOption = $this->option('id');
        $apartmentId = is_numeric($idOption) ? (int) $idOption : null;

        $this->info('Repair disk sync started'.($apartmentId !== null ? ' for apartment '.$apartmentId : '').'...');

        try {
            @set_time_limit(0);
            $result = $service->sync($apartmentId);
        } catch (Throwable $e) {
            Log::channel('bitrix_disk')->error('Repair disk sync failed', [
                'apartment_id' => $apartmentId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Repair disk sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Completed. Apartments: %d processed, %d skipped, downloaded: %d, unchanged: %d, failed: %d.',
            $result['processed'],
            $result['skipped'],
            $result['downloaded'],
            $result['unchanged'],
            $result['failed']
        ));

        if ($result['failed'] > 0) {
            $this->warn('Failed apartment ids: '.implode(', ', $result['failed_ids']));
        }

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
