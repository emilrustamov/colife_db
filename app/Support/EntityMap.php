<?php

namespace App\Support;

class EntityMap
{
    /**
     * Resolve local sync entity name for a portal code + Bitrix entity type id.
     */
    public static function entityName(string $portalCode, int $entityTypeId): ?string
    {
        if ($entityTypeId <= 0) {
            return null;
        }

        $entities = config('bitrix_entities.portals.'.strtolower($portalCode).'.entities', []);
        if (! is_array($entities)) {
            return null;
        }

        foreach ($entities as $entity => $typeId) {
            if ((int) $typeId === $entityTypeId) {
                return (string) $entity;
            }
        }

        return null;
    }

    /**
     * Entity type id for a named entity on a portal.
     */
    public static function typeId(string $portalCode, string $entity): int
    {
        return (int) config(
            'bitrix_entities.portals.'.strtolower($portalCode).'.entities.'.$entity,
            0
        );
    }

    /**
     * Portal code for a Bitrix domain from bitrix_entities map.
     */
    public static function portalByDomain(?string $domain): ?string
    {
        $normalized = self::normalizeDomain($domain);
        if ($normalized === '') {
            return null;
        }

        foreach (config('bitrix_entities.portals', []) as $code => $portal) {
            if (! is_array($portal)) {
                continue;
            }
            $portalDomain = self::normalizeDomain((string) ($portal['domain'] ?? ''));
            if ($portalDomain !== '' && strcasecmp($portalDomain, $normalized) === 0) {
                return strtolower((string) $code);
            }
        }

        return null;
    }

    private static function normalizeDomain(?string $domain): string
    {
        $domain = (string) preg_replace('#^https?://#i', '', (string) ($domain ?? ''));

        return rtrim(trim($domain), '/');
    }
}
