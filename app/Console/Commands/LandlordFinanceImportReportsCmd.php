<?php

namespace App\Console\Commands;

use App\Services\LandlordFinanceReportImporter;
use Illuminate\Console\Command;

class LandlordFinanceImportReportsCmd extends Command
{
    protected $signature = 'landlord-finance:import-reports
        {--index=1Xhy37Z2-OOTUL3awBSzE3kp44gDMasbD1mYll-PphX8 : Reports_2026 spreadsheet id}
        {--year=2026 : Finance year to push}
        {--dry-run : Register/resolve only, do not POST to Alma}';

    protected $description = 'Import report links from Reports_2026 (Oct→Jan) and push to Alma';

    /**
     * Import linked unit reports and push finance year batches.
     */
    public function handle(LandlordFinanceReportImporter $importer): int
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

        $this->info('Importing report links from '.implode(' → ', $months).'...');

        $summary = $importer->importFromIndex(
            (string) $this->option('index'),
            $months,
            (int) $this->option('year'),
            (bool) $this->option('dry-run'),
        );

        $this->info(sprintf(
            'links=%d unique=%d ok=%d failed=%d',
            $summary['links'],
            $summary['unique'],
            $summary['ok'],
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

        $this->line('Errors JSON: storage/app/google/landlord_finance_import_errors.json');

        return self::SUCCESS;
    }
}
