<?php

namespace App\Console\Commands;

use App\Jobs\CollectWikibotBilling;
use Illuminate\Console\Command;

class WikibotCollectCmd extends Command
{
    protected $signature = 'wikibot:collect';

    protected $description = 'Collect Wikibot billing and alert Bitrix when credits are low';

    /**
     * Dispatch synchronous collection of Wikibot billing.
     */
    public function handle(): int
    {
        $billing = CollectWikibotBilling::dispatchSync();

        if ($billing === null) {
            $this->warn('Wikibot API key is not configured.');

            return self::SUCCESS;
        }

        $this->info('Wikibot billing collected.');
        $this->line('Plan: '.($billing['plan'] !== '' ? $billing['plan'] : '—'));
        $this->line('Credits (plan): '.number_format($billing['credits'], 0, '.', ' '));
        $this->line('Total credits: '.number_format($billing['total_credits'], 0, '.', ' '));
        $this->line('Can query: '.($billing['can_query'] ? 'yes' : 'no'));

        if ($billing['money_balance'] !== null) {
            $this->line('Money balance: ₽'.number_format($billing['money_balance'], 0, '.', ' '));
        } else {
            $this->warn('Money balance is missing (API key needs billing scope).');
        }

        if (is_string($billing['expires_at']) && $billing['expires_at'] !== '') {
            $this->line('Expires at: '.$billing['expires_at']);
        }

        return self::SUCCESS;
    }
}
