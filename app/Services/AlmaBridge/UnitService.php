<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
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
        $apartmentBitrixId = $knownApartmentId
            ?? (string) (ProjectMapping::itemValue($unit, $aptLinkField) ?? '');
        if ($apartmentBitrixId === '') {
            throw new RuntimeException('Unit is not linked to apartment');
        }

        $parent = $this->almaApi->unitByExternalId($apartmentBitrixId);
        if ($parent === null || empty($parent['id'])) {
            throw new RuntimeException('Parent apartment not found in Alma external_id='.$apartmentBitrixId);
        }

        $numberField = ProjectMapping::getUnitField('number', $projectName);
        $numberRaw = ProjectMapping::itemValue($unit, $numberField);
        if (is_array($numberRaw)) {
            $numberRaw = $numberRaw[0] ?? '';
        }
        $numberMap = ProjectMapping::unitNumbers($projectName);
        $number = $numberMap[(string) $numberRaw] ?? (string) $numberRaw;

        $payload = [
            'parent_unit' => $parent['id'],
            'property_type' => 'room',
            'number' => $number,
            'name' => (string) ($unit['title'] ?? ''),
            'internal_area' => ProjectMapping::itemValue(
                $unit,
                ProjectMapping::getUnitField('internal_area', $projectName)
            ),
            'goal_rent_cost' => ProjectMapping::itemValue(
                $unit,
                ProjectMapping::getUnitField('goal_rent_cost', $projectName)
            ),
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
