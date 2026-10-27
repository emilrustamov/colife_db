<?php

namespace App\Services;

class TwilioAlert
{
    public function __construct(
        private readonly DailyAlert $dailyAlert,
    ) {}

    /**
     * Notify Bitrix channel when Twilio balance is at or below threshold (once per day).
     *
     * @param  array{balance: float, currency: string, account_sid: string}  $balanceData
     */
    public function notifyIfLow(array $balanceData): void
    {
        $threshold = (float) config('services.twilio.alert_threshold', 50);
        $balance = $balanceData['balance'];
        $currency = $balanceData['currency'];

        if ($balance > $threshold) {
            return;
        }

        $amount = number_format($balance, 2, '.', '');
        $balanceLabel = strtoupper($currency) === 'USD'
            ? '$'.$amount
            : $amount.' '.$currency;

        $portal = (string) config('services.twilio.portal', 'AE');
        $this->dailyAlert->notifyOnce(
            'twilio:low_balance_alert:'.$this->dailyAlert->todayKey(),
            'twilio:'.strtoupper($portal),
            [
                'portal' => $portal,
                'service' => 'Twilio',
                'date' => $this->dailyAlert->todayNoticeDate(),
                'balance' => '[B]'.$balanceLabel.'[/B]',
            ],
            'Twilio low balance Bitrix alert failed',
            ['balance' => $balance, 'currency' => $currency],
        );
    }
}
