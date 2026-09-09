<?php

namespace App\Console\Commands;

use App\Jobs\CollectRegruDomains;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RegruCollectCmd extends Command
{
    protected $signature = 'regru:collect';

    protected $description = 'Collect REG.RU domains and alert Bitrix when expiration is near';

    /**
     * Dispatch synchronous collection of REG.RU domains.
     */
    public function handle(): int
    {
        $domains = CollectRegruDomains::dispatchSync();

        if ($domains === null) {
            $this->warn('REG.RU API credentials are not configured.');

            return self::SUCCESS;
        }

        $this->info('REG.RU domains collected.');

        foreach ($domains as $domain) {
            $expire = is_string($domain['expire']) && $domain['expire'] !== ''
                ? ' until '.Carbon::parse($domain['expire'])->format('d.m.Y')
                : '';
            $this->line('Domain: '.$domain['fqdn'].$expire);
        }

        return self::SUCCESS;
    }
}
