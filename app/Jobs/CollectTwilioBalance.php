<?php

namespace App\Jobs;

use App\Services\CollectFailureAlert;
use App\Services\TwilioAlert;
use App\Services\TwilioApi;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class CollectTwilioBalance
{
    use Dispatchable;

    /**
     * Collect Twilio account balance and notify Bitrix when remaining is low.
     */
    public function handle(
        TwilioApi $twilioApi,
        TwilioAlert $alertService,
        CollectFailureAlert $collectFailureAlert,
    ): void {
        $accountSid = trim((string) config('services.twilio.account_sid'));
        $authToken = trim((string) config('services.twilio.auth_token'));

        if ($accountSid === '' || $authToken === '') {
            Log::warning('Twilio collect skipped: credentials are not configured.');

            return;
        }

        try {
            $balanceData = $twilioApi->getBalance();
            $alertService->notifyIfLow($balanceData);
        } catch (\Throwable $e) {
            Log::error('Twilio collect failed', [
                'error' => $e->getMessage(),
            ]);
            $collectFailureAlert->notify(
                'twilio_collect_failed',
                '📞',
                'Twilio collect упал',
                (string) config('services.twilio.portal', 'AE'),
                'проверь TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN',
                $e,
            );

            throw $e;
        }
    }
}
