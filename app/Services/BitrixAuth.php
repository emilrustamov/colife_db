<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BitrixAuth
{
    public function __construct(
        private readonly BitrixOAuth $oauthCredentials
    ) {}

    /**
     * Exchange authorization code for OAuth tokens.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, ?string $portalDomain = null): array
    {
        $c = $this->oauthCredentials->forPortalDomain($portalDomain);

        return Http::asForm()->post(
            'https://oauth.bitrix.info/oauth/token/',
            [
                'grant_type' => 'authorization_code',
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'code' => $code,
                'redirect_uri' => $c['redirect_uri'],
            ]
        )->throw()->json();
    }

    /**
     * Refresh Bitrix OAuth tokens using refresh token.
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, ?string $portalDomain = null): array
    {
        $c = $this->oauthCredentials->forPortalDomain($portalDomain);

        return Http::asForm()->post(
            'https://oauth.bitrix.info/oauth/token/',
            [
                'grant_type' => 'refresh_token',
                'client_id' => $c['client_id'],
                'client_secret' => $c['client_secret'],
                'refresh_token' => $refreshToken,
            ]
        )->json();
    }
}
