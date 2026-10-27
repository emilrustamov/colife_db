<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncApartmentsCommand extends Command
{
    protected $signature = 'bitrix:sync-apartments {--sync-stages=1}';

    protected $description = 'Sync apartments and apartment stages from Bitrix24';

    /**
     * Alias for bitrix:sync-spa apartments.
     */
    public function handle(): int
    {
        return $this->call('bitrix:sync-spa', [
            'entity' => 'apartments',
            '--sync-stages' => $this->option('sync-stages'),
        ]);
    }
}
