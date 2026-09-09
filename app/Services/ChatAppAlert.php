<?php

namespace App\Services;

use App\Models\DialogBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatAppAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel
    ) {}

    /**
     * Notify Bitrix channel when dialog balance is below threshold (once per line per day).
     */
    public function notifyIfLow(DialogBalance $balance, string $account = 'ae'): void
    {
        $threshold = (int) config('services.chatapp.alert_threshold', 1000);

        if ($balance->remaining >= $threshold) {
            return;
        }

        $cacheKey = 'chatapp:low_balance_alert:'.$account.':'.$balance->line_id.':'.$balance->collected_at->format('Y-m-d');

        if (Cache::has($cacheKey)) {
            return;
        }

        $accountConfig = config('services.chatapp.accounts.'.$account, []);
        $lineUrl = is_array($accountConfig) ? (string) ($accountConfig['cabinet_line_url'] ?? '') : '';

        try {
            $this->bitrixImChannel->notice([
                'portal' => strtoupper($account),
                'service' => 'ChatApp',
                'line' => $this->lineNumber($balance->line_id),
                'line_url' => $lineUrl,
                'pay_method' => (string) config('services.chatapp.pay_method', 'Счёт'),
                'date' => Carbon::parse($balance->collected_at)->format('d.m.Y'),
                'balance' => '[B]'.$balance->remaining.'[/B]',
            ]);
            Cache::put($cacheKey, true, Carbon::parse($balance->collected_at)->endOfDay());
        } catch (\Throwable $e) {
            Log::error('ChatApp low balance Bitrix alert failed', [
                'account' => $account,
                'line_id' => $balance->line_id,
                'remaining' => $balance->remaining,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Extract license/line number from stored line_id (`license:type` or `account:license:type`).
     */
    private function lineNumber(string $lineId): string
    {
        $segments = explode(':', $lineId);

        return count($segments) >= 3 ? $segments[1] : $segments[0];
    }
}
