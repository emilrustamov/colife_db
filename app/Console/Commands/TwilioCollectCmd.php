<?php

namespace App\Console\Commands;

use App\Jobs\CollectTwilioBalance;
use Illuminate\Console\Command;

class TwilioCollectCmd extends Command
{
    protected $signature = 'twilio:collect';

    protected $description = 'Collect Twilio account balance and alert Bitrix when low';

    /**
     * Dispatch synchronous collection of Twilio account balance.
     */
    public function handle(): int
    {
        CollectTwilioBalance::dispatchSync();

        $this->info('Twilio account balance collected.');

        return self::SUCCESS;
    }
}
