<?php

namespace App\Console\Commands;

use App\Jobs\CalculatePriceReports;
use App\Services\Prices\MonthlyReportCalculator;
use Illuminate\Console\Command;
use RuntimeException;

class PricesCalculateCmd extends Command
{
    protected $signature = 'prices:calculate {--queue : Dispatch to queue instead of running inline}';

    protected $description = 'Rebuild occupancy/revenue monthly reports from unit_stays';

    public function handle(MonthlyReportCalculator $calculator): int
    {
        if ($this->option('queue')) {
            CalculatePriceReports::dispatch();
            $this->info('prices:calculate dispatched to queue.');

            return self::SUCCESS;
        }

        try {
            $stats = $calculator->rebuild();
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already running')) {
                $this->warn($e->getMessage());

                return self::FAILURE;
            }

            throw $e;
        }

        $this->info(sprintf(
            'Processed %d / %d stays → %d report rows (skipped: no_type=%d dup=%d invalid=%d errors=%d)',
            $stats['processed'],
            $stats['total'],
            $stats['reports'],
            $stats['skipped_no_type'],
            $stats['skipped_duplicates'],
            $stats['skipped_invalid_days'],
            $stats['skipped_errors'],
        ));

        return self::SUCCESS;
    }
}
