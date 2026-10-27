<?php

namespace App\Services;

use App\Models\BitrixToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BitrixRest
{
    public function __construct(private readonly BitrixAuth $authService) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $method, array $payload, ?string $domain = null): Response
    {
        $domain = rtrim(trim((string) ($domain ?? config('services.bitrix.portal_domain', ''))), '/');
        if ($domain === '') {
            throw new RuntimeException('Bitrix portal domain is not configured.');
        }

        $token = $this->resolveToken($domain);
        $response = $this->request($domain, $token, $method, $payload);

        if ($this->isExpiredToken($response)) {
            $tokenModel = BitrixToken::query()->where('domain', $domain)->first();
            if (! $tokenModel) {
                throw new RuntimeException('Bitrix OAuth token not configured');
            }

            $this->refreshToken($tokenModel);
            $response = $this->request($domain, $this->resolveToken($domain), $method, $payload);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postJson(string $method, array $payload, ?string $domain = null): array
    {
        $response = $this->post($method, $payload, $domain);
        $response->throw();

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }

    private function resolveToken(string $domain): string
    {
        $token = BitrixToken::query()->where('domain', $domain)->first();

        if (! $token) {
            throw new RuntimeException('Bitrix OAuth token not configured');
        }

        $accessToken = (string) ($token->access_token ?: $token->auth_id);
        if ($accessToken === '') {
            throw new RuntimeException('Bitrix OAuth token not configured');
        }

        if ($token->expires_at !== null && $token->expires_at->lt(now())) {
            $token = $this->refreshToken($token);
            $accessToken = (string) ($token->access_token ?: $token->auth_id);
        }

        if ($accessToken === '') {
            throw new RuntimeException('No Bitrix token');
        }

        return $accessToken;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(string $domain, string $accessToken, string $method, array $payload): Response
    {
        return Http::asForm()->post(
            'https://'.$domain.'/rest/'.ltrim($method, '/'),
            array_merge($payload, ['auth' => $accessToken])
        );
    }

    private function isExpiredToken(Response $response): bool
    {
        $json = $response->json();
        if (! is_array($json)) {
            return false;
        }

        return (string) ($json['error'] ?? '') === 'expired_token';
    }

    private function refreshToken(BitrixToken $token): BitrixToken
    {
        $domain = (string) $token->domain;
        $lock = Cache::lock('bitrix-oauth-refresh:'.$domain, 20);

        return $lock->block(15, function () use ($token, $domain): BitrixToken {
            $fresh = BitrixToken::query()->where('domain', $domain)->first() ?? $token;
            if (
                $fresh->expires_at !== null
                && $fresh->expires_at->gt(now()->addSeconds(30))
                && (string) ($fresh->access_token ?: $fresh->auth_id) !== ''
            ) {
                return $fresh;
            }

            $refreshToken = (string) ($fresh->refresh_token ?: $fresh->refresh_id);
            if ($refreshToken === '') {
                throw new RuntimeException('Bitrix refresh token is not configured.');
            }

            $response = $this->authService->refresh($refreshToken, $fresh->domain);
            $accessToken = (string) ($response['access_token'] ?? '');
            if ($accessToken === '') {
                throw new RuntimeException('Bitrix OAuth refresh failed.');
            }

            $expiresIn = (int) ($response['expires_in'] ?? 0);
            $newRefreshToken = (string) ($response['refresh_token'] ?? $refreshToken);

            $fresh->forceFill([
                'access_token' => $accessToken,
                'auth_id' => $accessToken,
                'refresh_token' => $newRefreshToken,
                'refresh_id' => $newRefreshToken,
                'expires_in' => $expiresIn > 0 ? $expiresIn : null,
                'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            ])->save();

            return $fresh->fresh() ?? $fresh;
        });
    }
}
