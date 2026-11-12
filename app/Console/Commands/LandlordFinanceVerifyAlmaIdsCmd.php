<?php

namespace App\Console\Commands;

use App\Services\LandlordFinanceRegistrySync;
use Illuminate\Console\Command;

class LandlordFinanceVerifyAlmaIdsCmd extends Command
{
    protected $signature = 'landlord-finance:verify-alma-ids';

    protected $description = 'Check registry landlord_id/apart_id against Alma (no finance push)';

    /**
     * Verify cached finance ids exist in Alma.
     */
    public function handle(LandlordFinanceRegistrySync $sync): int
    {
        $this->warn('Alma lookup only — finance will NOT be pushed.');
        $summary = $sync->verifyRegistryAgainstAlma();

        $this->info(sprintf(
            'checked=%d ok=%d failed=%d',
            $summary['checked'],
            $summary['ok'],
            $summary['failed'],
        ));

        foreach ($summary['errors'] as $error) {
            $this->error(sprintf(
                '#%s %s | ll=%s unit=%s | %s',
                $error['id'] ?? '',
                $error['title'] ?? '',
                $error['landlord_id'] ?? '',
                $error['apart_id'] ?? '',
                $error['error'] ?? '',
            ));
        }

        $this->line('Report: storage/app/google/landlord_finance_alma_id_check.json');

        return self::SUCCESS;
    }
}