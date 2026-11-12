<?php

namespace App\Console\Commands;

use App\Services\LandlordFinanceRegistrySync;
use Illuminate\Console\Command;

class LandlordFinanceSyncRegistryCmd extends Command
{
    protected $signature = 'landlord-finance:sync-registry
        {--index=1Xhy37Z2-OOTUL3awBSzE3kp44gDMasbD1mYll-PphX8 : Reports_2026 spreadsheet id}
        {--year=2026 : Finance year tab to bind}';

    protected $description = 'Fill landlord_finance_sheets from report links (DB only, never pushes Alma)';

    /**
     * Sync registry rows from index links without Alma push.
     */
    public function handle(LandlordFinanceRegistrySync $sync): int
    {
        $months = [
            'October',
            'September',
            'August',
            'July',
            'June',
            'May',
            'Apr',
            'Mar',
            'Feb',
            'Jan',
        ];

        $this->warn('DB registry only — Alma will NOT be called.');
        $this->info('Syncing registry from '.implode(' → ', $months).'...');

        $summary = $sync->syncFromIndex(
            (string) $this->option('index'),
            $months,
            (int) $this->option('year'),
        );

        $this->info(sprintf(
            'links=%d unique=%d ok=%d skipped_summary=%d external=%d failed=%d',
            $summary['links'],
            $summary['unique'],
            $summary['ok'],
            $summary['skipped_summary'],
            $summary['external'],
            $summary['failed'],
        ));

        foreach ($summary['errors'] as $error) {
            $this->error(sprintf(
                '%s | %s | %s',
                $error['name'] ?? '',
                $error['error'] ?? '',
                $error['url'] ?? '',
            ));
        }

        $this->line('Report JSON: storage/app/google/landlord_finance_registry_sync.json');

        return self::SUCCESS;
    }
}
