<?php

namespace App\Console\Commands;

use App\Services\LandlordContactsSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncLandlordContactsCommand extends Command
{
    protected $signature = 'bitrix:sync-landlord-contacts
        {--dry-run : Compute diffs without writing to Bitrix}
        {--limit= : Process only first N landlord contacts}';

    protected $description = 'Sync landlord contact type and Apartments / Ex apartments CRM fields from local apartments';

    /**
     * Push landlord TYPE_ID and apartment bindings to Bitrix contacts.
     */
    public function handle(LandlordContactsSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null && $limitOption !== '' ? (int) $limitOption : null;

        $this->info($dryRun
            ? 'Landlord contacts sync (dry-run) started...'
            : 'Landlord contacts sync started...');

        try {
            $result = $sync->sync($dryRun, $limit);

            Log::channel('bitrix_landlord_contacts')->info('Landlord contacts sync completed', $result);

            $this->info(sprintf(
                'Completed. Landlords: %d, checked: %d, updated: %d, unchanged: %d, failed: %d%s.',
                $result['landlords'],
                $result['checked'],
                $result['updated'],
                $result['unchanged'],
                $result['failed'],
                $dryRun ? ' (dry-run)' : ''
            ));

            if ($result['failures'] !== []) {
                $this->warn('Failed contact ids: '.implode(', ', array_column($result['failures'], 'id')));
            }

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('bitrix_landlord_contacts')->error('Landlord contacts sync failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error('Landlord contacts sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
