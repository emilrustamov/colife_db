<?php

namespace App\Services;

use App\Models\DialogBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatAppAlert
{
    public function __construct(
        private readonly BitrixIm $bitrixImChannel,
        private readonly AlertTask $alertTask
    ) {}

    /**
     * Notify Bitrix channel when dialog balance is below threshold.
     */
    public function notifyIfLow(DialogBalance $balance, string $account = 'ae'): void
    {
        $line = $this->lineNumber($balance->line_id);
        $threshold = $this->threshold($line);

        if ($balance->remaining >= $threshold) {
            return;
        }

        $date = $balance->collected_at->format('Y-m-d');
        $cacheKey = 'chatapp:low_balance_alert:'.$account.':'.$balance->line_id.':'.$date;
        $dailyLimit = $this->dailyLimit($line);
        $sent = (int) Cache::get($cacheKey, 0);

        if ($sent >= $dailyLimit) {
            return;
        }

        $accountConfig = config('services.chatapp.accounts.'.$account, []);
        $lineUrl = is_array($accountConfig) ? (string) ($accountConfig['cabinet_line_url'] ?? '') : '';

        try {
            $notice = [
                'portal' => strtoupper($account),
                'service' => 'ChatApp',
                'line' => $this->lineLabel($line, $balance->line_name),
                'line_url' => $lineUrl,
                'pay_method' => (string) config('services.chatapp.pay_method', 'Счёт'),
                'date' => Carbon::parse($balance->collected_at)->format('d.m.Y'),
                'balance' => '[B]'.$balance->remaining.'[/B]',
            ];
            if ($sent === 0) {
                $taskId = $this->alertTask->ensureOpen(
                    $notice,
                    'chatapp:'.strtoupper($account).':'.$line
                );
                $notice['task_url'] = $this->alertTask->taskUrl($taskId);
            }
            $this->bitrixImChannel->notice($notice);
            Cache::put($cacheKey, $sent + 1, Carbon::parse($balance->collected_at)->endOfDay());
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
     * Alert remaining-dialogs threshold for a ChatApp line.
     */
    private function threshold(string $line): int
    {
        $overrides = config('services.chatapp.alert_thresholds', []);
        $overrides = is_array($overrides) ? $overrides : [];

        if (array_key_exists($line, $overrides)) {
            return (int) $overrides[$line];
        }

        return (int) config('services.chatapp.alert_threshold', 1000);
    }

    /**
     * Max low-balance notices per day for a ChatApp line.
     */
    private function dailyLimit(string $line): int
    {
        $limits = config('services.chatapp.alert_daily_limits', []);
        $limits = is_array($limits) ? $limits : [];

        if (array_key_exists($line, $limits)) {
            return max(1, (int) $limits[$line]);
        }

        return 1;
    }

    /**
     * Build notice line label with ChatApp license name when available.
     */
    private function lineLabel(string $line, ?string $lineName): string
    {
        $name = trim((string) $lineName);
        if ($name === '') {
            return $line;
        }

        $base = trim(explode(' / ', $name, 2)[0]);

        return $base !== '' ? $line.' — '.$base : $line;
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
