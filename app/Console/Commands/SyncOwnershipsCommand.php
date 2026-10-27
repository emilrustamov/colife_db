<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncOwnershipsCommand extends Command
{
    protected $signature = 'bitrix:sync-apartment-ownerships {--sync-stages=1}';

    protected $description = 'Sync landlord apartment ownerships (SPA 148) from Bitrix24';

    /**
     * Alias for bitrix:sync-spa apartment-ownerships.
     */
    public function handle(): int
    {
        return $this->call('bitrix:sync-spa', [
            'entity' => 'apartment-ownerships',
            '--sync-stages' => $this->option('sync-stages'),
        ]);
    }
}
