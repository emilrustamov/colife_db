<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

class CacheStoreHealth
{
    public function __construct(
        private readonly TelegramAlert $alerts,
        private readonly TelegramAlertText $text,
    ) {}

    /**
     * Production must use Redis for Cache::lock (Alma Bridge / Prices / OAuth).
     * File cache races under php-fpm and breaks with root-owned dirs.
     *
     * @return list<string>
     */
    public function check(): array
    {
        $store = (string) config('cache.default', 'file');
        if ($store === 'file' && app()->environment('production')) {
            $message = $this->text->message(
                '🗄️',
                'CACHE_STORE=file запрещён',
                null,
                'Ставь CACHE_STORE=redis. File-cache ломает Alma Bridge locks (fopen / Permission denied).',
            );
            $this->alerts->notifyOnce('cache_store_file_forbidden', $message);

            return [$message];
        }

        if ($store !== 'redis') {
            return [];
        }

        try {
            $key = 'health:cache-store:'.getmypid();
            Cache::put($key, 'ok', 30);
            if (Cache::get($key) !== 'ok') {
                throw new \RuntimeException('redis cache get mismatch');
            }
            Cache::forget($key);

            $lock = Cache::lock('health:cache-lock', 5);
            if (! $lock->get()) {
                throw new \RuntimeException('redis lock not acquired');
            }
            $lock->release();
        } catch (Throwable $e) {
            $message = $this->text->message(
                '🗄️',
                'Redis cache недоступен',
                null,
                substr($e->getMessage(), 0, 200),
            );
            $this->alerts->notifyOnce('cache_store_redis_down', $message);

            return [$message];
        }

        return [];
    }
}
