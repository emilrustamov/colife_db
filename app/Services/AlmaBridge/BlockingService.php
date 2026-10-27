<?php

namespace App\Services\AlmaBridge;

use App\Services\AlmaApi;
use RuntimeException;

class BlockingService
{
    private const PROP_ENTITY_ID = 'PROPERTY_1626';

    private const PROP_STATUS = 'PROPERTY_1628';

    private const PROP_TYPE = 'PROPERTY_1624';

    private const PROP_START = 'PROPERTY_1630';

    private const PROP_END = 'PROPERTY_1632';

    private const PROP_REASON = 'PROPERTY_1636';

    private const PROP_ACTIVE = 'PROPERTY_1800';

    private const DEFAULT_END = '2030-12-31';

    public function __construct(
        private readonly AlmaApi $almaApi,
    ) {}

    /**
     * Apply blocking list element as Alma synthetic tenant contract.
     *
     * @return array<string, mixed>
     */
    public function apply(int|string $listElementId, string $projectName): array
    {
        if (! BridgeSupport::enabled()) {
            return ['skipped' => true, 'reason' => 'bridge_disabled'];
        }

        $element = $this->fetchListElement($projectName, $listElementId);
        $externalId = (string) ($element['ID'] ?? $listElementId);
        $status = $this->firstProp($element, self::PROP_STATUS);
        $typeOfSmart = $this->firstProp($element, self::PROP_TYPE);
        $startDate = $this->normalizeDate($this->firstProp($element, self::PROP_START));
        $inactive = in_array(
            (string) $this->firstProp($element, self::PROP_ACTIVE),
            ProjectMapping::inactiveStatuses($projectName),
            true
        );

        if ($inactive) {
            $existing = $this->almaApi->tenantByExternalId($externalId);
            if ($existing !== null) {
                $this->almaApi->archiveTenant($externalId, true);
                BridgeSupport::log()->info('Blocking contract archived', ['external_id' => $externalId]);

                return ['method' => 'ARCHIVE', 'external_id' => $externalId];
            }

            return ['skipped' => true, 'reason' => 'inactive_no_contract'];
        }

        if (trim((string) $status) !== 'Блокировка') {
            return ['skipped' => true, 'reason' => 'not_blocking_event'];
        }
        if (strtolower(trim((string) $typeOfSmart)) !== 'apartment') {
            return ['skipped' => true, 'reason' => 'not_apartment'];
        }
        if ($startDate === null) {
            return ['skipped' => true, 'reason' => 'no_start_date'];
        }

        $reasonText = trim((string) ($this->firstProp($element, self::PROP_REASON) ?? ''));
        $reasonMap = ProjectMapping::fields($projectName)['blocking_list_reason_alma_client_ids'] ?? [];
        $almaClientId = isset($reasonMap[$reasonText]) ? (int) $reasonMap[$reasonText] : 0;
        if ($almaClientId <= 0) {
            return ['skipped' => true, 'reason' => 'no_alma_client_for_reason'];
        }

        $apartmentRaw = $this->firstProp($element, self::PROP_ENTITY_ID);
        $apartmentId = $this->normalizeEntityId($apartmentRaw);
        if ($apartmentId === null) {
            throw new RuntimeException('Blocking list element has empty apartment id');
        }
        $unit = $this->almaApi->unitByExternalId((string) $apartmentId);
        if ($unit === null || empty($unit['id'])) {
            throw new RuntimeException('Apartment not found in Alma for blocking');
        }

        $endDate = $this->normalizeDate($this->firstProp($element, self::PROP_END)) ?? self::DEFAULT_END;
        $payload = [
            'external_id' => $externalId,
            'unit_id' => $unit['id'],
            'client_id' => $almaClientId,
            'name' => 'Blocking '.$externalId,
            'start_date' => BridgeSupport::formatDateForAlma($startDate),
            'end_date' => BridgeSupport::formatDateForAlma($endDate),
            'price' => '0.00',
            'type_contract' => 'Blocking',
            'rent_type' => 'short_term',
        ];

        $existing = $this->almaApi->tenantByExternalId($externalId);
        if ($existing !== null) {
            if (BridgeSupport::isAlmaContractArchived($existing)) {
                $this->almaApi->updateTenantContract($existing['id'], [
                    'external_id' => $externalId.'_old_'.$existing['id'],
                ]);
                $created = $this->almaApi->createTenantContract($payload);
                BridgeSupport::log()->info('Blocking contract recreated', ['external_id' => $externalId]);

                return ['method' => 'POST', 'response' => $created];
            }
            unset($payload['client_id']);
            $updated = $this->almaApi->updateTenantContract($existing['id'], $payload);
            BridgeSupport::log()->info('Blocking contract updated', ['external_id' => $externalId]);

            return ['method' => 'PATCH', 'response' => $updated];
        }

        $created = $this->almaApi->createTenantContract($payload);
        BridgeSupport::log()->info('Blocking contract created', ['external_id' => $externalId]);

        return ['method' => 'POST', 'response' => $created];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchListElement(string $projectName, int|string $listElementId): array
    {
        $iblockId = (int) (ProjectMapping::fields($projectName)['blocking_list_iblock_id'] ?? 0);
        if ($iblockId <= 0) {
            throw new RuntimeException('Blocking list not configured');
        }
        $bitrix = BitrixWebhookClient::forProject($projectName);
        $elRes = $bitrix->call('lists.element.get', [
            'IBLOCK_TYPE_ID' => 'lists',
            'IBLOCK_ID' => $iblockId,
            'ELEMENT_ID' => $listElementId,
        ]);
        $raw = $elRes['result'] ?? [];
        $element = isset($raw['ID']) ? $raw : (is_array($raw) && isset($raw[0]) ? $raw[0] : null);
        if (! is_array($element) || $element === []) {
            throw new RuntimeException('List element not found');
        }

        return $element;
    }

    /**
     * @param  array<string, mixed>  $element
     */
    private function firstProp(array $element, string $key): ?string
    {
        $prop = $element[$key] ?? null;
        if (! is_array($prop) || $prop === []) {
            return null;
        }
        $v = reset($prop);

        return $v === false ? null : (string) $v;
    }

    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $trimmed = trim($value);
        $dt = date_create_from_format('d.m.Y', $trimmed)
            ?: date_create_from_format('Y-m-d', $trimmed)
            ?: date_create($trimmed);

        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function normalizeEntityId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if (preg_match('/^T\d+_(\d+)$/', $s, $m)) {
            return (int) $m[1];
        }

        return is_numeric($s) ? (int) $s : null;
    }
}
