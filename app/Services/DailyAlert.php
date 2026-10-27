<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel,
        private readonly AlertTask $alertTask,
    ) {}

    /**
     * Create/reuse alert task, send IM notice, cache until end of Moscow day.
     *
     * @param  array{portal: string, service: string, url?: string, line?: string|null, line_url?: string, pay_method?: string, date: string, balance?: string}  $notice
     */
    public function notifyOnce(string $cacheKey, string $dedupeKey, array $notice, string $logLabel, array $logContext = []): void
    {
        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $taskId = $this->alertTask->ensureOpen($notice, $dedupeKey);
            $notice['task_url'] = $this->alertTask->taskUrl($taskId);
            $this->bitrixImChannel->notice($notice);
            Cache::put($cacheKey, true, Carbon::today('Europe/Moscow')->endOfDay());
        } catch (Throwable $e) {
            Log::error($logLabel, array_merge($logContext, ['error' => $e->getMessage()]));

            throw $e;
        }
    }

    /**
     * Moscow calendar date used in daily alert cache keys.
     */
    public function todayKey(): string
    {
        return Carbon::today('Europe/Moscow')->format('Y-m-d');
    }

    /**
     * Moscow date formatted for Bitrix notice payloads.
     */
    public function todayNoticeDate(): string
    {
        return Carbon::today('Europe/Moscow')->format('d.m.Y');
    }
}
