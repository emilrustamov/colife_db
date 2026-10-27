<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class VdsinaAlert
{
    public function __construct(
        private readonly DailyAlert $dailyAlert,
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

        $paymentDate = is_string($balanceData['forecast']) && $balanceData['forecast'] !== ''
            ? Carbon::parse($balanceData['forecast'], 'Europe/Moscow')->format('d.m.Y')
            : $this->dailyAlert->todayNoticeDate();

        $portal = (string) config('services.vdsina.portal', 'AE');
        $this->dailyAlert->notifyOnce(
            'vdsina:low_balance_alert:'.$this->dailyAlert->todayKey(),
            'vdsina:'.strtoupper($portal),
            [
                'portal' => $portal,
                'service' => 'VDSina',
                'url' => (string) config('services.vdsina.cabinet_url'),
                'pay_method' => (string) config('services.vdsina.pay_method', 'Карта'),
                'date' => $paymentDate,
                'balance' => '[B]$'.number_format($balance, 2, '.', '').'[/B]',
            ],
            'VDSina low balance Bitrix alert failed',
            ['balance' => $balance, 'forecast' => $balanceData['forecast']],
        );
    }
}
