<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TwilioAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel
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

        $today = Carbon::today('Europe/Moscow')->format('Y-m-d');
        $cacheKey = 'twilio:low_balance_alert:'.$today;

        if (Cache::has($cacheKey)) {
            return;
        }

        $date = Carbon::today('Europe/Moscow')->format('d.m.y');
        $balanceFormatted = number_format($balance, 2, '.', '');
        $thresholdFormatted = number_format($threshold, 0, '.', '');

        $message = sprintf(
            "%s В Twilio баланс %s %s и ниже.\nТекущий баланс: [B]%s[/B] %s.\nНужно пополнить.",
            $date,
            $thresholdFormatted,
            $currency,
            $balanceFormatted,
            $currency
        );

        try {
            $this->bitrixImChannel->send($message);
            Cache::put($cacheKey, true, Carbon::today('Europe/Moscow')->endOfDay());
        } catch (\Throwable $e) {
            Log::error('Twilio low balance Bitrix alert failed', [
                'balance' => $balance,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
