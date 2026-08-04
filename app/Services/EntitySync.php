<?php

namespace App\Services;

use App\Services\Contracts\EntityProfile;
use App\Services\Profiles\OwnershipProfile;
use App\Services\Profiles\ApartmentProfile;
use App\Services\Profiles\ContactProfile;
use App\Services\Profiles\UnitProfile;
use App\Services\Profiles\UnitStayProfile;

class EntitySync
{
    /**
     * @var array<string, EntityProfile>
     */
    private array $profiles;

    public function __construct(
        ContactProfile $contactProfile,
        ApartmentProfile $apartmentProfile,
        UnitProfile $unitProfile,
        UnitStayProfile $unitStayProfile,
        OwnershipProfile $apartmentOwnershipProfile
    ) {
        $this->profiles = [
            $contactProfile->entity() => $contactProfile,
            $apartmentProfile->entity() => $apartmentProfile,
            $unitProfile->entity() => $unitProfile,
            $unitStayProfile->entity() => $unitStayProfile,
            $apartmentOwnershipProfile->entity() => $apartmentOwnershipProfile,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatch(array $items, string $entity = 'contacts'): array
    {
        return $this->resolveProfile($entity)->syncBatch($items);
    }

    public function syncOne(int $bitrixId, string $entity = 'contacts'): bool
    {
        return $this->resolveProfile($entity)->syncOne($bitrixId);
    }

    public function markDeleted(int $bitrixId, string $entity = 'contacts'): int
    {
        return $this->resolveProfile($entity)->markDeleted($bitrixId);
    }

    private function resolveProfile(string $entity): EntityProfile
    {
        if (isset($this->profiles[$entity])) {
            return $this->profiles[$entity];
        }

        throw new \InvalidArgumentException('Unsupported Bitrix entity: '.$entity);
    }
}
