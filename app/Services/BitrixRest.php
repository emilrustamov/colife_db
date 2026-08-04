<?php

namespace App\Services;

use App\Models\BitrixToken;
use Illuminate\Http\Client\Response;
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

        if (! $token || ! $token->access_token) {
            throw new RuntimeException('Bitrix OAuth token not configured');
        }

        if ($token->expires_at !== null && $token->expires_at->lt(now())) {
            $token = $this->refreshToken($token);
        }

        return $token->access_token ?? throw new RuntimeException('No Bitrix token');
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
        $refreshToken = (string) ($token->refresh_token ?: $token->refresh_id);
        if ($refreshToken === '') {
            throw new RuntimeException('Bitrix refresh token is not configured.');
        }

        $response = $this->authService->refresh($refreshToken, $token->domain);
        $accessToken = (string) ($response['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Bitrix OAuth refresh failed.');
        }

        $expiresIn = (int) ($response['expires_in'] ?? 0);
        $newRefreshToken = (string) ($response['refresh_token'] ?? $refreshToken);

        $token->forceFill([
            'access_token' => $accessToken,
            'auth_id' => $accessToken,
            'refresh_token' => $newRefreshToken,
            'refresh_id' => $newRefreshToken,
            'expires_in' => $expiresIn > 0 ? $expiresIn : null,
            'expires_at' => $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
        ])->save();

        return $token->fresh() ?? $token;
    }
}
