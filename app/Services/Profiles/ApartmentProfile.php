<?php

namespace App\Services\Profiles;

use App\Models\Apartment;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApartmentProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 144;

    public function entity(): string
    {
        return 'apartments';
    }

    protected function modelClass(): string
    {
        return Apartment::class;
    }

    protected function typeId(): int
    {
        return self::ENTITY_TYPE_ID;
    }

    protected function eventBase(): string
    {
        return 'bitrix.apartment';
    }

    protected function stageType(): string
    {
        return 'apartment';
    }

    /**
     * @return list<string>
     */
    protected function updateCols(): array
    {
        return [
            'title',
            'stage_id',
            'building_id',
            'landlord_contact_id',
            'metro_station_id',
            'apartment_type_id',
            'internal_number',
            'address',
            'property_mode',
            'rental_type',
            'status',
            'floor',
            'metro_minutes',
            'transport_type',
            'parking_number',
            'google_maps_link',
            'disk_url',
            'bathrooms',
            'rooms',
            'area_sqm',
            'wifi_name',
            'wifi_password',
            'access_cards',
            'parking_cards',
            'keys_count',
            'lock_pass',
            'keybox_code',
            'room_keys_notes',
            'is_deleted',
            'bitrix_created_at',
            'bitrix_updated_at',
            'last_synced_at',
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    protected function logKeys(): array
    {
        return [
            'bitrix_id',
            'title',
            'stage_id',
            'building_id',
            'landlord_contact_id',
            'metro_station_id',
            'apartment_type_id',
            'internal_number',
            'address',
            'property_mode',
            'rental_type',
            'status',
            'floor',
            'metro_minutes',
            'transport_type',
            'parking_number',
            'google_maps_link',
            'disk_url',
            'bathrooms',
            'rooms',
            'area_sqm',
            'wifi_name',
            'wifi_password',
            'access_cards',
            'parking_cards',
            'keys_count',
            'lock_pass',
            'keybox_code',
            'room_keys_notes',
            'is_deleted',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(): array
    {
        return [
            'stageIdMap' => $this->loadStageIdMap(),
            'contactIdMap' => DB::table('contacts')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all(),
            'buildingIdMap' => $this->loadBuildingIdMap(),
            'apartmentTypeIdMap' => DB::table('apartment_types')
                ->whereNotNull('bitrix_enum_id')
                ->pluck('id', 'bitrix_enum_id')
                ->mapWithKeys(static fn ($id, $enumId): array => [(int) $enumId => (int) $id])
                ->all(),
            'metroStationIdMap' => DB::table('metro_stations')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (int) $id])
                ->all(),
            'enumMap' => BitrixEnums::load(
                $this->bitrixRestClient,
                self::ENTITY_TYPE_ID,
                false
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function normalizeItem(array $item, mixed $existingId, array $context, Carbon $now): array
    {
        $bitrixId = (int) ($item['id'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid apartment ID');
        }

        /** @var array<string, int> $stageIdMap */
        $stageIdMap = $context['stageIdMap'];
        /** @var array<int, string> $contactIdMap */
        $contactIdMap = $context['contactIdMap'];
        /** @var array<string, string> $buildingIdMap */
        $buildingIdMap = $context['buildingIdMap'];
        /** @var array<int, int> $apartmentTypeIdMap */
        $apartmentTypeIdMap = $context['apartmentTypeIdMap'];
        /** @var array<int, int> $metroStationIdMap */
        $metroStationIdMap = $context['metroStationIdMap'];
        /** @var array<string, array<string, string>> $enumMap */
        $enumMap = $context['enumMap'];

        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $buildingName = BitrixValue::str($item['ufCrm6_1682232363193'] ?? null);
        $contactBitrixId = (int) ($item['contactId'] ?? 0);
        $apartmentTypeEnumId = (int) ($item['ufCrm6_1682232863625'] ?? 0);
        $metroStationBitrixId = (int) ($item['ufCrm6_1682233481671'] ?? 0);
        $propertyModeLabel = BitrixEnums::resolve($enumMap, 'ufCrm6_1736951470242', $item['ufCrm6_1736951470242'] ?? null);
        $rentalTypeLabel = BitrixEnums::resolve($enumMap, 'ufCrm6_1753278068179', $item['ufCrm6_1753278068179'] ?? null);
        $transportTypeLabel = BitrixEnums::resolve($enumMap, 'ufCrm6_1689258811986', $item['ufCrm6_1689258811986'] ?? null);

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'building_id' => $buildingName !== null ? ($buildingIdMap[$this->normalizeTextKey($buildingName)] ?? null) : null,
            'landlord_contact_id' => $contactBitrixId > 0 ? ($contactIdMap[$contactBitrixId] ?? null) : null,
            'metro_station_id' => $metroStationBitrixId > 0 ? ($metroStationIdMap[$metroStationBitrixId] ?? null) : null,
            'apartment_type_id' => $apartmentTypeEnumId > 0 ? ($apartmentTypeIdMap[$apartmentTypeEnumId] ?? null) : null,
            'internal_number' => BitrixValue::str($item['ufCrm6_1707234311507'] ?? null),
            'address' => BitrixValue::str($item['ufCrm6_1718821717'] ?? null),
            'property_mode' => $this->normalizePropertyMode($propertyModeLabel),
            'rental_type' => $this->normalizeRentalType($rentalTypeLabel),
            'status' => 'free',
            'busy_reason' => null,
            'work_model' => null,
            'floor' => BitrixValue::asInt($item['ufCrm6_1682232312628'] ?? null),
            'metro_minutes' => BitrixValue::asInt($item['ufCrm6_1682238902770'] ?? null),
            'transport_type' => $this->normalizeTransportType($transportTypeLabel),
            'parking_number' => BitrixValue::firstStr(
                $item['ufCrm6_1682238927645'] ?? null,
                $item['ufCrm6_1683299159437'] ?? null
            ),
            'google_maps_link' => BitrixValue::str($item['ufCrm6Adresslink'] ?? null),
            'disk_url' => BitrixValue::str($item['ufCrm6_1785314487770'] ?? null),
            'bathrooms' => BitrixValue::asInt($item['ufCrm6_1724248916'] ?? null),
            'rooms' => BitrixValue::asInt($item['ufCrm6_1724247642'] ?? null),
            'area_sqm' => BitrixValue::asFloat($item['ufCrm6_1697722222483'] ?? null),
            'wifi_name' => BitrixValue::str($item['ufCrm6_1682235809295'] ?? null),
            'wifi_password' => BitrixValue::str($item['ufCrm6_1686728251990'] ?? null),
            'access_cards' => BitrixValue::firstInt($item['ufCrm6_1715776920'] ?? null),
            'parking_cards' => BitrixValue::firstInt($item['ufCrm6_1715777017'] ?? null),
            'keys_count' => BitrixValue::firstInt($item['ufCrm6_1715777650'] ?? null),
            'lock_pass' => BitrixValue::str($item['ufCrm6_1715777670'] ?? null),
            'keybox_code' => BitrixValue::str($item['ufCrm6_1720794204'] ?? null),
            'room_keys_notes' => BitrixValue::str($item['ufCrm6_1721897304'] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => BitrixValue::dt($item['createdTime'] ?? null),
            'bitrix_updated_at' => BitrixValue::dt($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function loadBuildingIdMap(): array
    {
        return DB::table('buildings')
            ->get(['id', 'name'])
            ->mapWithKeys(function (object $row): array {
                $name = $this->normalizeTextKey((string) $row->name);
                if ($name === '') {
                    return [];
                }

                return [$name => (string) $row->id];
            })
            ->all();
    }

    private function normalizeTextKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function normalizePropertyMode(?string $value): ?string
    {
        $normalized = $this->normalizeTextKey((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'room') || str_contains($normalized, 'sharing')) {
            return 'sharing';
        }
        if (str_contains($normalized, 'unit')) {
            return 'unit';
        }

        return null;
    }

    private function normalizeRentalType(?string $value): ?string
    {
        $normalized = $this->normalizeTextKey((string) $value);
        if ($normalized === '') {
            return null;
        }

        $hasHoliday = str_contains($normalized, 'holiday');
        $hasEjari = str_contains($normalized, 'ejari');
        if ($hasHoliday && $hasEjari) {
            return 'holiday_home_ejari';
        }
        if ($hasHoliday) {
            return 'holiday_home';
        }
        if ($hasEjari) {
            return 'ejari';
        }
        if (str_contains($normalized, 'hotel')) {
            return 'hotel_apartment';
        }

        return null;
    }

    private function normalizeTransportType(?string $value): ?string
    {
        $normalized = $this->normalizeTextKey((string) $value);
        if ($normalized === '') {
            return null;
        }
        if (str_contains($normalized, 'metro')) {
            return 'metro';
        }
        if (str_contains($normalized, 'tram')) {
            return 'tram';
        }
        if (str_contains($normalized, 'bus')) {
            return 'bus';
        }

        return null;
    }
}
