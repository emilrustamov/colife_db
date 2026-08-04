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
        BitrixRest $bitrixRestClient,
        int $entityTypeId,
        bool $enumerationTypeOnly = true
    ): array {
        $response = $bitrixRestClient->postJson('crm.item.fields.json', [
            'entityTypeId' => $entityTypeId,
        ]);
        $fields = data_get($response, 'result.fields', data_get($response, 'result', []));
        if (! is_array($fields)) {
            return [];
        }

        $map = [];
        foreach ($fields as $fieldCode => $field) {
            if (! is_array($field)) {
                continue;
            }

            if ($enumerationTypeOnly && ($field['type'] ?? null) !== 'enumeration') {
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
}
