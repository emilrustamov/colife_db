<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WikibotApi
{
    /**
     * Fetch Wikibot billing: plan credits and optional account money balance.
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
     * }
     */
    public function getBilling(): array
    {
        $apiKey = trim((string) config('services.wikibot.api_key'));
        $baseUrl = rtrim((string) config('services.wikibot.api_url', 'https://api.wikibot.pro/api'), '/');

        if ($apiKey === '') {
            throw new RuntimeException('Wikibot API key is not configured.');
        }

        $response = Http::baseUrl($baseUrl)
            ->withHeaders(['Authorization' => $apiKey])
            ->acceptJson()
            ->get('/bot/billing')
            ->throw()
            ->json();

        if (! is_array($response) || ! array_key_exists('credits', $response)) {
            throw new RuntimeException('Wikibot billing response is invalid.');
        }

        $credits = $this->toFloat($response['credits'] ?? 0);
        $money = $response['money'] ?? null;
        $moneyBalance = null;
        $totalCredits = $credits;
        $balanceUsableUntil = null;

        if (is_array($money)) {
            $moneyBalance = $this->toFloat($money['balance'] ?? 0);
            if (array_key_exists('totalCredits', $money)) {
                $totalCredits = $this->toFloat($money['totalCredits']);
            }
            $until = $money['balanceUsableUntil'] ?? null;
            $balanceUsableUntil = is_string($until) && $until !== '' ? $until : null;
        }

        $expiresAt = $response['expiresAt'] ?? null;

        return [
            'plan' => (string) ($response['plan'] ?? ''),
            'is_paid' => (bool) ($response['isPaid'] ?? false),
            'expires_at' => is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : null,
            'credits' => $credits,
            'can_query' => (bool) ($response['canQuery'] ?? false),
            'total_credits' => $totalCredits,
            'money_balance' => $moneyBalance,
            'balance_usable_until' => $balanceUsableUntil,
        ];
    }

    /**
     * Cast an API numeric value to float.
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
