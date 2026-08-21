<?php

namespace App\Services;

class BitrixOAuth
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

    /**
     * Check whether domain is a configured portal (AE or HK).
     */
    public function isAllowedPortal(?string $domain): bool
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return false;
        }

        foreach ([
            (string) config('services.bitrix.portal_domain', ''),
            (string) config('services.b24_hk.portal_domain', ''),
        ] as $allowed) {
            $allowed = $this->normalizeDomain($allowed);
            if ($allowed !== '' && strcasecmp($allowed, $normalized) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize portal domain to host without scheme or trailing slash.
     */
    public function normalizeDomain(?string $domain): string
    {
        $domain = $domain ?? '';
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        return rtrim(trim($domain), '/');
    }
}
