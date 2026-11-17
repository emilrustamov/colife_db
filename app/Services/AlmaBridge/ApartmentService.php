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
        private readonly TerminationService $terminations,
    ) {}

    /**
     * Upsert Bitrix apartment SPA into Alma unit (+ rooms cascade when rent_type=rooms).
     *
     * @param  array{skip_if_integration_update?: bool}  $options
     * @return array<string, mixed>
     */
    public function upsert(
        int|string $bitrixId,
        string $projectName,
        ?int $entityTypeId = null,
        array $options = []
    ): array {
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
        $map = ProjectMapping::fields($projectName);

        try {
            $res = $bitrix->call('crm.item.get', [
                'entityTypeId' => $entityTypeId,
                'id' => $bitrixId,
            ]);
            $apt = $res['result']['item'] ?? null;
            if (! is_array($apt)) {
                throw new RuntimeException('Apartment not found in Bitrix24');
            }

            if (! empty($options['skip_if_integration_update'])) {
                if (BridgeSupport::isWebhookEcho(
                    $bitrix,
                    $entityTypeId,
                    $bitrixId,
                    $apt,
                    $bitrix->restUserId()
                )) {
                    BridgeSupport::log()->info('Apartment sync skipped: webhook echo from Alma comment', [
                        'apartment_id' => $bitrixId,
                    ]);

                    return ['skipped' => true, 'reason' => 'alma_comment_echo', 'method' => 'SKIP_ALMA_COMMENT_ECHO'];
                }
            }

            $stageField = ProjectMapping::getApartmentField('stage', $projectName);
            $stage = (string) (ProjectMapping::itemValue($apt, $stageField) ?? $apt['stageId'] ?? '');
            $archiveStages = ProjectMapping::archiveStages($projectName);
            $allowedStages = ProjectMapping::allowedStages($projectName);

            if ($stage !== '' && in_array($stage, $archiveStages, true)) {
                $existing = $this->almaApi->unitByExternalId((string) $bitrixId);
                if ($existing !== null && isset($existing['id'])) {
                    $this->almaApi->setUnitArchived((string) $bitrixId, true);
                    $building = $existing['building'] ?? null;
                    $buildingId = is_array($building) ? ($building['id'] ?? null) : $building;
                    if ($buildingId !== null) {
                        $this->almaApi->archiveBuildingIfEmpty($buildingId);
                    }
                }
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

            $buildingName = trim((string) (ProjectMapping::itemValue(
                $apt,
                ProjectMapping::getApartmentField('building_name', $projectName)
            ) ?? ''));
            if ($buildingName === '') {
                $buildingName = 'Default Building';
            }

            $projectId = (int) (ProjectMapping::project($projectName)['id'] ?? 0);
            $building = $this->almaApi->getOrCreateBuilding($buildingName, $projectId > 0 ? $projectId : null);
            $buildingId = $building['id'] ?? null;
            if ($buildingId === null) {
                throw new RuntimeException("Failed to get building '{$buildingName}'");
            }
            if (! empty($building['is_archived'])) {
                $this->almaApi->setBuildingArchived($buildingId, false);
            } else {
                $fresh = $this->almaApi->buildingById($buildingId);
                if ($fresh !== null && ! empty($fresh['is_archived'])) {
                    $this->almaApi->setBuildingArchived($buildingId, false);
                }
            }

            $rentType = ProjectMapping::resolveRentType($apt, $projectName);
            $propertyTypeField = ProjectMapping::getApartmentField('property_type', $projectName);
            $propertyRaw = ProjectMapping::itemValue($apt, $propertyTypeField);
            if (is_array($propertyRaw)) {
                $propertyRaw = $propertyRaw[0] ?? null;
            }
            $propertyMap = $map['property_type_mapping'] ?? [];
            $bedroomsMap = $map['bedrooms_mapping'] ?? [];
            $propertyType = $propertyMap[(string) $propertyRaw] ?? 'apartment';
            $numberOfBedrooms = (int) ($bedroomsMap[(string) $propertyRaw] ?? 1);

            $additionalField = ProjectMapping::getApartmentField('additional_external_id', $projectName);
            $additionalExternalId = null;
            if ($additionalField !== null) {
                $val = ProjectMapping::itemValue($apt, $additionalField);
                $additionalExternalId = is_array($val) ? ($val[0] ?? null) : $val;
                if ($additionalExternalId === '') {
                    $additionalExternalId = null;
                }
            }
            $useAdditionalExternalId = ($rentType === 'unit');
            if ($rentType === 'rooms') {
                $additionalExternalId = null;
            }

            $metroMap = $map['metro_mapping'] ?? [];
            $subwayField = ProjectMapping::getApartmentField('subway_station', $projectName);
            $subwayValue = '';
            if ($subwayField !== null) {
                $subwayKey = ProjectMapping::itemValue($apt, $subwayField) ?? '';
                $subwayValue = (string) ($metroMap[(string) $subwayKey] ?? '');
            }

            $parkingTypeField = ProjectMapping::getApartmentField('parking_type', $projectName);
            $parkingPaymentTypeMap = $map['parking_payment_type_mapping'] ?? [];
            $parkingPaymentType = 'information_is_missing';
            if ($parkingTypeField !== null) {
                $parkingKey = (string) (ProjectMapping::itemValue($apt, $parkingTypeField) ?? '');
                if ($parkingKey !== '' && isset($parkingPaymentTypeMap[$parkingKey])) {
                    $parkingPaymentType = $parkingPaymentTypeMap[$parkingKey];
                }
            }

            $name = (string) ($apt['title'] ?? ('Apartment '.$bitrixId));
            $internalArea = (float) (ProjectMapping::itemValue(
                $apt,
                ProjectMapping::getApartmentField('internal_area', $projectName)
            ) ?? 0);
            $keybox = ProjectMapping::itemValue($apt, ProjectMapping::getApartmentField('keybox_code', $projectName));
            $elLock = ProjectMapping::itemValue(
                $apt,
                ProjectMapping::getApartmentField('electronic_lock_password', $projectName)
            );
            $poolRaw = ProjectMapping::itemValue(
                $apt,
                ProjectMapping::getApartmentField('is_swimming_pool', $projectName)
            );

            $payload = [
                'building' => $buildingId,
                'is_used_additional_external_id' => $useAdditionalExternalId,
                'name' => $name,
                'header' => $name,
                'property_type' => $propertyType,
                'number' => ((string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('apartment_number', $projectName)
                ) ?? '0')) ?: '0',
                'internal_area' => $internalArea,
                'photos' => [],
                'goal_rent_cost' => (float) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('goal_rent_cost', $projectName)
                ) ?? 0),
                'address' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('address', $projectName)
                ) ?? ''),
                'number_of_bedrooms' => $numberOfBedrooms,
                'number_of_baths' => (int) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('number_of_baths', $projectName)
                ) ?? 0),
                'is_roof_garden' => false,
                'parking' => 'not_applicable',
                'parking_payment_type' => $parkingPaymentType,
                'is_swimming_pool' => (($poolRaw ?? 'N') === 'Y'),
                'total_buildable_area' => $internalArea,
                'floor' => ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('floor', $projectName)
                ),
                'internet_login' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('internet_login', $projectName)
                ) ?? ''),
                'internet_password' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('internet_password', $projectName)
                ) ?? ''),
                'subway_station' => $subwayValue,
                'parking_number' => (string) (ProjectMapping::itemValue(
                    $apt,
                    ProjectMapping::getApartmentField('parking_number', $projectName)
                ) ?? ''),
                'keybox_code' => $keybox !== null && $keybox !== ''
                    ? mb_substr((string) $keybox, 0, 50, 'UTF-8')
                    : null,
                'electronic_lock_password' => $elLock !== null && $elLock !== ''
                    ? mb_substr((string) $elLock, 0, 8, 'UTF-8')
                    : null,
            ];

            if ($rentType === 'rooms') {
                $payload['additional_external_id'] = '';
            } elseif ($additionalExternalId !== null) {
                $payload['additional_external_id'] = $additionalExternalId;
            }

            $result = $this->almaApi->upsertUnit((string) $bitrixId, $payload);

            if ($rentType === 'rooms') {
                $this->units->upsertRoomsForApartment((string) $bitrixId, $projectName, $bitrix);
            }

            if (ProjectMapping::getApartmentField('date_of_refused', $projectName) !== null) {
                $this->terminations->applyForApartment($projectName, (string) $bitrixId, $apt);
            }

            if (($result['method'] ?? '') === 'POST') {
                $almaUnitId = $result['response']['id'] ?? null;
                if ($almaUnitId !== null && $almaUnitId !== '') {
                    try {
                        BridgeSupport::addCrmTimelineComment(
                            $bitrix,
                            $entityTypeId,
                            $bitrixId,
                            BridgeSupport::formatApartmentCreatedComment($almaUnitId)
                        );
                    } catch (Throwable $commentError) {
                        BridgeSupport::log()->warning('Failed to add Bitrix success comment on apartment', [
                            'apartment_id' => $bitrixId,
                            'error' => $commentError->getMessage(),
                        ]);
                    }
                }
            }

            BridgeSupport::log()->info('Apartment sync succeeded', [
                'apartment_id' => $bitrixId,
                'method' => $result['method'],
                'alma_id' => $result['response']['id'] ?? null,
            ]);

            return $result;
        } catch (Throwable $e) {
            try {
                BridgeSupport::addCrmTimelineComment(
                    $bitrix,
                    $entityTypeId,
                    $bitrixId,
                    BridgeSupport::formatSyncErrorComment($e->getMessage())
                );
            } catch (Throwable) {
            }
            BridgeSupport::log()->error('Webhook: apartment sync failed', [
                'entity_id' => $bitrixId,
                'project' => $projectName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
