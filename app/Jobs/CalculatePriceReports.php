<?php

namespace App\Jobs;

use App\Services\Prices\MonthlyReportCalculator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CalculatePriceReports implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function handle(MonthlyReportCalculator $calculator): void
    {
        try {
            $stats = $calculator->rebuild();
            Log::info('prices.calculate.done', $stats);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already running')) {
                Log::info('prices.calculate.skipped', ['reason' => $e->getMessage()]);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Cache::lock(MonthlyReportCalculator::LOCK_KEY)->forceRelease();
        Log::error('prices.calculate.failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
