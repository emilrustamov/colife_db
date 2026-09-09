<?php

namespace App\Jobs;

use App\Services\VdsinaAlert;
use App\Services\VdsinaApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectVdsinaBalance
{
    use Dispatchable;

    /**
     * Collect VDSina account balance and notify Bitrix when remaining is low.
     *
     * @return array{real: float, bonus: float, partner: float, forecast: string|null}|null
     */
    public function handle(
        VdsinaApi $vdsinaApi,
        VdsinaAlert $alertService
    ): ?array {
        $token = trim((string) config('services.vdsina.api_token'));

        if ($token === '') {
            Log::warning('VDSina collect skipped: API token is not configured.');

            return null;
        }

        try {
            $balanceData = $vdsinaApi->getBalance();
            $alertService->notifyIfLow($balanceData);

            return $balanceData;
        } catch (\Throwable $e) {
            Log::error('VDSina collect failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
