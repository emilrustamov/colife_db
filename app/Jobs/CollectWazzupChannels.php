<?php

namespace App\Jobs;

use App\Services\CollectFailureAlert;
use App\Services\WazzupAlert;
use App\Services\WazzupApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectWazzupChannels
{
    use Dispatchable;

    /**
     * Collect Wazzup channels and notify Bitrix when a line needs payment.
     */
    public function handle(
        WazzupApi $wazzupApi,
        WazzupAlert $alertService,
        CollectFailureAlert $collectFailureAlert,
    ): void {
        $apiKey = trim((string) config('services.wazzup.api_key'));
        if ($apiKey === '') {
            Log::warning('Wazzup collect skipped: API key is not configured.');

            return;
        }

        try {
            $channels = $wazzupApi->getChannels();
            foreach ($channels as $channel) {
                $alertService->notifyIfUnpaid($channel);
            }

            Log::info('WAZZUP_COLLECT_OK', [
                'channels' => count($channels),
            ]);
        } catch (\Throwable $e) {
            Log::error('Wazzup collect failed', [
                'error' => $e->getMessage(),
            ]);
            $collectFailureAlert->notify(
                'wazzup_collect_failed',
                '📡',
                'Wazzup collect упал',
                (string) config('services.wazzup.portal', 'HK'),
                'проверь WAZZUP_API_KEY',
                $e,
            );

            throw $e;
        }
    }
}
