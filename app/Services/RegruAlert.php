<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RegruAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel
    ) {}

    /**
     * Notify Bitrix channel when a REG.RU domain expires within the alert window (once per domain per day).
     *
     * @param  array{fqdn: string, expire: string|null}  $domain
     */
    public function notifyIfDue(array $domain): void
    {
        if (! is_string($domain['expire']) || $domain['expire'] === '') {
            return;
        }

        $expire = Carbon::parse($domain['expire'], 'Europe/Moscow')->startOfDay();
        $threshold = (int) config('services.regru.alert_days', 30);
        $limit = Carbon::today('Europe/Moscow')->addDays($threshold);

        if ($expire->gt($limit)) {
            return;
        }

        $today = Carbon::today('Europe/Moscow')->format('Y-m-d');
        $cacheKey = 'regru:domain_alert:'.$domain['fqdn'].':'.$today;

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $this->bitrixImChannel->notice([
                'portal' => (string) config('services.regru.portal', 'AE'),
                'service' => $domain['fqdn'],
                'url' => (string) config('services.regru.cabinet_url'),
                'date' => $expire->format('d.m.Y'),
            ]);
            Cache::put($cacheKey, true, Carbon::today('Europe/Moscow')->endOfDay());
        } catch (\Throwable $e) {
            Log::error('REG.RU domain Bitrix alert failed', [
                'fqdn' => $domain['fqdn'],
                'expire' => $domain['expire'],
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
