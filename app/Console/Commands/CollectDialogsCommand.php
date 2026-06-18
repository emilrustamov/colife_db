<?php

namespace App\Console\Commands;

use App\Jobs\CollectDialogsJob;
use Illuminate\Console\Command;

class CollectDialogsCommand extends Command
{
    protected $signature = 'chatapp:collect';

    protected $description = 'Collect dialogs balances from ChatApp API';

    /**
     * Dispatch synchronous collection of ChatApp dialog balances.
     */
    public function handle(): int
    {
        CollectDialogsJob::dispatchSync();

        $this->info('ChatApp dialog balances collected.');

        return self::SUCCESS;
    }
}
