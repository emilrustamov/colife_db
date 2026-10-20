<?php

namespace App\Console\Commands;

use App\Jobs\CollectAlmaContacts;
use Illuminate\Console\Command;

class AlmaCollectCmd extends Command
{
    protected $signature = 'alma:collect';

    protected $description = 'Sync Alma contacts into local cache';

    /**
     * Dispatch synchronous collection of Alma contacts.
     */
    public function handle(): int
    {
        CollectAlmaContacts::dispatchSync();

        $this->info('Alma contacts collected.');

        return self::SUCCESS;
    }
}
