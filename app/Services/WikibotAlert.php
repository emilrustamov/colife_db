<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class WikibotAlert
{
    public function __construct(
        private readonly DailyAlert $dailyAlert,
    ) {}

    /**
     * Notify Bitrix when plan credits are low and money balance is empty (no autopurchase left).
     *
     * @param  array{
     *     plan: string,
     *     is_paid: bool,
     *     expires_at: string|null,
     *     credits: float,
     *     can_query: bool,
     *     total_credits: float,
     *     money_balance: float|null,
     *     balance_usable_until: string|null
     * }  $billing
     */
    public function notifyIfLow(array $billing): void
    {
        $threshold = (float) config('services.wikibot.alert_threshold', 4000);
        $credits = $billing['credits'];
        $moneyBalance = $billing['money_balance'];

        if ($moneyBalance === null || $moneyBalance > 0 || $credits > $threshold) {
            return;
        }

        $paymentDate = is_string($billing['expires_at']) && $billing['expires_at'] !== ''
            ? Carbon::parse($billing['expires_at'], 'Europe/Moscow')->format('d.m.Y')
            : $this->dailyAlert->todayNoticeDate();

        $portal = (string) config('services.wikibot.portal', 'AE');
        $this->dailyAlert->notifyOnce(
            'wikibot:low_balance_alert:'.$this->dailyAlert->todayKey(),
            'wikibot:'.strtoupper($portal),
            [
                'portal' => $portal,
                'service' => 'Wikibot',
                'url' => (string) config('services.wikibot.cabinet_url'),
                'pay_method' => (string) config('services.wikibot.pay_method', 'Карта'),
                'date' => $paymentDate,
                'balance' => '[B]'.$this->balanceLabel($billing).'[/B]',
            ],
            'Wikibot low balance Bitrix alert failed',
            [
                'credits' => $credits,
                'total_credits' => $billing['total_credits'],
                'money_balance' => $moneyBalance,
                'can_query' => $billing['can_query'],
            ],
        );
    }

    /**
     * Format credits and optional money balance for the IM notice.
     *
     * @param  array{total_credits: float, money_balance: float|null}  $billing
     */
    private function balanceLabel(array $billing): string
    {
        $credits = number_format($billing['total_credits'], 0, '.', ' ').' кр.';
        if ($billing['money_balance'] === null) {
            return $credits;
        }

        return $credits.' / ₽'.number_format($billing['money_balance'], 0, '.', ' ');
    }
}
