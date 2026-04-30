<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BitrixRestClient
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $method, array $payload): Response
    {
        return Http::timeout((int) config('services.bitrix_contacts.timeout', 60))
            ->acceptJson()
            ->asJson()
            ->post($this->buildUrl($method), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postJson(string $method, array $payload): array
    {
        $response = $this->post($method, $payload);
        $response->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    private function buildUrl(string $method): string
    {
        return rtrim((string) config('services.bitrix.webhook'), '/').'/'.$method;
    }
}
