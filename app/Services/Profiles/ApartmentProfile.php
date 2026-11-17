<?php

namespace App\Services\Profiles;

use App\Models\Apartment;
use App\Support\BitrixEnums;
use App\Support\BitrixValue;
use App\Support\EntityMap;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApartmentProfile extends SpaProfile
{
    public const ENTITY_TYPE_ID = 144;

    /**
     * @return list<string>
     */
    public static function select(): array
    {
        return [
            'id', 'title', 'stageId', 'contactId', 'createdTime', 'updatedTime',
            'ufCrm6_1682232363193', 'ufCrm6_1707234311507', 'ufCrm6_1718821717',
            'ufCrm6_1682232863625', 'ufCrm6_1682233481671', 'ufCrm6_1682232312628',
            'ufCrm6_1682238902770', 'ufCrm6_1689258811986', 'ufCrm6Adresslink',
            'ufCrm6_1697722222483', 'ufCrm6_1724248916', 'ufCrm6_1724247642',
            'ufCrm6_1682235809295', 'ufCrm6_1686728251990', 'ufCrm6_1715776920',
            'ufCrm6_1715777017', 'ufCrm6_1715777650', 'ufCrm6_1715777670',
            'ufCrm6_1720794204', 'ufCrm6_1721897304', 'ufCrm6_1736951470242',
            'ufCrm6_1753278068179', 'ufCrm6_1682238927645', 'ufCrm6_1683299159437',
            'ufCrm6_1785314487770',
        ];
    }

    public function entity(): string
    {
        return 'apartments';
    }

    protected function model(): string
    {
        return Apartment::class;
    }

    protected function typeId(): int
    {
        $typeId = EntityMap::typeId('ae', 'apartments');

        return $typeId > 0 ? $typeId : self::ENTITY_TYPE_ID;
    }

    protected function event(): string
    {
        return 'bitrix.apartment';
    }

    protected function stageEntity(): string
    {
        return 'apartment';
    }

    /**
     * @return list<string>
     */
    protected function fields(): array
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(): array
    {
        return [
            'stages' => $this->stageMap(),
            'contacts' => $this->idMap('contacts'),
            'buildings' => $this->buildingMap(),
            'typeIds' => DB::table('apartment_types')
                ->whereNotNull('bitrix_enum_id')
                ->pluck('id', 'bitrix_enum_id')
                ->mapWithKeys(static fn ($id, $enumId): array => [(int) $enumId => (int) $id])
                ->all(),
            'metroIds' => DB::table('metro_stations')
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (int) $id])
                ->all(),
            'enums' => $this->loadEnums(false, self::ENTITY_TYPE_ID),
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

        /** @var array<string, int> $stages */
        $stages = $context['stages'];
        /** @var array<int, string> $contacts */
        $contacts = $context['contacts'];
        /** @var array<string, string> $buildings */
        $buildings = $context['buildings'];
        /** @var array<int, int> $typeIds */
        $typeIds = $context['typeIds'];
        /** @var array<int, int> $metroIds */
        $metroIds = $context['metroIds'];
        /** @var array<string, array<string, string>> $enums */
        $enums = $context['enums'];

        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $buildingName = BitrixValue::str($item['ufCrm6_1682232363193'] ?? null);
        $contactBitrixId = (int) ($item['contactId'] ?? 0);
        $typeEnumId = (int) ($item['ufCrm6_1682232863625'] ?? 0);
        $metroBitrixId = (int) ($item['ufCrm6_1682233481671'] ?? 0);
        $modeLabel = BitrixEnums::resolve($enums, 'ufCrm6_1736951470242', $item['ufCrm6_1736951470242'] ?? null);
        $rentalLabel = BitrixEnums::resolve($enums, 'ufCrm6_1753278068179', $item['ufCrm6_1753278068179'] ?? null);
        $transitLabel = BitrixEnums::resolve($enums, 'ufCrm6_1689258811986', $item['ufCrm6_1689258811986'] ?? null);

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'portal_id' => $this->portalId(),
            'bitrix_id' => $bitrixId,
            'title' => BitrixValue::str($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stages[$stageCode] ?? null) : null,
            'building_id' => $buildingName !== null ? ($buildings[$this->textKey($buildingName)] ?? null) : null,
            'landlord_contact_id' => $contactBitrixId > 0 ? ($contacts[$contactBitrixId] ?? null) : null,
            'metro_station_id' => $metroBitrixId > 0 ? ($metroIds[$metroBitrixId] ?? null) : null,
            'apartment_type_id' => $typeEnumId > 0 ? ($typeIds[$typeEnumId] ?? null) : null,
            'internal_number' => BitrixValue::str($item['ufCrm6_1707234311507'] ?? null),
            'address' => BitrixValue::str($item['ufCrm6_1718821717'] ?? null),
            'property_mode' => $this->mapMode($modeLabel),
            'rental_type' => $this->mapRental($rentalLabel),
            'status' => 'free',
            'busy_reason' => null,
            'work_model' => null,
            'floor' => BitrixValue::asInt($item['ufCrm6_1682232312628'] ?? null),
            'metro_minutes' => BitrixValue::asInt($item['ufCrm6_1682238902770'] ?? null),
            'transport_type' => $this->mapTransit($transitLabel),
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
            ...$this->stamps($item, $now),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildingMap(): array
    {
        return DB::table('buildings')
            ->get(['id', 'name'])
            ->mapWithKeys(function (object $row): array {
                $name = $this->textKey((string) $row->name);
                if ($name === '') {
                    return [];
                }

                return [$name => (string) $row->id];
            })
            ->all();
    }

    private function textKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function mapMode(?string $value): ?string
    {
        $normalized = $this->textKey((string) $value);
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

    private function mapRental(?string $value): ?string
    {
        $normalized = $this->textKey((string) $value);
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

    private function mapTransit(?string $value): ?string
    {
        $normalized = $this->textKey((string) $value);
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
