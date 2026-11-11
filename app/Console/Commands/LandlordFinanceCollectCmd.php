<?php

namespace App\Console\Commands;

use App\Jobs\CollectLandlordFinance;
use Illuminate\Console\Command;

class LandlordFinanceCollectCmd extends Command
{
    protected $signature = 'landlord-finance:collect
        {--id= : Registry row id}
        {--year= : Comma-separated years, e.g. 2026 or 2025,2026}
        {--dry-run : Read and build payload without Alma POST}';

    protected $description = 'Sync landlord finance Google Sheets into Alma';

    /**
     * Dispatch synchronous landlord finance collection.
     */
    public function handle(): int
    {
        $idOption = $this->option('id');
        $registryId = $idOption !== null && $idOption !== '' ? (int) $idOption : null;

        $years = null;
        $yearOption = trim((string) $this->option('year'));
        if ($yearOption !== '') {
            $years = [];
            foreach (explode(',', $yearOption) as $part) {
                $year = (int) trim($part);
                if ($year >= 2000 && $year <= 2100) {
                    $years[] = $year;
                }
            }
            $years = array_values(array_unique($years));
        }

        CollectLandlordFinance::dispatchSync(
            $registryId,
            $years,
            (bool) $this->option('dry-run'),
        );

        $this->info('Landlord finance collected.');

        return self::SUCCESS;
    }
}
