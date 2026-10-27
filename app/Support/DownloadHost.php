<?php

namespace App\Support;

class DownloadHost
{
    /**
     * Whether a download URL host is an allowed Bitrix portal / *.bitrix24.* host.
     */
    public static function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach (self::configuredHosts() as $allowed) {
            if ($allowed !== '' && strcasecmp($host, $allowed) === 0) {
                return true;
            }
        }

        return (bool) preg_match('/(^|\.)bitrix24\.[a-z0-9.-]+$/i', $host);
    }

    /**
     * @return list<string>
     */
    private static function configuredHosts(): array
    {
        $hosts = [];

        foreach ([
            (string) config('services.bitrix.portal_domain', ''),
            (string) config('services.b24_hk.portal_domain', ''),
            (string) config('services.b24_tr.portal_domain', ''),
            (string) config('alma_bridge.projects.Dubai.domain', ''),
            (string) config('alma_bridge.projects.HongKong.domain', ''),
        ] as $domain) {
            $domain = (string) preg_replace('#^https?://#i', '', $domain);
            $domain = strtolower(rtrim(trim($domain), '/'));
            if ($domain !== '') {
                $hosts[] = $domain;
            }
        }

        foreach (config('bitrix_entities.portals', []) as $portal) {
            if (! is_array($portal)) {
                continue;
            }
            $domain = (string) preg_replace('#^https?://#i', '', (string) ($portal['domain'] ?? ''));
            $domain = strtolower(rtrim(trim($domain), '/'));
            if ($domain !== '') {
                $hosts[] = $domain;
            }
        }

        return array_values(array_unique($hosts));
    }
}
