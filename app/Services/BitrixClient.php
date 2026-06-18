<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BitrixClient
{
    public function __construct(
        protected string $domain,
        protected string $token
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $response = Http::asForm()->post(
            'https://'.$this->domain.'/rest/'.ltrim($method, '/'),
            array_merge($params, ['auth' => $this->token])
        );

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
