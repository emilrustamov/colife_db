<?php

namespace App\Console\Commands;

use App\Jobs\CollectDialogs;
use Illuminate\Console\Command;

class CollectCmd extends Command
{
    protected $signature = 'chatapp:collect';

    protected $description = 'Collect dialogs balances from ChatApp API';

    /**
     * Dispatch synchronous collection of ChatApp dialog balances.
     */
    public function handle(): int
    {
        CollectDialogs::dispatchSync();

        $this->info('ChatApp dialog balances collected.');

        return self::SUCCESS;
    }
}
