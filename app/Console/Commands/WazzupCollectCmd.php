<?php

namespace App\Console\Commands;

use App\Jobs\CollectWazzupChannels;
use Illuminate\Console\Command;

class WazzupCollectCmd extends Command
{
    protected $signature = 'wazzup:collect';

    protected $description = 'Collect Wazzup channels and alert Bitrix when a line needs payment';

    /**
     * Dispatch synchronous collection of Wazzup channels.
     */
    public function handle(): int
    {
        CollectWazzupChannels::dispatchSync();

        $this->info('Wazzup channels collected.');

        return self::SUCCESS;
    }
}
