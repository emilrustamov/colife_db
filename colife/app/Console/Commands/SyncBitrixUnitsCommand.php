<?php

namespace App\Console\Commands;

use App\Services\BitrixUnitsSyncService;
use Illuminate\Console\Command;

class SyncBitrixUnitsCommand extends Command
{
    protected $signature = 'bitrix:sync-units';

    protected $description = 'Sync units from Bitrix24 to local database';

    /**
     * @return int
     */
    public function handle(BitrixUnitsSyncService $service): int
    {
        $this->info('Bitrix units sync started...');

        $service->sync();

        $this->info('Bitrix units sync completed.');

        return self::SUCCESS;
    }
}
