<?php

namespace App\Jobs;

use App\Services\WikibotAlert;
use App\Services\WikibotApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectWikibotBilling
{
    use Dispatchable;

    /**
     * Collect Wikibot billing and notify Bitrix when remaining credits are low.
     *
     * @return array{
     *     plan: string,
     *     is_paid: bool,
     *     expires_at: string|null,
     *     credits: float,
     *     can_query: bool,
     *     total_credits: float,
     *     money_balance: float|null,
     *     balance_usable_until: string|null
     * }|null
     */
    public function handle(
        WikibotApi $wikibotApi,
        WikibotAlert $alertService
    ): ?array {
        $apiKey = trim((string) config('services.wikibot.api_key'));

        if ($apiKey === '') {
            Log::warning('Wikibot collect skipped: API key is not configured.');

            return null;
        }

        try {
            $billing = $wikibotApi->getBilling();

            if ($billing['money_balance'] === null) {
                Log::warning('Wikibot billing response has no money field; enable API key scope billing.');
            }

            $alertService->notifyIfLow($billing);

            return $billing;
        } catch (\Throwable $e) {
            Log::error('Wikibot collect failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
