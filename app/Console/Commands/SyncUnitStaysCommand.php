<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncUnitStaysCommand extends Command
{
    protected $signature = 'bitrix:sync-unit-stays {--sync-stages=1}';

    protected $description = 'Sync tenant unit stays (SPA 183) from Bitrix24';

    /**
     * Alias for bitrix:sync-spa unit-stays.
     */
    public function handle(): int
    {
        return $this->call('bitrix:sync-spa', [
            'entity' => 'unit-stays',
            '--sync-stages' => $this->option('sync-stages'),
        ]);
    }
}
