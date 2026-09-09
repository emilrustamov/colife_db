<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServicePayAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel
    ) {}

    /**
     * Notify Bitrix channel on the service payment notification date (once per item).
     *
     * @param  array{id: int, title: string, stage_id: string, service_id: int, service: string, portal: string, pay_method: string|null, pay_date: string|null, notify_at: string|null, amount: string|null}  $item
     */
    public function notifyIfDue(array $item): void
    {
        $inactiveStage = (string) config('services.service_payments.inactive_stage', 'DT1094_74:FAIL');
        if ($item['stage_id'] === $inactiveStage) {
            return;
        }

        $notifyAt = $item['notify_at'] ?? $item['pay_date'];
        if (! is_string($notifyAt) || $notifyAt === '') {
            return;
        }

        $today = Carbon::today('Europe/Moscow')->format('Y-m-d');
        if ($notifyAt !== $today) {
            return;
        }

        $cacheKey = 'service_payments:alert:'.$item['id'].':'.$today;

        if (Cache::has($cacheKey)) {
            return;
        }

        $payDate = is_string($item['pay_date']) && $item['pay_date'] !== ''
            ? Carbon::parse($item['pay_date'])->format('d.m.Y')
            : Carbon::today('Europe/Moscow')->format('d.m.Y');

        $urls = config('services.service_payments.cabinet_urls', []);
        $url = $this->cabinetUrl(is_array($urls) ? ($urls[$item['service_id']] ?? '') : '', $item['portal']);

        $payload = [
            'portal' => $item['portal'],
            'service' => $item['service'],
            'url' => $url,
            'date' => $payDate,
        ];

        if (is_string($item['pay_method']) && $item['pay_method'] !== '') {
            $payload['pay_method'] = $item['pay_method'];
        }

        if (is_string($item['amount']) && $item['amount'] !== '') {
            $payload['balance'] = '[B]'.$item['amount'].'[/B]';
        }

        try {
            $this->bitrixImChannel->notice($payload);
            Cache::put($cacheKey, true, Carbon::today('Europe/Moscow')->endOfDay());
        } catch (\Throwable $e) {
            Log::error('Service payment Bitrix alert failed', [
                'id' => $item['id'],
                'service' => $item['service'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve cabinet URL from a string or a portal-keyed map.
     */
    private function cabinetUrl(mixed $url, string $portal): string
    {
        if (is_array($url)) {
            $url = $url[$portal] ?? '';
        }

        return is_string($url) ? $url : '';
    }
}
