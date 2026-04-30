<?php

namespace App\Services;

use App\Services\Contracts\BitrixEntityProfile;
use App\Services\Profiles\BitrixContactProfile;

class BitrixEntitySyncService
{
    /**
     * @var array<string, BitrixEntityProfile>
     */
    private array $profiles;

    public function __construct(BitrixContactProfile $contactProfile)
    {
        $this->profiles = [
            $contactProfile->entity() => $contactProfile,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatchItems(array $items, string $entity = 'contacts'): array
    {
        return $this->resolveProfile($entity)->syncBatchItems($items);
    }

    public function syncSingleItemByBitrixId(int $bitrixId, string $entity = 'contacts'): bool
    {
        return $this->resolveProfile($entity)->syncSingleItemByBitrixId($bitrixId);
    }

    public function markItemDeleted(int $bitrixId, string $entity = 'contacts'): int
    {
        return $this->resolveProfile($entity)->markItemDeleted($bitrixId);
    }

    private function resolveProfile(string $entity): BitrixEntityProfile
    {
        if (isset($this->profiles[$entity])) {
            return $this->profiles[$entity];
        }

        throw new \InvalidArgumentException('Unsupported Bitrix entity: '.$entity);
    }
}
