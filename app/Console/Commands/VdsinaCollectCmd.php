<?php

namespace App\Console\Commands;

use App\Jobs\CollectVdsinaBalance;
use Illuminate\Console\Command;

class VdsinaCollectCmd extends Command
{
    protected $signature = 'vdsina:collect';

    protected $description = 'Collect VDSina account balance and alert Bitrix when low';

    /**
     * Dispatch synchronous collection of VDSina account balance.
     */
    public function handle(): int
    {
        $balanceData = CollectVdsinaBalance::dispatchSync();

        if ($balanceData === null) {
            $this->warn('VDSina API token is not configured.');

            return self::SUCCESS;
        }

        $this->info('VDSina account balance collected.');
        $this->line('Real: $'.number_format($balanceData['real'], 2, '.', ''));
        $this->line('Bonus: $'.number_format($balanceData['bonus'], 2, '.', ''));
        $this->line('Partner: $'.number_format($balanceData['partner'], 2, '.', ''));

        if (is_string($balanceData['forecast']) && $balanceData['forecast'] !== '') {
            $this->line('Forecast: '.$balanceData['forecast']);
        }

        return self::SUCCESS;
    }
}
