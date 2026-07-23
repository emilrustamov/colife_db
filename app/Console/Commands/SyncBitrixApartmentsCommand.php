<?php

namespace App\Console\Commands;

use App\Models\Apartment;
use App\Models\Contact;
use App\Services\BitrixRestClient;
use App\Support\BitrixSoftDeleteReconciler;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SyncBitrixApartmentsCommand extends Command
{
    protected $signature = 'bitrix:sync-apartments {--sync-stages=1}';

    protected $description = 'Sync apartments and apartment stages from Bitrix24';

    private const APARTMENTS_ENTITY_TYPE_ID = 144;

    /**
     * @var list<string>
     */
    private const APARTMENT_SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'contactId',
        'createdTime',
        'updatedTime',
        'ufCrm6_1682232363193',
        'ufCrm6_1707234311507',
        'ufCrm6_1718821717',
        'ufCrm6_1682232863625',
        'ufCrm6_1682233481671',
        'ufCrm6_1682232312628',
        'ufCrm6_1682238902770',
        'ufCrm6_1689258811986',
        'ufCrm6Adresslink',
        'ufCrm6_1697722222483',
        'ufCrm6_1724248916',
        'ufCrm6_1724247642',
        'ufCrm6_1682235809295',
        'ufCrm6_1686728251990',
        'ufCrm6_1715776920',
        'ufCrm6_1715777017',
        'ufCrm6_1715777650',
        'ufCrm6_1715777670',
        'ufCrm6_1720794204',
        'ufCrm6_1721897304',
        'ufCrm6_1736951470242',
        'ufCrm6_1753278068179',
    ];

    public function handle(BitrixRestClient $bitrixRestClient): int
    {
        $this->info('Bitrix apartments sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $this->syncStages($bitrixRestClient, $now);
                $this->info(sprintf('Apartment stages synced: %d', $syncedStages));
            }

            $enumMap = $this->loadFieldEnumMap($bitrixRestClient);
            $stageIdMap = $this->loadStageIdMap();
            $contactIdMap = $this->loadContactIdMap();
            $buildingIdMap = $this->loadBuildingIdMap();
            $apartmentTypeIdMap = $this->loadApartmentTypeIdMap();
            $metroStationIdMap = $this->loadMetroStationIdMap();

            $result = $this->syncApartments(
                $bitrixRestClient,
                $enumMap,
                $stageIdMap,
                $contactIdMap,
                $buildingIdMap,
                $apartmentTypeIdMap,
                $metroStationIdMap,
                $now
            );

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d, marked deleted: %d.',
                $result['total'],
                $result['successful'],
                $result['failed'],
                $result['marked_deleted']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed apartment ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix apartments sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function syncStages(BitrixRestClient $bitrixRestClient, Carbon $now): int
    {
        $categoriesResponse = $bitrixRestClient->postJson('crm.category.list.json', [
            'entityTypeId' => self::APARTMENTS_ENTITY_TYPE_ID,
        ]);
        $categories = data_get($categoriesResponse, 'result.categories', data_get($categoriesResponse, 'result', []));
        if (! is_array($categories)) {
            return 0;
        }

        $synced = 0;
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0) {
                continue;
            }

            $pipelineName = $this->toNullableString($category['name'] ?? null) ?? 'Apartments';
            DB::table('pipelines')->upsert([
                [
                    'entity_type' => 'apartment',
                    'bitrix_id' => $categoryId,
                    'name' => $pipelineName,
                    'sort' => (int) ($category['sort'] ?? 500),
                    'bitrix_created_at' => null,
                    'bitrix_updated_at' => null,
                    'last_synced_at' => $now,
                    'is_deleted' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ], ['bitrix_id'], ['entity_type', 'name', 'sort', 'last_synced_at', 'is_deleted', 'updated_at']);

            $pipelineId = (int) DB::table('pipelines')->where('bitrix_id', $categoryId)->value('id');
            if ($pipelineId <= 0) {
                continue;
            }

            $entityId = sprintf('DYNAMIC_%d_STAGE_%d', self::APARTMENTS_ENTITY_TYPE_ID, $categoryId);
            $statusesResponse = $bitrixRestClient->postJson('crm.status.list.json', [
                'filter' => [
                    'ENTITY_ID' => $entityId,
                ],
            ]);
            $statuses = data_get($statusesResponse, 'result', []);
            if (! is_array($statuses)) {
                continue;
            }

            $rows = [];
            foreach ($statuses as $status) {
                if (! is_array($status)) {
                    continue;
                }

                $bitrixStageId = trim((string) ($status['STATUS_ID'] ?? ''));
                if ($bitrixStageId === '') {
                    continue;
                }

                $rows[] = [
                    'entity_type' => 'apartment',
                    'pipeline_id' => $pipelineId,
                    'bitrix_stage_id' => $bitrixStageId,
                    'name' => $this->toNullableString($status['NAME'] ?? null) ?? $bitrixStageId,
                    'sort' => (int) ($status['SORT'] ?? 500),
                    'is_success' => str_ends_with($bitrixStageId, ':SUCCESS'),
                    'is_fail' => str_ends_with($bitrixStageId, ':FAIL'),
                    'is_deleted' => false,
                    'bitrix_created_at' => null,
                    'bitrix_updated_at' => null,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('stages')->upsert(
                    $rows,
                    ['bitrix_stage_id'],
                    ['entity_type', 'pipeline_id', 'name', 'sort', 'is_success', 'is_fail', 'is_deleted', 'last_synced_at', 'updated_at']
                );
                $synced += count($rows);
            }
        }

        return $synced;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function loadFieldEnumMap(BitrixRestClient $bitrixRestClient): array
    {
        $response = $bitrixRestClient->postJson('crm.item.fields.json', [
            'entityTypeId' => self::APARTMENTS_ENTITY_TYPE_ID,
        ]);
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

            $fieldMap = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $id = trim((string) ($item['ID'] ?? ''));
                $value = $this->toNullableString($item['VALUE'] ?? null);
                if ($id === '' || $value === null) {
                    continue;
                }

                $fieldMap[$id] = $value;
            }

            if ($fieldMap !== []) {
                $map[(string) $fieldCode] = $fieldMap;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function loadStageIdMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', 'apartment')
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $key) => [(string) $key => (int) $id])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function loadContactIdMap(): array
    {
        return Contact::query()
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId) => [(int) $bitrixId => (string) $id])
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
            ->mapWithKeys(static fn ($id, $enumId) => [(int) $enumId => (int) $id])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function loadMetroStationIdMap(): array
    {
        return DB::table('metro_stations')
            ->pluck('id', 'bitrix_id')
            ->mapWithKeys(static fn ($id, $bitrixId) => [(int) $bitrixId => (int) $id])
            ->all();
    }

    /**
     * @param  array<string, array<string, string>>  $enumMap
     * @param  array<string, int>  $stageIdMap
     * @param  array<int, string>  $contactIdMap
     * @param  array<string, string>  $buildingIdMap
     * @param  array<int, int>  $apartmentTypeIdMap
     * @param  array<int, int>  $metroStationIdMap
     * @return array{total:int,successful:int,failed:int,failed_ids:list<int|string>,marked_deleted:int}
     */
    private function syncApartments(
        BitrixRestClient $bitrixRestClient,
        array $enumMap,
        array $stageIdMap,
        array $contactIdMap,
        array $buildingIdMap,
        array $apartmentTypeIdMap,
        array $metroStationIdMap,
        Carbon $now
    ): array {
        $start = 0;
        $total = 0;
        $successful = 0;
        $failedIds = [];
        $seenBitrixIds = [];

        while (true) {
            $response = $bitrixRestClient->postJson('crm.item.list.json', [
                'entityTypeId' => self::APARTMENTS_ENTITY_TYPE_ID,
                'select' => self::APARTMENT_SELECT_FIELDS,
                'start' => $start,
            ]);
            $items = data_get($response, 'result.items', []);
            if (! is_array($items) || $items === []) {
                break;
            }

            $bitrixIds = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $bitrixId = (int) ($item['id'] ?? 0);
                if ($bitrixId > 0) {
                    $bitrixIds[] = $bitrixId;
                    $seenBitrixIds[] = $bitrixId;
                }
            }
            $existingIdMap = Apartment::query()
                ->whereIn('bitrix_id', $bitrixIds)
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId) => [(int) $bitrixId => (string) $id])
                ->all();

            $rows = [];
            foreach ($items as $item) {
                $total++;

                try {
                    if (! is_array($item)) {
                        throw new \RuntimeException('Invalid payload row.');
                    }

                    $rows[] = $this->normalizeApartmentRow(
                        $item,
                        $existingIdMap,
                        $enumMap,
                        $stageIdMap,
                        $contactIdMap,
                        $buildingIdMap,
                        $apartmentTypeIdMap,
                        $metroStationIdMap,
                        $now
                    );
                    $successful++;
                } catch (Throwable) {
                    $failedIds[] = is_array($item) ? ($item['id'] ?? 'unknown') : 'unknown';
                }
            }

            if ($rows !== []) {
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
            }

            $this->info(sprintf(
                'Batch synced. Total processed: %d, successful: %d, failed: %d.',
                $total,
                $successful,
                count($failedIds)
            ));

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        $markedDeleted = 0;
        if ($total > 0) {
            $markedDeleted = BitrixSoftDeleteReconciler::markMissingAsDeleted(
                Apartment::class,
                array_values(array_unique($seenBitrixIds)),
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
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $existingIdMap
     * @param  array<string, array<string, string>>  $enumMap
     * @param  array<string, int>  $stageIdMap
     * @param  array<int, string>  $contactIdMap
     * @param  array<string, string>  $buildingIdMap
     * @param  array<int, int>  $apartmentTypeIdMap
     * @param  array<int, int>  $metroStationIdMap
     * @return array<string, mixed>
     */
    private function normalizeApartmentRow(
        array $item,
        array $existingIdMap,
        array $enumMap,
        array $stageIdMap,
        array $contactIdMap,
        array $buildingIdMap,
        array $apartmentTypeIdMap,
        array $metroStationIdMap,
        Carbon $now
    ): array {
        $bitrixId = (int) ($item['id'] ?? 0);
        if ($bitrixId <= 0) {
            throw new \RuntimeException('Invalid apartment id.');
        }

        $stageCode = trim((string) ($item['stageId'] ?? ''));
        $buildingName = $this->toNullableString($item['ufCrm6_1682232363193'] ?? null);
        $contactBitrixId = (int) ($item['contactId'] ?? 0);
        $apartmentTypeBitrixEnumId = (int) ($item['ufCrm6_1682232863625'] ?? 0);
        $metroStationBitrixId = (int) ($item['ufCrm6_1682233481671'] ?? 0);
        $propertyModeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1736951470242', $item['ufCrm6_1736951470242'] ?? null);
        $rentalTypeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1753278068179', $item['ufCrm6_1753278068179'] ?? null);
        $transportTypeLabel = $this->resolveEnumValue($enumMap, 'ufCrm6_1689258811986', $item['ufCrm6_1689258811986'] ?? null);

        return [
            'id' => $existingIdMap[$bitrixId] ?? (string) Str::uuid(),
            'bitrix_id' => $bitrixId,
            'title' => $this->toNullableString($item['title'] ?? null),
            'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
            'building_id' => $buildingName !== null ? ($buildingIdMap[$this->normalizeTextKey($buildingName)] ?? null) : null,
            'landlord_contact_id' => $contactBitrixId > 0 ? ($contactIdMap[$contactBitrixId] ?? null) : null,
            'metro_station_id' => $metroStationBitrixId > 0 ? ($metroStationIdMap[$metroStationBitrixId] ?? null) : null,
            'apartment_type_id' => $apartmentTypeBitrixEnumId > 0 ? ($apartmentTypeIdMap[$apartmentTypeBitrixEnumId] ?? null) : null,
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

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function toNullableDateTime(mixed $value): ?Carbon
    {
        $string = $this->toNullableString($value);
        if ($string === null) {
            return null;
        }

        return Carbon::parse($string);
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
