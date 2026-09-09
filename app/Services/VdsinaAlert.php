<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VdsinaAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel
    ) {}

    /**
     * Notify Bitrix channel when VDSina real balance is at or below threshold (once per day).
     *
     * @param  array{real: float, bonus: float, partner: float, forecast: string|null}  $balanceData
     */
    public function notifyIfLow(array $balanceData): void
    {
        $threshold = (float) config('services.vdsina.alert_threshold', 50);
        $balance = $balanceData['real'];

        if ($balance > $threshold) {
            return;
        }

        $today = Carbon::today('Europe/Moscow')->format('Y-m-d');
        $cacheKey = 'vdsina:low_balance_alert:'.$today;

        if (Cache::has($cacheKey)) {
            return;
        }

        $paymentDate = is_string($balanceData['forecast']) && $balanceData['forecast'] !== ''
            ? Carbon::parse($balanceData['forecast'], 'Europe/Moscow')->format('d.m.Y')
            : Carbon::today('Europe/Moscow')->format('d.m.Y');

        try {
            $this->bitrixImChannel->notice([
                'portal' => (string) config('services.vdsina.portal', 'AE'),
                'service' => 'VDSina',
                'url' => (string) config('services.vdsina.cabinet_url'),
                'pay_method' => (string) config('services.vdsina.pay_method', 'Карта'),
                'date' => $paymentDate,
                'balance' => '[B]$'.number_format($balance, 2, '.', '').'[/B]',
            ]);
            Cache::put($cacheKey, true, Carbon::today('Europe/Moscow')->endOfDay());
        } catch (\Throwable $e) {
            Log::error('VDSina low balance Bitrix alert failed', [
                'balance' => $balance,
                'forecast' => $balanceData['forecast'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
