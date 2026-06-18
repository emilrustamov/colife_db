<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BitrixAuthService
{
    public function __construct(
        private readonly BitrixOAuthCredentialsResolver $oauthCredentials
    ) {}

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
