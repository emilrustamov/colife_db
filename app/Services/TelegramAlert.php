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
     * Default TTL for rare/daily alerts.
     */
    public function dailyTtl(): int
    {
        $ttl = (int) config('services.telegram.dedupe_ttl', 86400);

        return $ttl > 0 ? $ttl : 86400;
    }

    /**
     * Shorter TTL so a new breakage after a fix still notifies today.
     */
    public function instantTtl(): int
    {
        $ttl = (int) config('services.telegram.instant_dedupe_ttl', 3600);

        return $ttl > 0 ? $ttl : 3600;
    }

    /**
     * Send an alert at most once per dedupe key until TTL expires.
     */
    public function notifyOnce(string $dedupeKey, string $text, ?int $ttlSeconds = null): bool
    {
        if (! $this->bot->enabled()) {
            return false;
        }

        $ttl = $ttlSeconds ?? $this->dailyTtl();
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

        try {
            Log::info('TELEGRAM_ALERT_SENT', ['dedupe_key' => $dedupeKey]);
        } catch (\Throwable) {
        }

        return true;
    }
}
