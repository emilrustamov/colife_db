<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlmaApi
{
    /**
     * Fetch all Alma clients.
     *
     * @return list<array<string, mixed>>
     */
    public function listClients(): array
    {
        $response = $this->client()
            ->get('users/clients/')
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Alma clients list response is invalid.');
        }

        return array_values(array_filter($response, 'is_array'));
    }

    /**
     * Patch Alma client fields by Alma internal id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateClient(int $almaId, array $payload): array
    {
        $response = $this->client()
            ->patch('users/clients/'.$almaId.'/', $payload)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Alma client update response is invalid.');
        }

        return $response;
    }

    /**
     * Build configured HTTP client for Alma External API.
     */
    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $apiUrl = rtrim(trim((string) config('services.alma.api_url')), '/').'/';
        $apiKey = trim((string) config('services.alma.api_key'));

        if ($apiUrl === '/' || $apiKey === '') {
            throw new RuntimeException('Alma API URL or API key is not configured.');
        }

        return Http::baseUrl($apiUrl)
            ->withHeaders(['Api-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.alma.timeout', 120));
    }
}
