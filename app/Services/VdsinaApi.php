<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class VdsinaApi
{
    /**
     * Fetch the current VDSina account balances and shutdown forecast.
     *
     * @return array{real: float, bonus: float, partner: float, forecast: string|null}
     */
    public function getBalance(): array
    {
        $balance = $this->get('account.balance');
        $account = $this->get('account');

        $forecast = $account['forecast'] ?? null;

        return [
            'real' => $this->toFloat($balance['real'] ?? 0),
            'bonus' => $this->toFloat($balance['bonus'] ?? 0),
            'partner' => $this->toFloat($balance['partner'] ?? 0),
            'forecast' => is_string($forecast) && $forecast !== '' ? $forecast : null,
        ];
    }

    /**
     * Perform a GET request against the VDSina public API.
     *
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $token = trim((string) config('services.vdsina.api_token'));
        $baseUrl = rtrim((string) config('services.vdsina.api_url'), '/');

        if ($token === '') {
            throw new RuntimeException('VDSina API token is not configured.');
        }

        $response = Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->get($path)
            ->throw()
            ->json();

        if (! is_array($response) || ($response['status'] ?? '') !== 'ok') {
            $message = is_array($response)
                ? (string) ($response['status_msg'] ?? $response['description'] ?? 'unknown error')
                : 'invalid response';

            throw new RuntimeException('VDSina '.$path.' request failed: '.$message);
        }

        $data = $response['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Cast an API numeric value to float.
     */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
