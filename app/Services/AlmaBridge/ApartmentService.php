<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use RuntimeException;
use Throwable;

class ApartmentService
{
    public function __construct(
        private readonly AlmaApi $almaApi,
        private readonly UnitService $units,
    ) {}

    /**
     * Upsert Bitrix apartment SPA into Alma unit (+ rooms cascade when rent_type=rooms).
     *
     * @return array<string, mixed>
     */
    public function upsert(int|string $bitrixId, string $projectName, ?int $entityTypeId = null): array
    {
        if (! BridgeSupport::enabled()) {
            BridgeSupport::log()->info('Alma bridge disabled; apartment upsert skipped', [
                'apartment_id' => $bitrixId,
                'project' => $projectName,
            ]);

            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        $bitrix = BitrixWebhookClient::forProject($projectName);
        $typeIds = ProjectMapping::apartmentTypeIds($projectName);
        $entityTypeId = $entityTypeId ?? ($typeIds[0] ?? 144);

        try {
            $res = $bitrix->call('crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $bitrixId,
            ]);
            $apt = $res['result']['item'] ?? null;
            if (! is_array($apt)) {
                throw new RuntimeException('Apartment not found in Bitrix24');
            }

            $stageField = ProjectMapping::getApartmentField('stage', $projectName);
            $stage = (string) (ProjectMapping::itemValue($apt, $stageField) ?? $apt['stageId'] ?? '');
            $archiveStages = ProjectMapping::archiveStages($projectName);
            $allowedStages = ProjectMapping::allowedStages($projectName);

            if ($stage !== '' && in_array($stage, $archiveStages, true)) {
                $this->almaApi->setUnitArchived((string) $bitrixId, true);
                BridgeSupport::log()->info('Apartment archived in Alma', [
                    'apartment_id' => $bitrixId,
                    'stage' => $stage,
                ]);

                return ['method' => 'ARCHIVE', 'external_id' => (string) $bitrixId];
            }

            if ($stage !== '' && $allowedStages !== [] && ! in_array($stage, $allowedStages, true)) {
                BridgeSupport::log()->info('Apartment sync skipped: stage not allowed', [
                    'apartment_id' => $bitrixId,
                    'stage' => $stage,
                ]);

                return ['skipped' => true, 'reason' => 'stage_not_allowed', 'stage' => $stage];
            }

            $buildingName = (string) (ProjectMapping::itemValue(
                $apt,
                ProjectMapping::getApartmentField('building_name', $projectName)
            ) ?? 'Unknown');
            $building = $this->almaApi->getOrCreateBuilding($buildingName);

            $rentType = ProjectMapping::resolveRentType($apt, $projectName);
            $propertyTypeField = ProjectMapping::getApartmentField('property_type', $projectName);
            $propertyRaw = ProjectMapping::itemValue($apt, $propertyTypeField);
            if (is_array($propertyRaw)) {
                $propertyRaw = $propertyRaw[0] ?? null;
            }
            $propertyMap = ProjectMapping::fields($projectName)['property_type_mapping'] ?? [];
            $propertyType = $propertyMap[(string) $propertyRaw] ?? 'apartment';

            $payload = [
                'building_id' => $building['id'] ?? null,
                'name' => (string) ($apt['title'] ?? ''),
                'property_type' => $propertyType,
                'rent_type' => $rentType,
                'apartment_number' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('apartment_number', $projectName)
                ) ?? ''),
                'floor' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('floor', $projectName)
                ) ?? ''),
                'address' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('address', $projectName)
                ) ?? ''),
                'internal_area' => ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('internal_area', $projectName)
                ),
                'goal_rent_cost' => ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('goal_rent_cost', $projectName)
                ),
                'internet_login' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('internet_login', $projectName)
                ) ?? ''),
                'internet_password' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('internet_password', $projectName)
                ) ?? ''),
            ];

            $result = $this->almaApi->upsertUnit((string) $bitrixId, array_filter(
                $payload,
                static fn ($v) => $v !== null && $v !== ''
            ));

            if ($rentType === 'rooms') {
                $this->units->upsertRoomsForApartment((string) $bitrixId, $projectName, $bitrix);
            }

            BridgeSupport::log()->info('Apartment sync succeeded', [
                'apartment_id' => $bitrixId,
                'method' => $result['method'],
                'alma_id' => $result['response']['id'] ?? null,
            ]);

            return $result;
        } catch (Throwable $e) {
            BridgeSupport::log()->error('Webhook: apartment sync failed', [
                'entity_id' => $bitrixId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
