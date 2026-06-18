<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatAppApiService
{
    private const string ACCESS_TOKEN_CACHE_KEY = 'chatapp:access_token';

    private const string REFRESH_TOKEN_CACHE_KEY = 'chatapp:refresh_token';

    private const int TOKEN_CACHE_TTL_SECONDS = 86400;

    /**
     * @return array<string, mixed>
     */
    public function authenticate(): array
    {
        $response = $this->http()
            ->post('/v1/tokens', [
                'email' => config('services.chatapp.email'),
                'password' => config('services.chatapp.password'),
                'appId' => config('services.chatapp.app_id'),
            ])
            ->throw()
            ->json();

        $this->storeTokens($response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(): array
    {
        $refreshToken = Cache::get(self::REFRESH_TOKEN_CACHE_KEY);
        if (! is_string($refreshToken) || $refreshToken === '') {
            return $this->authenticate();
        }

        $response = $this->http()
            ->withHeaders(['Refresh' => $refreshToken])
            ->post('/v1/tokens/refresh')
            ->throw()
            ->json();

        $this->storeTokens($response);

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLicenses(): array
    {
        return $this->requestLicenses($this->accessToken());
    }

    /**
     * @return array{total_limit: int, used: int, remaining: int}|null
     */
    public function getBalanceConversations(int|string $licenseId, string $messengerType): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Authorization' => $this->accessToken()])
                ->get("/v1/licenses/{$licenseId}/messengers/{$messengerType}/balanceConversations")
                ->throw()
                ->json();
        } catch (RequestException) {
            return null;
        }

        if (! ($response['success'] ?? false) || ! is_array($response['data'] ?? null)) {
            return null;
        }

        $data = $response['data'];

        return [
            'total_limit' => (int) ($data['totalPurchased'] ?? 0),
            'used' => (int) ($data['totalSpent'] ?? 0),
            'remaining' => (int) ($data['balance'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requestLicenses(string $accessToken): array
    {
        try {
            return $this->licensesResponse($accessToken);
        } catch (RequestException $exception) {
            if (! $exception->response || $exception->response->status() !== 403) {
                throw $exception;
            }

            $this->refreshToken();

            return $this->licensesResponse($this->accessToken());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function licensesResponse(string $accessToken): array
    {
        $response = $this->http()
            ->withHeaders(['Authorization' => $accessToken])
            ->get('/v1/licenses')
            ->throw()
            ->json();

        if (! ($response['success'] ?? false)) {
            throw new RuntimeException('ChatApp licenses request failed.');
        }

        $data = $response['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function accessToken(): string
    {
        $accessToken = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($accessToken) && $accessToken !== '') {
            return $accessToken;
        }

        $this->authenticate();

        $accessToken = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('ChatApp access token is missing after authentication.');
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeTokens(array $response): void
    {
        $data = $response['data'] ?? null;
        if (! is_array($data)) {
            throw new RuntimeException('ChatApp token response is invalid.');
        }

        $accessToken = $data['accessToken'] ?? null;
        $refreshToken = $data['refreshToken'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($refreshToken) || $refreshToken === '') {
            throw new RuntimeException('ChatApp token response is missing tokens.');
        }

        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $accessToken, self::TOKEN_CACHE_TTL_SECONDS);
        Cache::put(self::REFRESH_TOKEN_CACHE_KEY, $refreshToken, self::TOKEN_CACHE_TTL_SECONDS);
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.chatapp.api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Lang' => 'ru']);
    }
}
