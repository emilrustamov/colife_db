<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChatAppApi
{
    private const int TOKEN_CACHE_TTL_SECONDS = 86400;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLicenses(string $account): array
    {
        return $this->requestLicenses($account, $this->accessToken($account));
    }

    /**
     * @return array{total_limit: int, used: int, remaining: int}|null
     */
    public function getBalanceConversations(string $account, int|string $licenseId, string $messengerType): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Authorization' => $this->accessToken($account)])
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
     * @return array<string, mixed>
     */
    public function authenticate(string $account): array
    {
        $credentials = $this->credentials($account);

        $response = $this->http()
            ->post('/v1/tokens', [
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'appId' => $credentials['app_id'],
            ])
            ->throw()
            ->json();

        $this->storeTokens($account, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshToken(string $account): array
    {
        $refreshToken = Cache::get($this->refreshTokenCacheKey($account));
        if (! is_string($refreshToken) || $refreshToken === '') {
            return $this->authenticate($account);
        }

        $response = $this->http()
            ->withHeaders(['Refresh' => $refreshToken])
            ->post('/v1/tokens/refresh')
            ->throw()
            ->json();

        $this->storeTokens($account, $response);

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requestLicenses(string $account, string $accessToken): array
    {
        try {
            return $this->licensesResponse($accessToken);
        } catch (RequestException $exception) {
            if (! $exception->response || $exception->response->status() !== 403) {
                throw $exception;
            }

            $this->refreshToken($account);

            return $this->licensesResponse($this->accessToken($account));
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

    private function accessToken(string $account): string
    {
        $accessToken = Cache::get($this->accessTokenCacheKey($account));
        if (is_string($accessToken) && $accessToken !== '') {
            return $accessToken;
        }

        $this->authenticate($account);

        $accessToken = Cache::get($this->accessTokenCacheKey($account));
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('ChatApp access token is missing after authentication.');
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeTokens(string $account, array $response): void
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

        Cache::put($this->accessTokenCacheKey($account), $accessToken, self::TOKEN_CACHE_TTL_SECONDS);
        Cache::put($this->refreshTokenCacheKey($account), $refreshToken, self::TOKEN_CACHE_TTL_SECONDS);
    }

    /**
     * @return array{email: string, password: string, app_id: string}
     */
    private function credentials(string $account): array
    {
        $config = config('services.chatapp.accounts.'.$account);
        if (! is_array($config)) {
            throw new RuntimeException("ChatApp account [{$account}] is not configured.");
        }

        $email = (string) ($config['email'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $appId = (string) ($config['app_id'] ?? '');

        if ($email === '' || $password === '' || $appId === '') {
            throw new RuntimeException("ChatApp account [{$account}] credentials are incomplete.");
        }

        return [
            'email' => $email,
            'password' => $password,
            'app_id' => $appId,
        ];
    }

    private function accessTokenCacheKey(string $account): string
    {
        return 'chatapp:'.$account.':access_token';
    }

    private function refreshTokenCacheKey(string $account): string
    {
        return 'chatapp:'.$account.':refresh_token';
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.chatapp.api_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Lang' => 'ru']);
    }
}
