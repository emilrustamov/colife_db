<?php

namespace App\Services;

use App\Services\Contracts\EntityProfile;
use App\Services\Profiles\ApartmentProfile;
use App\Services\Profiles\ContactProfile;
use App\Services\Profiles\OwnershipProfile;
use App\Services\Profiles\UnitProfile;
use App\Services\Profiles\UnitStayProfile;

class EntitySync
{
    /**
     * @var array<string, EntityProfile>
     */
    private array $profiles;

    public function __construct(
        ContactProfile $contacts,
        ApartmentProfile $apartments,
        UnitProfile $units,
        UnitStayProfile $unitStays,
        OwnershipProfile $ownerships
    ) {
        $this->profiles = [
            $contacts->entity() => $contacts,
            $apartments->entity() => $apartments,
            $units->entity() => $units,
            $unitStays->entity() => $unitStays,
            $ownerships->entity() => $ownerships,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatch(array $items, string $entity = 'contacts'): array
    {
        return $this->resolve($entity)->syncBatch($items);
    }

    public function syncOne(int $bitrixId, string $entity = 'contacts'): bool
    {
        return $this->resolve($entity)->syncOne($bitrixId);
    }

    public function markDeleted(int $bitrixId, string $entity = 'contacts'): int
    {
        return $this->resolve($entity)->markDeleted($bitrixId);
    }

    private function resolve(string $entity): EntityProfile
    {
        if (isset($this->profiles[$entity])) {
            return $this->profiles[$entity];
        }

        throw new \InvalidArgumentException('Unsupported Bitrix entity: '.$entity);
    }
}
