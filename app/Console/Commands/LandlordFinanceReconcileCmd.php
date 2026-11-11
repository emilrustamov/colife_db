<?php

namespace App\Console\Commands;

use App\Services\LandlordFinanceBitrixReconcile;
use Illuminate\Console\Command;

class LandlordFinanceReconcileCmd extends Command
{
    protected $signature = 'landlord-finance:reconcile
        {--id= : Registry row id}
        {--no-notify : Do not send Telegram alert}';

    protected $description = 'Compare Alma finance external_ids with Bitrix SPA 148 owner/apartment';

    /**
     * Run Alma ↔ Bitrix ownership reconciliation.
     */
    public function handle(LandlordFinanceBitrixReconcile $reconcile): int
    {
        $idOption = $this->option('id');
        $ids = null;
        if ($idOption !== null && $idOption !== '') {
            $ids = [(int) $idOption];
        }

        $summary = $reconcile->reconcile(
            $ids,
            ! (bool) $this->option('no-notify'),
        );

        $this->info(sprintf(
            'checked=%d matched=%d mismatched=%d missing_bitrix=%d',
            $summary['checked'],
            $summary['matched'],
            $summary['mismatched'],
            $summary['missing_bitrix'],
        ));

        foreach ($summary['issues'] as $issue) {
            $this->warn(sprintf(
                '#%s [%s] %s',
                $issue['registry_id'] ?? '?',
                $issue['status'] ?? '',
                $issue['message'] ?? '',
            ));
        }

        return self::SUCCESS;
    }
}
