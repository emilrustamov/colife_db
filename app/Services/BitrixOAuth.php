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

        foreach (['b24_hk', 'b24_tr'] as $portalKey) {
            $portalDomain = $this->normalizeDomain((string) config("services.{$portalKey}.portal_domain"));
            if ($portalDomain === '' || $normalized === '' || strcasecmp($normalized, $portalDomain) !== 0) {
                continue;
            }

            $clientId = (string) config("services.{$portalKey}.client_id");
            $clientSecret = (string) config("services.{$portalKey}.client_secret");
            if ($clientId === '' || $clientSecret === '') {
                continue;
            }

            $redirect = (string) config("services.{$portalKey}.redirect_uri", '');
            if ($redirect === '') {
                $redirect = (string) config('services.bitrix.redirect_uri');
            }

            return [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirect,
            ];
        }

        return [
            'client_id' => (string) config('services.bitrix.client_id'),
            'client_secret' => (string) config('services.bitrix.client_secret'),
            'redirect_uri' => (string) config('services.bitrix.redirect_uri'),
        ];
    }

    /**
     * Check whether domain is a configured portal (AE, HK or TR).
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
            (string) config('services.b24_tr.portal_domain', ''),
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

    /**
     * Map domain to short portal code (AE / HK / TR).
     */
    public function portalCode(?string $domain): string
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return '?';
        }

        $map = [
            'AE' => $this->normalizeDomain((string) config('services.bitrix.portal_domain', '')),
            'HK' => $this->normalizeDomain((string) config('services.b24_hk.portal_domain', '')),
            'TR' => $this->normalizeDomain((string) config('services.b24_tr.portal_domain', '')),
        ];

        foreach ($map as $code => $portalDomain) {
            if ($portalDomain !== '' && strcasecmp($portalDomain, $normalized) === 0) {
                return $code;
            }
        }

        return '?';
    }

    /**
     * HTTPS URL for a portal domain.
     */
    public function portalUrl(?string $domain): string
    {
        $normalized = $this->normalizeDomain($domain);
        if ($normalized === '') {
            return '';
        }

        return 'https://'.$normalized;
    }

    /**
     * Configured portals: code => domain.
     *
     * @return array<string, string>
     */
    public function configuredPortals(): array
    {
        $out = [];
        foreach ([
            'AE' => (string) config('services.bitrix.portal_domain', ''),
            'HK' => (string) config('services.b24_hk.portal_domain', ''),
            'TR' => (string) config('services.b24_tr.portal_domain', ''),
        ] as $code => $domain) {
            $domain = $this->normalizeDomain($domain);
            if ($domain !== '') {
                $out[$code] = $domain;
            }
        }

        return $out;
    }
}
