<?php

namespace App\Console\Commands;

use App\Jobs\CollectServicePay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ServicePayCollectCmd extends Command
{
    protected $signature = 'payments:collect';

    protected $description = 'Collect Bitrix service payments and alert when notification date has arrived';

    /**
     * Dispatch synchronous collection of Bitrix service payment items.
     */
    public function handle(): int
    {
        $items = CollectServicePay::dispatchSync();

        $this->info('Service payments collected.');

        foreach ($items as $item) {
            $payDate = is_string($item['pay_date']) && $item['pay_date'] !== ''
                ? Carbon::parse($item['pay_date'])->format('d.m.Y')
                : '-';
            $amount = is_string($item['amount']) && $item['amount'] !== '' ? ' '.$item['amount'] : '';
            $payMethod = is_string($item['pay_method']) && $item['pay_method'] !== '' ? ' '.$item['pay_method'] : '';
            $this->line($item['portal'].' | '.$item['service'].' | '.$payDate.$amount.$payMethod);
        }

        return self::SUCCESS;
    }
}
