<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class UnitService
{
    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Upsert one Bitrix unit/room into Alma (HongKong webhook / manual).
     *
     * @return array<string, mixed>
     */
    public function upsert(int|string $bitrixId, string $projectName): array
    {
        if (! BridgeSupport::enabled()) {
            BridgeSupport::log()->info('Alma bridge disabled; unit upsert skipped', [
                'unit_id' => $bitrixId,
                'project' => $projectName,
            ]);

            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        if ($projectName === 'Dubai') {
            BridgeSupport::log()->info('Dubai unit webhook ignored (rooms cascade from apartment)', [
                'unit_id' => $bitrixId,
            ]);

            return ['skipped' => true, 'reason' => 'dubai_unit_webhook_ignored'];
        }

        $bitrix = BitrixWebhookClient::forProject($projectName);

        return $this->upsertRoomById((string) $bitrixId, $projectName, $bitrix);
    }

    /**
     * Cascade rooms for a sharing apartment.
     */
    public function upsertRoomsForApartment(string $apartmentBitrixId, string $projectName, BitrixWebhookClient $bitrix): void
    {
        $unitTypeId = ProjectMapping::unitTypeId($projectName);
        $aptLinkField = ProjectMapping::unitApartmentField($projectName);
        $start = 0;
        do {
            $list = $bitrix->call('crm.item.list', [
                'entityTypeId' => $unitTypeId,
                'filter' => [$aptLinkField => $apartmentBitrixId],
                'select' => ['id'],
                'start' => $start,
            ]);
            $items = $list['result']['items'] ?? $list['result'] ?? [];
            if (! is_array($items)) {
                break;
            }
            foreach ($items as $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                try {
                    $this->upsertRoomById((string) $item['id'], $projectName, $bitrix, $apartmentBitrixId);
                } catch (Throwable $e) {
                    BridgeSupport::log()->warning('Room sync failed in cascade', [
                        'unit_id' => $item['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $start = isset($list['next']) ? (int) $list['next'] : -1;
        } while ($start >= 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function upsertRoomById(
        string $bitrixId,
        string $projectName,
        BitrixWebhookClient $bitrix,
        ?string $knownApartmentId = null
    ): array {
        $unitTypeId = ProjectMapping::unitTypeId($projectName);
        $res = $bitrix->call('crm.item.get', [
            'entityTypeId' => $unitTypeId,
            'id' => $bitrixId,
        ]);
        $unit = $res['result']['item'] ?? null;
        if (! is_array($unit)) {
            throw new RuntimeException('Unit not found in Bitrix24');
        }

        $aptLinkField = ProjectMapping::unitApartmentField($projectName);
        if ($knownApartmentId !== null && $knownApartmentId !== '') {
            $apartmentBitrixId = $knownApartmentId;
        } else {
            $rawApt = ProjectMapping::itemValue($unit, $aptLinkField);
            if (is_array($rawApt)) {
                $rawApt = $rawApt[0] ?? null;
            }
            $apartmentBitrixId = (string) ($rawApt ?? '');
        }
        if ($apartmentBitrixId === '') {
            throw new RuntimeException('Unit is not linked to apartment');
        }

        $aptTypeIds = ProjectMapping::apartmentTypeIds($projectName);
        $aptTypeId = $aptTypeIds[0] ?? null;
        if ($aptTypeId === null) {
            throw new RuntimeException('Apartment entity type id is not configured for project '.$projectName);
        }
        $aptRes = $bitrix->call('crm.item.get', [
            'entityTypeId' => $aptTypeId,
            'id' => $apartmentBitrixId,
        ]);
        $apartment = $aptRes['result']['item'] ?? null;
        if (! is_array($apartment)) {
            throw new RuntimeException('Failed to get apartment data from Bitrix24 for room sync');
        }
        $rentType = ProjectMapping::resolveRentType($apartment, $projectName);
        if ($rentType !== 'rooms') {
            throw new InvalidArgumentException(
                'Room synchronization is allowed only for apartments with rent type "rooms". Current rent type: '.$rentType
            );
        }

        $existingRoom = $this->almaApi->roomByExternalId($bitrixId);
        if ($existingRoom !== null && ! empty($existingRoom['id'])) {
            $parent = $existingRoom['parent_unit'] ?? null;
            $parentUnitId = is_array($parent) ? ($parent['id'] ?? null) : $parent;
        } else {
            $parentUnitId = null;
        }

        if ($parentUnitId === null) {
            $parent = $this->almaApi->unitByExternalId($apartmentBitrixId);
            if ($parent === null || empty($parent['id'])) {
                throw new RuntimeException('Parent apartment not found in Alma external_id='.$apartmentBitrixId);
            }
            $parentUnitId = $parent['id'];
        }

        $numberField = ProjectMapping::getUnitField('number', $projectName);
        $numberRaw = ProjectMapping::itemValue($unit, $numberField);
        if (is_array($numberRaw)) {
            $numberRaw = $numberRaw[0] ?? '';
        }
        $numberMap = ProjectMapping::unitNumbers($projectName);
        $number = ($numberRaw !== null && $numberRaw !== '')
            ? ($numberMap[(string) $numberRaw] ?? (string) $numberRaw)
            : '';

        $goal = ProjectMapping::itemValue($unit, ProjectMapping::getUnitField('goal_rent_cost', $projectName));
        if ($goal === null || $goal === '') {
            $goal = ProjectMapping::itemValue($unit, ProjectMapping::getUnitField('goal_rent_cost_alt', $projectName));
        }

        $name = trim((string) ($unit['title'] ?? ('Room '.$bitrixId)));
        $payload = [
            'parent_unit' => $parentUnitId,
            'property_type' => 'room',
            'number' => $number,
            'name' => $name,
            'header' => $name,
            'internal_area' => ProjectMapping::itemValue(
                $unit,
                ProjectMapping::getUnitField('internal_area', $projectName)
            ),
            'goal_rent_cost' => $goal,
        ];

        $result = $this->almaApi->upsertRoom($bitrixId, array_filter(
            $payload,
            static fn ($v) => $v !== null && $v !== ''
        ));

        BridgeSupport::log()->info('Unit/room sync succeeded', [
            'unit_id' => $bitrixId,
            'method' => $result['method'],
            'alma_id' => $result['response']['id'] ?? null,
        ]);

        return $result;
    }
}
