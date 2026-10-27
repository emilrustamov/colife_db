<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncUnitsCommand extends Command
{
    protected $signature = 'bitrix:sync-units {--sync-stages=1}';

    protected $description = 'Sync units directory and unit stages from Bitrix24';

    /**
     * Alias for bitrix:sync-spa units.
     */
    public function handle(): int
    {
        return $this->call('bitrix:sync-spa', [
            'entity' => 'units',
            '--sync-stages' => $this->option('sync-stages'),
        ]);
    }
}
