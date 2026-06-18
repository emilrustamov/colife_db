<?php

namespace App\Services\Profiles;

use App\Models\Apartment;
use App\Services\BitrixRestClient;
use App\Services\Contracts\BitrixEntityProfile;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BitrixApartmentProfile implements BitrixEntityProfile
{
    private const ENTITY_TYPE_ID = 144;

    public function __construct(
        private readonly BitrixRestClient $bitrixRestClient
    ) {}

    public function entity(): string
    {
        return 'apartments';
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatchItems(array $items): array
    {
        $processed = 0;
        $created = 0;
        $updated = 0;
        $successful = 0;
        $skipped = 0;
        $failedIds = [];
        $now = now();

        $incomingBitrixIds = [];
        foreach ($items as $item) {
            $bitrixId = (int) ($item['id'] ?? 0);
            if ($bitrixId > 0) {
                $incomingBitrixIds[] = $bitrixId;
            }
        }

        $existing = Apartment::query()
            ->whereIn('bitrix_id', $incomingBitrixIds)
            ->get(['id', 'bitrix_id', 'bitrix_updated_at']);
        $existingByBitrixId = $existing->keyBy(fn (Apartment $row): int => (int) $row->bitrix_id);

        $stageIdMap = $this->loadStageIdMap();
        $contactIdMap = $this->loadContactIdMap();
        $buildingIdMap = $this->loadBuildingIdMap();
        $apartmentTypeIdMap = $this->loadApartmentTypeIdMap();
        $metroStationIdMap = $this->loadMetroStationIdMap();
        $enumMap = $this->loadFieldEnumMap();

        $rows = [];
        $events = [];
        $oldValues = [];

        foreach ($items as $item) {
            $processed++;

            try {
                $bitrixId = (int) ($item['id'] ?? 0);
                if ($bitrixId <= 0) {
                    throw new \RuntimeException('Invalid apartment ID');
                }

                $existingRow = $existingByBitrixId->get($bitrixId);
                $normalized = $this->normalizeItem(
                    $item,
                    $existingRow?->id,
                    $stageIdMap,
                    $contactIdMap,
                    $buildingIdMap,
                    $apartmentTypeIdMap,
                    $metroStationIdMap,
                    $enumMap,
                    $now
                );

                $incomingUpdatedAt = $normalized['bitrix_updated_at'] instanceof Carbon
                    ? $normalized['bitrix_updated_at']->getTimestamp()
                    : null;
                $existingUpdatedAt = $existingRow?->bitrix_updated_at?->getTimestamp();

                if ($existingUpdatedAt !== null && $incomingUpdatedAt !== null && $incomingUpdatedAt <= $existingUpdatedAt) {
                    $skipped++;

                    continue;
                }

                if ($existingRow !== null) {
                    $updated++;
                    $events[$bitrixId] = 'bitrix.apartment.updated';
                    $oldValues[$bitrixId] = $this->buildActivityPayloadFromModel($existingRow);
                } else {
                    $created++;
                    $events[$bitrixId] = 'bitrix.apartment.created';
                }

                $rows[] = $normalized;
                $successful++;
            } catch (\Throwable) {
                $failedIds[] = $item['id'] ?? 'unknown';
            }
        }

        if ($rows !== []) {
            DB::transaction(function () use ($rows, $events, $oldValues, $now): void {
                Apartment::query()->upsert(
                    $rows,
                    ['bitrix_id'],
                    [
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
                        'busy_reason',
                        'work_model',
                        'floor',
                        'metro_minutes',
                        'transport_type',
                        'parking_number',
                        'google_maps_link',
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
                    ]
                );

                $models = Apartment::query()
                    ->whereIn('bitrix_id', array_map(static fn (array $r): int => (int) $r['bitrix_id'], $rows))
                    ->get();
                $modelByBitrix = $models->keyBy(fn (Apartment $row): int => (int) $row->bitrix_id);

                $logRows = [];
                foreach ($rows as $row) {
                    $bitrixId = (int) $row['bitrix_id'];
                    $model = $modelByBitrix->get($bitrixId);
                    if (! $model instanceof Apartment) {
                        continue;
                    }

                    $newPayload = $this->buildActivityPayloadFromModel($model);
                    $event = $events[$bitrixId] ?? 'bitrix.apartment.synced';
                    $oldPayload = $event === 'bitrix.apartment.updated' ? ($oldValues[$bitrixId] ?? null) : null;
                    if ($event === 'bitrix.apartment.updated' && ! $this->hasMeaningfulDiff($oldPayload, $newPayload)) {
                        continue;
                    }

                    $logRows[] = [
                        'id' => (string) Str::uuid(),
                        'event' => $event,
                        'subject_type' => Apartment::class,
                        'subject_id' => $model->id,
                        'user_id' => null,
                        'old_values' => $oldPayload !== null ? json_encode($oldPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        'new_values' => json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'happened_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($logRows !== []) {
                    DB::table('activity_logs')->insert($logRows);
                }
            });
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'successful' => $successful,
            'skipped' => $skipped,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
        ];
    }

    public function syncSingleItemByBitrixId(int $bitrixId): bool
    {
        $response = $this->bitrixRestClient->postJson('crm.item.get.json', [
            'entityTypeId' => self::ENTITY_TYPE_ID,
            'id' => $bitrixId,
        ]);

        $item = data_get($response, 'result.item', null);
        if (! is_array($item)) {
            return false;
        }

        $result = $this->syncBatchItems([$item]);

        return $result['successful'] > 0 || $result['skipped'] > 0;
    }

    public function markItemDeleted(int $bitrixId): int
    {
        return Apartment::query()
            ->where('bitrix_id', $bitrixId)
            ->update([
                'is_deleted' => true,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $stageIdMap
     * @param  array<int, string>  $contactIdMap
     * @param  array<string, string>  $buildingIdMap
     * @param  array<int, int>  $apartmentTypeIdMap
     * @param  array<int, int>  $metroStationIdMap
     * @param  array<string, array<string, string>>  $enumMap
     * @return array<string, mixed>
     */
    private function normalizeItem(
        array $item,
        ?string $existingId,
        array $stageIdMap,
        array $contactIdMap,
        array $buildingIdMap,
        array $apartmentTypeIdMap,
        array $metroStationIdMap,
        array $enumMap,
        Carbon $now
    ): array {
        $bitrixId = (int) ($item['id'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid apartment ID');
        }

        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $buildingName = $this->toNullableString($item['ufCrm6_1682232363193'] ?? null);
        $contactBitrixId = (int) ($item['contactId'] ?? 0);
        $apartmentTypeEnumId = (int) ($item['ufCrm6_1682232863625'] ?? 0);
        $metroStationBitrixId = (int) ($item['ufCrm6_1682233481671'] ?? 0);
        $propertyModeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1736951470242', $item['ufCrm6_1736951470242'] ?? null);
        $rentalTypeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1753278068179', $item['ufCrm6_1753278068179'] ?? null);
        $transportTypeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1689258811986', $item['ufCrm6_1689258811986'] ?? null);

        return [
            'id' => $existingId ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'title' => $this->toNullableString($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'building_id' => $buildingName !== null ? ($buildingIdMap[$this->normalizeTextKey($buildingName)] ?? null) : null,
            'landlord_contact_id' => $contactBitrixId > 0 ? ($contactIdMap[$contactBitrixId] ?? null) : null,
            'metro_station_id' => $metroStationBitrixId > 0 ? ($metroStationIdMap[$metroStationBitrixId] ?? null) : null,
            'apartment_type_id' => $apartmentTypeEnumId > 0 ? ($apartmentTypeIdMap[$apartmentTypeEnumId] ?? null) : null,
            'internal_number' => $this->toNullableString($item['ufCrm6_1707234311507'] ?? null),
            'address' => $this->toNullableString($item['ufCrm6_1718821717'] ?? null),
            'property_mode' => $this->normalizePropertyMode($propertyModeLabel),
            'rental_type' => $this->normalizeRentalType($rentalTypeLabel),
            'status' => 'free',
            'busy_reason' => null,
            'work_model' => null,
            'floor' => $this->toNullableInt($item['ufCrm6_1682232312628'] ?? null),
            'metro_minutes' => $this->toNullableInt($item['ufCrm6_1682238902770'] ?? null),
            'transport_type' => $this->normalizeTransportType($transportTypeLabel),
            'parking_number' => $this->firstNonEmptyString($item['ufCrm6_1682238927645'] ?? null, $item['ufCrm6_1683299159437'] ?? null),
            'google_maps_link' => $this->toNullableString($item['ufCrm6Adresslink'] ?? null),
            'bathrooms' => $this->toNullableInt($item['ufCrm6_1724248916'] ?? null),
            'rooms' => $this->toNullableInt($item['ufCrm6_1724247642'] ?? null),
            'area_sqm' => $this->toNullableFloat($item['ufCrm6_1697722222483'] ?? null),
            'wifi_name' => $this->toNullableString($item['ufCrm6_1682235809295'] ?? null),
            'wifi_password' => $this->toNullableString($item['ufCrm6_1686728251990'] ?? null),
            'access_cards' => $this->extractFirstInt($item['ufCrm6_1715776920'] ?? null),
            'parking_cards' => $this->extractFirstInt($item['ufCrm6_1715777017'] ?? null),
            'keys_count' => $this->extractFirstInt($item['ufCrm6_1715777650'] ?? null),
            'lock_pass' => $this->toNullableString($item['ufCrm6_1715777670'] ?? null),
            'keybox_code' => $this->toNullableString($item['ufCrm6_1720794204'] ?? null),
            'room_keys_notes' => $this->toNullableString($item['ufCrm6_1721897304'] ?? null),
            'is_deleted' => false,
            'bitrix_created_at' => $this->toNullableDateTime($item['createdTime'] ?? null),
            'bitrix_updated_at' => $this->toNullableDateTime($item['updatedTime'] ?? null),
            'last_synced_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function loadStageIdMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', 'apartment')
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $bitrixStageId): array => [(string) $bitrixStageId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function loadContactIdMap(): array
    {
        return DB::table('contacts')
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
            ->all();
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

    /**
     * @return array<int, int>
     */
    private function loadApartmentTypeIdMap(): array
    {
        return DB::table('apartment_types')
            ->whereNotNull('bitrix_enum_id')
            ->pluck('id', 'bitrix_enum_id')
            ->mapWithKeys(static fn ($id, $enumId): array => [(int) $enumId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function loadMetroStationIdMap(): array
    {
        return DB::table('metro_stations')
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (int) $id])
            ->all();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadFieldEnumMap(): array
    {
        $response = $this->bitrixRestClient->postJson('crm.item.fields.json', ['entityTypeId' => self::ENTITY_TYPE_ID]);
        $fields = data_get($response, 'result.fields', []);
        if (! is_array($fields)) {
            return [];
        }

        $map = [];
        foreach ($fields as $fieldCode => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $items = $definition['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }
            $enumMap = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $enumId = trim((string) ($item['ID'] ?? ''));
                $enumValue = $this->toNullableString($item['VALUE'] ?? null);
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

    private function buildActivityPayloadFromModel(Apartment $apartment): array
    {
        return Arr::only($apartment->toArray(), [
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
        ]);
    }

    private function hasMeaningfulDiff(?array $oldValues, array $newValues): bool
    {
        if ($oldValues === null) {
            return true;
        }

        $keys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        foreach ($keys as $key) {
            $old = $this->normalizeDiffValue($oldValues[$key] ?? null);
            $new = $this->normalizeDiffValue($newValues[$key] ?? null);
            if ($old !== $new) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDiffValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function resolveEnumValue(array $enumMap, string $fieldCode, mixed $rawValue): ?string
    {
        $raw = $this->toNullableString($rawValue);
        if ($raw === null) {
            return null;
        }

        return $enumMap[$fieldCode][$raw] ?? $raw;
    }

    private function normalizeTextKey(string $value): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function toNullableDateTime(mixed $value): ?Carbon
    {
        $string = $this->toNullableString($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string);
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

    private function extractFirstInt(mixed $value): ?int
    {
        $string = $this->toNullableString($value);
        if ($string === null) {
            return null;
        }
        if (is_numeric($string)) {
            return (int) $string;
        }
        if (preg_match('/\d+/', $string, $matches) === 1) {
            return (int) $matches[0];
        }

        return null;
    }

    private function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->toNullableString($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
