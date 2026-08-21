<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwilioApi
{
    /**
     * Fetch the current Twilio account balance.
     *
     * @return array{balance: float, currency: string, account_sid: string}
     */
    public function getBalance(): array
    {
        $accountSid = trim((string) config('services.twilio.account_sid'));
        $authToken = trim((string) config('services.twilio.auth_token'));

        if ($accountSid === '' || $authToken === '') {
            throw new RuntimeException('Twilio account SID or auth token is not configured.');
        }

        $response = Http::baseUrl('https://api.twilio.com')
            ->withBasicAuth($accountSid, $authToken)
            ->acceptJson()
            ->get("/2010-04-01/Accounts/{$accountSid}/Balance.json")
            ->throw()
            ->json();

        if (! is_array($response) || ! array_key_exists('balance', $response)) {
            throw new RuntimeException('Twilio balance response is invalid.');
        }

        return [
            'balance' => (float) $response['balance'],
            'currency' => (string) ($response['currency'] ?? 'USD'),
            'account_sid' => (string) ($response['account_sid'] ?? $accountSid),
        ];
    }
}
