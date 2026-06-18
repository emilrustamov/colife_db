<?php

namespace App\Services;

use App\Models\DialogBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatAppDialogBalanceAlertService
{
    public function __construct(
        private readonly BitrixImChannelService $bitrixImChannel
    ) {}

    /**
     * Notify Bitrix channel when dialog balance is below threshold (once per line per day).
     */
    public function notifyIfLow(DialogBalance $balance): void
    {
        $threshold = (int) config('services.chatapp.alert_threshold', 1000);

        if ($balance->remaining >= $threshold) {
            return;
        }

        $cacheKey = 'chatapp:low_balance_alert:'.$balance->line_id.':'.$balance->collected_at->format('Y-m-d');

        if (Cache::has($cacheKey)) {
            return;
        }

        $lineNumber = explode(':', $balance->line_id, 2)[0];
        $date = Carbon::parse($balance->collected_at)->format('d.m.y');
        $lineUrl = (string) config('services.chatapp.cabinet_line_url');
        $lineLink = '[URL='.$lineUrl.']'.$lineNumber.'[/URL]';

        $remainingBold = '[B]'.$balance->remaining.'[/B]';

        $message = sprintf(
            "%s В ChatApp на линии %s осталось меньше %d диалогов.\nТекущий остаток: %s.\nПроверьте необходимость пополнения.",
            $date,
            $lineLink,
            $threshold,
            $remainingBold
        );

        try {
            $this->bitrixImChannel->send($message);
            Cache::put($cacheKey, true, Carbon::parse($balance->collected_at)->endOfDay());
        } catch (\Throwable $e) {
            Log::error('ChatApp low balance Bitrix alert failed', [
                'line_id' => $balance->line_id,
                'remaining' => $balance->remaining,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
