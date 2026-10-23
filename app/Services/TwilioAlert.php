<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TwilioAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel,
        private readonly ServiceAlertTask $serviceAlertTask
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

        $amount = number_format($balance, 2, '.', '');
        $balanceLabel = strtoupper($currency) === 'USD'
            ? '$'.$amount
            : $amount.' '.$currency;

        try {
            $notice = [
                'portal' => (string) config('services.twilio.portal', 'AE'),
                'service' => 'Twilio',
                'date' => Carbon::today('Europe/Moscow')->format('d.m.Y'),
                'balance' => '[B]'.$balanceLabel.'[/B]',
            ];
            $taskId = $this->serviceAlertTask->ensureOpen($notice, 'twilio:'.strtoupper((string) $notice['portal']));
            $notice['task_url'] = $this->serviceAlertTask->taskUrl($taskId);
            $this->bitrixImChannel->notice($notice);
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
