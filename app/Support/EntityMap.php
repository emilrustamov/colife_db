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
}
