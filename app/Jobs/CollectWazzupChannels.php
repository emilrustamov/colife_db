<?php

namespace App\Jobs;

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
        WazzupAlert $alertService
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

            throw $e;
        }
    }
}
