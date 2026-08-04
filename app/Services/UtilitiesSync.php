<?php

namespace App\Services;

use App\Models\Apartment;
use App\Models\Utility;
use App\Support\BitrixSoftDelete;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

class UtilitiesSync
{
    private const IBLOCK_TYPE_ID = 'lists';

    /**
     * @var list<string>
     */
    private const PROPERTY_CODES = [
        'UTILITIES',
        'PROVIDER_COMPANY',
        'ACCOUNT_NUMBER',
        'LOGIN_',
        'PASSWORD',
        'EMAIL_FOR_REGISTRATION',
        'NAME_USED_FOR_REGISTRATION',
        'APARTMENT',
        'ACQUISITION_DEAL',
        'AUTOPAYMENT_DATE',
        'APARTMENT_TEXT',
    ];

    public function __construct(
        private readonly BitrixWebhook $webhookClient
    ) {}

    /**
     * Sync Bitrix universal list 156 into local utilities table.
     *
     * @return array{total:int, successful:int, failed:int, failed_ids:list<int|string>, marked_deleted:int}
     */
    public function sync(): array
    {
        $iblockId = (int) config('services.bitrix.lists.utilities_iblock_id', 156);
        $fieldMap = $this->loadFieldMap($iblockId);
        $items = $this->fetchAllElements($iblockId);
        $fieldMap = $this->expandUtilitiesEnumMap($fieldMap, $items);

        $apartmentIdByBitrixId = Apartment::query()
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
            ->all();

        $now = now();
        $total = 0;
        $successful = 0;
        $failedIds = [];
        $rows = [];

        $bitrixIds = [];
        foreach ($items as $item) {
            $bitrixId = (int) ($item['ID'] ?? 0);
            if ($bitrixId > 0) {
                $bitrixIds[] = $bitrixId;
            }
        }

        $existingIds = Utility::query()
            ->whereIn('bitrix_id', $bitrixIds)
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
            ->all();

        foreach ($items as $item) {
            $total++;

            try {
                $rows[] = $this->normalizeItem($item, $fieldMap, $existingIds, $apartmentIdByBitrixId, $now);
                $successful++;
            } catch (Throwable) {
                $failedIds[] = $item['ID'] ?? 'unknown';
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Utility::query()->upsert(
                $chunk,
                ['bitrix_id'],
                [
                    'name',
                    'utility_type_id',
                    'utility_type',
                    'provider_company',
                    'account_number',
                    'login',
                    'password',
                    'email_for_registration',
                    'name_used_for_registration',
                    'apartment_id',
                    'apartment_bitrix_id',
                    'acquisition_deal_id',
                    'autopayment_date',
                    'apartment_text',
                    'is_deleted',
                    'bitrix_created_at',
                    'bitrix_updated_at',
                    'last_synced_at',
                    'updated_at',
                ]
            );
        }

        $markedDeleted = 0;
        if ($total > 0) {
            $markedDeleted = BitrixSoftDelete::markMissing(
                Utility::class,
                $bitrixIds,
                $now
            );
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
            'marked_deleted' => $markedDeleted,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllElements(int $iblockId): array
    {
        $start = 0;
        $items = [];

        while (true) {
            $response = $this->webhookClient->call('lists.element.get', [
                'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
                'IBLOCK_ID' => $iblockId,
                'start' => $start,
            ]);

            $batch = data_get($response, 'result', []);
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return $items;
    }

    /**
     * @return array<string, array{field_id:string, enum_values:array<string, string>}>
     */
    private function loadFieldMap(int $iblockId): array
    {
        $response = $this->webhookClient->call('lists.field.get', [
            'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
            'IBLOCK_ID' => $iblockId,
        ]);

        $fields = data_get($response, 'result', []);
        if (! is_array($fields)) {
            return [];
        }

        $map = [];
        foreach ($fields as $field) {
            if (is_array($field)) {
                $this->registerField($map, $field);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array{field_id:string, enum_values:array<string, string>}>  $map
     * @param  array<string, mixed>  $field
     */
    private function registerField(array &$map, array $field): void
    {
        $code = trim((string) ($field['CODE'] ?? ''));
        $fieldId = trim((string) ($field['FIELD_ID'] ?? ''));
        if ($code === '' || $fieldId === '' || ! in_array($code, self::PROPERTY_CODES, true)) {
            return;
        }

        $enumValues = [];
        $displayValues = $field['DISPLAY_VALUES_FORM'] ?? null;
        if (is_array($displayValues)) {
            foreach ($displayValues as $enumId => $label) {
                $enumValues[(string) $enumId] = trim((string) $label);
            }
        }

        $map[$code] = [
            'field_id' => $fieldId,
            'enum_values' => $enumValues,
        ];
    }

    /**
     * Map legacy enum IDs from elements onto current DISPLAY_VALUES_FORM labels by sort order.
     *
     * @param  array<string, array{field_id:string, enum_values:array<string, string>}>  $fieldMap
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{field_id:string, enum_values:array<string, string>}>
     */
    private function expandUtilitiesEnumMap(array $fieldMap, array $items): array
    {
        if (! isset($fieldMap['UTILITIES'])) {
            return $fieldMap;
        }

        $current = $fieldMap['UTILITIES']['enum_values'];
        if ($current === []) {
            return $fieldMap;
        }

        $observedIds = [];
        foreach ($items as $item) {
            $raw = BitrixValue::str($this->propertyValue($item, $fieldMap, 'UTILITIES'));
            if ($raw !== null && is_numeric($raw)) {
                $observedIds[(string) $raw] = true;
            }
        }

        $legacyIds = [];
        foreach (array_keys($observedIds) as $id) {
            if (! isset($current[$id])) {
                $legacyIds[] = $id;
            }
        }

        sort($legacyIds, SORT_NUMERIC);

        $sortedCurrent = $current;
        ksort($sortedCurrent, SORT_NUMERIC);
        $labels = array_values($sortedCurrent);

        if ($legacyIds !== [] && count($legacyIds) === count($labels)) {
            foreach ($legacyIds as $index => $legacyId) {
                $current[(string) $legacyId] = $labels[$index];
            }
        }

        $fieldMap['UTILITIES']['enum_values'] = $current;

        return $fieldMap;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array{field_id:string, enum_values:array<string, string>}>  $fieldMap
     * @param  array<int, string>  $existingIds
     * @param  array<int, string>  $apartmentIdByBitrixId
     * @return array<string, mixed>
     */
    private function normalizeItem(
        array $item,
        array $fieldMap,
        array $existingIds,
        array $apartmentIdByBitrixId,
        Carbon $now
    ): array {
        $bitrixId = (int) ($item['ID'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid utility ID.');
        }

        $utilityTypeRaw = BitrixValue::str($this->propertyValue($item, $fieldMap, 'UTILITIES'));
        $utilityTypeId = is_numeric($utilityTypeRaw) ? (int) $utilityTypeRaw : null;
        $utilityType = $this->resolveEnumLabel($utilityTypeRaw, $fieldMap['UTILITIES']['enum_values'] ?? []);

        $apartmentBitrixId = $this->extractCrmEntityId($this->propertyValue($item, $fieldMap, 'APARTMENT'));
        $apartmentId = $apartmentBitrixId !== null
            ? ($apartmentIdByBitrixId[$apartmentBitrixId] ?? null)
            : null;

        return [
            'id' => $existingIds[$bitrixId] ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'name' => BitrixValue::str($item['NAME'] ?? null),
            'utility_type_id' => $utilityTypeId,
            'utility_type' => $utilityType,
            'provider_company' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'PROVIDER_COMPANY')),
            'account_number' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'ACCOUNT_NUMBER')),
            'login' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'LOGIN_')),
            'password' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'PASSWORD')),
            'email_for_registration' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'EMAIL_FOR_REGISTRATION')),
            'name_used_for_registration' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'NAME_USED_FOR_REGISTRATION')),
            'apartment_id' => $apartmentId,
            'apartment_bitrix_id' => $apartmentBitrixId,
            'acquisition_deal_id' => $this->extractCrmEntityId($this->propertyValue($item, $fieldMap, 'ACQUISITION_DEAL')),
            'autopayment_date' => BitrixValue::dt($this->propertyValue($item, $fieldMap, 'AUTOPAYMENT_DATE')),
            'apartment_text' => BitrixValue::str($this->propertyValue($item, $fieldMap, 'APARTMENT_TEXT')),
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['DATE_CREATE'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['TIMESTAMP_X'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array{field_id:string, enum_values:array<string, string>}>  $fieldMap
     */
    private function propertyValue(array $item, array $fieldMap, string $code): mixed
    {
        $fieldId = $fieldMap[$code]['field_id'] ?? null;
        $candidates = [];

        if (is_string($fieldId) && $fieldId !== '') {
            $candidates[] = $fieldId;
        }

        $candidates[] = 'PROPERTY_'.$code;
        $candidates[] = $code;

        foreach ($candidates as $key) {
            if (array_key_exists($key, $item)) {
                return $this->unwrapPropertyValue($item[$key]);
            }
        }

        return null;
    }

    private function unwrapPropertyValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return null;
        }

        if (array_is_list($value)) {
            return $value[0] ?? null;
        }

        $first = reset($value);

        return $first === false ? null : $first;
    }

    /**
     * @param  array<string, string>  $enumValues
     */
    private function resolveEnumLabel(mixed $value, array $enumValues): ?string
    {
        $normalized = BitrixValue::str($value);
        if ($normalized === null) {
            return null;
        }

        if ($enumValues !== [] && isset($enumValues[$normalized])) {
            $label = trim($enumValues[$normalized]);

            return $label !== '' ? $label : $normalized;
        }

        return $normalized;
    }

    private function extractCrmEntityId(mixed $value): ?int
    {
        $normalized = BitrixValue::str($value);
        if ($normalized === null) {
            return null;
        }

        if (is_numeric($normalized)) {
            return (int) $normalized;
        }

        if (preg_match('/(\d+)\s*$/', $normalized, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }


}
