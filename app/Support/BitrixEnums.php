<?php

namespace App\Support;

use App\Services\BitrixRest;

class BitrixEnums
{
    /**
     * Load CRM SPA field enum maps via crm.item.fields.
     *
     * @return array<string, array<string, string>>
     */
    public static function load(
        BitrixRest $bitrix,
        int $entityTypeId,
        bool $enumerationOnly = true
    ): array {
        $response = $bitrix->postJson('crm.item.fields.json', [
            'entityTypeId' => $entityTypeId,
        ]);
        $fields = data_get($response, 'result.fields', data_get($response, 'result', []));
        if (! is_array($fields)) {
            return [];
        }

        return self::mapsFromFields($fields, null, $enumerationOnly);
    }

    /**
     * Load contact UF enum maps via crm.contact.fields.
     *
     * @param  list<string>  $fieldCodes
     * @return array<string, array<string, string>>
     */
    public static function loadContact(BitrixRest $bitrix, array $fieldCodes): array
    {
        $response = $bitrix->postJson('crm.contact.fields', []);
        $fields = data_get($response, 'result', []);
        if (! is_array($fields)) {
            return [];
        }

        return self::mapsFromFields($fields, $fieldCodes, false);
    }

    /**
     * Resolve enum raw id/value to display label when present in map.
     *
     * @param  array<string, array<string, string>>  $enumMap
     */
    public static function resolve(array $enumMap, string $fieldCode, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $key = is_array($value) ? (string) (reset($value) ?: '') : trim((string) $value);
        if ($key === '') {
            return null;
        }

        return $enumMap[$fieldCode][$key] ?? $key;
    }

    /**
     * Build enum maps from a Bitrix fields payload.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>|null  $fieldCodes
     * @return array<string, array<string, string>>
     */
    private static function mapsFromFields(array $fields, ?array $fieldCodes, bool $enumerationOnly): array
    {
        $codes = $fieldCodes ?? array_keys($fields);
        $map = [];

        foreach ($codes as $fieldCode) {
            $field = $fields[$fieldCode] ?? null;
            if (! is_array($field)) {
                continue;
            }

            if ($enumerationOnly && ($field['type'] ?? null) !== 'enumeration') {
                continue;
            }

            $items = $field['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }

            $enumMap = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $enumId = trim((string) ($item['ID'] ?? $item['id'] ?? ''));
                $enumValue = BitrixValue::str($item['VALUE'] ?? $item['value'] ?? null);
                if ($enumId === '' || $enumValue === null) {
                    continue;
                }
                $enumMap[$enumId] = $enumValue;
            }

            if ($enumMap !== []) {
                $map[(string) $fieldCode] = $enumMap;
            }
        }

        return $map;
    }
}
