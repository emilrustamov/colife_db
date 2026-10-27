<?php

namespace App\Services\AlmaBridge;

use InvalidArgumentException;

class WebhookDispatcher
{
    public function __construct(
        private readonly ApartmentService $apartments,
        private readonly UnitService $units,
        private readonly TenantContractService $tenantContracts,
        private readonly LandlordContractService $landlordContracts,
        private readonly ContactService $contacts,
    ) {}

    /**
     * Route Bitrix dynamic/contact entity to the matching Alma Bridge service.
     *
     * @return array<string, mixed>
     */
    public function dispatch(int|string $id, ?int $entityTypeId, string $projectName, string $eventName = ''): array
    {
        $contactEvent = (string) config('alma_bridge.events.contact_update');
        if ((int) $entityTypeId === 3 || $eventName === $contactEvent) {
            $result = $this->contacts->updateIfExists($id, $projectName);

            return $result ?? ['skipped' => true, 'reason' => 'client_not_in_alma'];
        }

        $aptIds = ProjectMapping::apartmentTypeIds($projectName);
        if ($entityTypeId !== null && in_array($entityTypeId, $aptIds, true)) {
            return $this->apartments->upsert($id, $projectName, $entityTypeId);
        }

        $unitTypeId = ProjectMapping::unitTypeId($projectName);
        if ($entityTypeId !== null && $entityTypeId === $unitTypeId) {
            return $this->units->upsert($id, $projectName);
        }

        $contractTypeId = ProjectMapping::contractTypeId($projectName);
        if ($entityTypeId !== null && $entityTypeId === $contractTypeId) {
            return $this->tenantContracts->upsert($id, $projectName, [
                'skip_if_integration_update' => true,
            ]);
        }

        $llTypeId = ProjectMapping::landlordTypeId($projectName);
        if ($llTypeId !== null && $entityTypeId !== null && $entityTypeId === $llTypeId) {
            return $this->landlordContracts->upsert($id, $projectName, [
                'skip_if_integration_update' => true,
            ]);
        }

        throw new InvalidArgumentException(
            'Unsupported Alma bridge entityTypeId='.(string) $entityTypeId.' project='.$projectName
        );
    }
}
