<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramAlert
{
    public function __construct(
        private readonly TelegramBot $bot,
    ) {}

    /**
     * Send an alert at most once per dedupe key until TTL expires.
     */
    public function notifyOnce(string $dedupeKey, string $text, ?int $ttlSeconds = null): bool
    {
        if (! $this->bot->enabled()) {
            return false;
        }

        $ttl = $ttlSeconds ?? (int) config('services.telegram.dedupe_ttl', 86400);
        if ($ttl <= 0) {
            $ttl = 86400;
        }

        $cacheKey = 'telegram_alert:'.md5($dedupeKey);
        if (Cache::has($cacheKey)) {
            return false;
        }

        $sent = $this->bot->send($text);
        if (! $sent) {
            return false;
        }

        Cache::put($cacheKey, true, $ttl);
        Log::info('TELEGRAM_ALERT_SENT', ['dedupe_key' => $dedupeKey]);

        return true;
    }
}
