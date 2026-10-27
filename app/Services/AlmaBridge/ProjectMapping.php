<?php

namespace App\Services\AlmaBridge;

class ProjectMapping
{
    /**
     * @return array<string, mixed>
     */
    public static function fields(string $projectName = 'Dubai'): array
    {
        $mappings = config('alma_bridge.mappings', []);

        return $mappings[$projectName] ?? $mappings['Dubai'] ?? [];
    }

    /**
     * @return array{id: int, webhook_url: ?string, name: string, domain?: string}
     */
    public static function project(string $projectName = 'Dubai'): array
    {
        $projects = config('alma_bridge.projects', []);
        $cfg = $projects[$projectName] ?? $projects['Dubai'] ?? [];

        return [
            'id' => (int) ($cfg['id'] ?? 0),
            'webhook_url' => $cfg['webhook_url'] ?? null,
            'name' => (string) ($cfg['name'] ?? $projectName),
            'domain' => (string) ($cfg['domain'] ?? ''),
        ];
    }

    public static function mapContractType(mixed $bitrixType, string $projectName = 'Dubai'): ?string
    {
        $mapping = self::fields($projectName);

        return $mapping['contract_type_mapping'][(string) $bitrixType] ?? null;
    }

    public static function mapNationality(mixed $bitrixValue, string $projectName = 'Dubai'): ?int
    {
        if ($bitrixValue === null || $bitrixValue === '') {
            return null;
        }
        $mapping = self::fields($projectName);
        $countryId = $mapping['nationality_mapping'][(string) $bitrixValue] ?? null;

        return $countryId !== null ? (int) $countryId : null;
    }

    public static function mapWorkModel(mixed $bitrixType, string $projectName = 'Dubai'): ?string
    {
        $mapping = self::fields($projectName);

        return $mapping['work_model_mapping'][(string) $bitrixType] ?? null;
    }

    public static function unitTypeId(string $projectName = 'Dubai'): int
    {
        return (int) (self::fields($projectName)['unit_entity_type_id'] ?? 167);
    }

    public static function contractTypeId(string $projectName = 'Dubai'): int
    {
        return (int) (self::fields($projectName)['contract_entity_type_id'] ?? 183);
    }

    /**
     * @param  array<string, mixed>  $aptItem
     */
    public static function resolveRentType(array $aptItem, string $projectName = 'Dubai'): string
    {
        $rentTypeField = self::rentTypeField($projectName);
        if ($rentTypeField === null) {
            return 'unit';
        }
        $mapping = self::fields($projectName);
        $rentTypeMapping = $mapping['rent_type_mapping'] ?? [];
        $defaultRentTypeValue = self::defaultRentType($projectName);
        $rentTypeValue = $aptItem[$rentTypeField] ?? $defaultRentTypeValue;
        if (is_array($rentTypeValue)) {
            $rentTypeValue = $rentTypeValue[0] ?? $defaultRentTypeValue;
        }

        return $rentTypeMapping[(string) $rentTypeValue] ?? 'unit';
    }

    /**
     * @return list<string>
     */
    public static function tenantArchiveStages(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['tenant_contract_archive_stage_ids'] ?? [];

        return is_array($ids) ? array_map('strval', $ids) : [];
    }

    public static function getContractField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['contract_fields'][$fieldName] ?? null;
    }

    public static function getLandlordField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['landlord_fields'][$fieldName] ?? null;
    }

    public static function getTenantField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['tenant_fields'][$fieldName] ?? null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function itemValue(array $item, ?string $fieldCode): mixed
    {
        if ($fieldCode === null || $fieldCode === '') {
            return null;
        }
        if (array_key_exists($fieldCode, $item)) {
            return $item[$fieldCode];
        }
        $camelCase = self::bitrixFieldToCamelCase($fieldCode);
        if ($camelCase !== $fieldCode && array_key_exists($camelCase, $item)) {
            return $item[$camelCase];
        }

        return null;
    }

    private static function bitrixFieldToCamelCase(string $fieldCode): string
    {
        if (preg_match('/^UF_CRM_(\d+)_(.+)$/', $fieldCode, $matches)) {
            return 'ufCrm'.$matches[1].'_'.$matches[2];
        }
        if (preg_match('/^UF_CRM_(\d+)$/', $fieldCode, $matches)) {
            return 'ufCrm'.$matches[1];
        }

        return $fieldCode;
    }

    public static function unitApartmentField(string $projectName = 'Dubai'): string
    {
        if ($projectName === 'HongKong') {
            return 'ufCrm13Apartments';
        }

        return 'ufCrm8_1684429208';
    }

    public static function rentTypeField(string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['fields']['rent_type'] ?? null;
    }

    public static function getUnitField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['unit_fields'][$fieldName] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public static function unitNumbers(string $projectName = 'Dubai'): array
    {
        return self::fields($projectName)['unit_number_mapping'] ?? [];
    }

    public static function landlordTypeId(string $projectName = 'Dubai'): ?int
    {
        $id = self::fields($projectName)['landlord_contract_entity_type_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    public static function landlordContractField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['landlord_contract_fields'][$fieldName] ?? null;
    }

    public static function getApartmentField(string $fieldName, string $projectName = 'Dubai'): ?string
    {
        return self::fields($projectName)['fields'][$fieldName] ?? null;
    }

    /**
     * @return list<int>
     */
    public static function apartmentTypeIds(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['apartment_entity_type_ids'] ?? [];

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * @return list<string>
     */
    public static function archiveStages(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['archive_stage_ids'] ?? [];

        return array_map('strval', is_array($ids) ? $ids : []);
    }

    /**
     * @return list<string>
     */
    public static function allowedStages(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['allowed_stage_ids'] ?? [];

        return array_map('strval', is_array($ids) ? $ids : []);
    }

    /**
     * @return list<string>
     */
    public static function noInviteTypes(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['no_invitation_contract_type_ids'] ?? [];

        return array_map('strval', is_array($ids) ? $ids : []);
    }

    public static function defaultRentType(string $projectName = 'Dubai'): string
    {
        return (string) (self::fields($projectName)['default_rent_type_value'] ?? '4598');
    }

    public static function airbnbTypeId(string $projectName = 'Dubai'): ?string
    {
        $map = self::fields($projectName)['contract_type_mapping'] ?? [];
        foreach ($map as $id => $name) {
            if ($name === 'Airbnb') {
                return (string) $id;
            }
        }

        return null;
    }

    public static function terminationTypeId(string $projectName = 'Dubai'): ?string
    {
        $map = self::fields($projectName)['contract_type_mapping'] ?? [];
        foreach ($map as $id => $name) {
            if ($name === 'Termination') {
                return (string) $id;
            }
        }

        return null;
    }

    public static function terminationClientId(string $projectName = 'Dubai'): ?int
    {
        $id = self::fields($projectName)['termination_alma_client_id'] ?? 0;

        return $id !== null && (int) $id > 0 ? (int) $id : null;
    }

    public static function terminationEvent(string $projectName = 'Dubai'): string
    {
        return (string) (self::fields($projectName)['termination_list_event_type'] ?? 'Расторжение');
    }

    /**
     * @return list<string>
     */
    public static function inactiveStatuses(string $projectName = 'Dubai'): array
    {
        $ids = self::fields($projectName)['list_inactive_status_ids'] ?? [];

        return array_map('strval', is_array($ids) ? $ids : []);
    }
}
