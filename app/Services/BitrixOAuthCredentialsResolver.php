<?php

namespace App\Services;

class BitrixOAuthCredentialsResolver
{
    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}
     */
    public function forPortalDomain(?string $domain): array
    {
        $normalized = $this->normalizeDomain($domain);
        $hkDomain = $this->normalizeDomain((string) config('services.b24_hk.portal_domain'));
        if ($hkDomain !== '' && $normalized !== '' && strcasecmp($normalized, $hkDomain) === 0) {
            $clientId = (string) config('services.b24_hk.client_id');
            $clientSecret = (string) config('services.b24_hk.client_secret');
            if ($clientId !== '' && $clientSecret !== '') {
                $redirect = (string) config('services.b24_hk.redirect_uri', '');
                if ($redirect === '') {
                    $redirect = (string) config('services.bitrix.redirect_uri');
                }

                return [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirect,
                ];
            }
        }

        return [
            'client_id' => (string) config('services.bitrix.client_id'),
            'client_secret' => (string) config('services.bitrix.client_secret'),
            'redirect_uri' => (string) config('services.bitrix.redirect_uri'),
        ];
    }

    private function normalizeDomain(?string $domain): string
    {
        $domain = $domain ?? '';
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        return rtrim(trim($domain), '/');
    }
}
