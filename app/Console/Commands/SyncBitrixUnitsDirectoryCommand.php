<?php

namespace App\Console\Commands;

use App\Models\Apartment;
use App\Models\Unit;
use App\Services\BitrixRestClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SyncBitrixUnitsDirectoryCommand extends Command
{
    protected $signature = 'bitrix:sync-units {--sync-stages=1}';

    protected $description = 'Sync units directory and unit stages from Bitrix24';

    private const UNITS_ENTITY_TYPE_ID = 167;

    private const UNITS_CATEGORY_ID = 12;

    /**
     * @var list<string>
     */
    private const UNIT_SELECT_FIELDS = [
        'id',
        'title',
        'stageId',
        'createdTime',
        'updatedTime',
        'ufCrm8_1684429208',
        'ufCrm8_1698838056004',
        'ufCrm6_1707234311507',
    ];

    public function handle(BitrixRestClient $bitrixRestClient): int
    {
        $this->info('Bitrix units directory sync started...');
        $now = now();

        try {
            if ($this->option('sync-stages') !== '0') {
                $syncedStages = $this->syncStages($bitrixRestClient, $now);
                $this->info(sprintf('Unit stages synced: %d', $syncedStages));
            }

            $stageIdMap = $this->loadStageIdMap();
            $apartmentIdByBitrixId = Apartment::query()
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all();

            $result = $this->syncUnits($bitrixRestClient, $stageIdMap, $apartmentIdByBitrixId, $now);

            $this->info(sprintf(
                'Completed. Total: %d, successful: %d, failed: %d.',
                $result['total'],
                $result['successful'],
                $result['failed']
            ));

            if ($result['failed'] > 0) {
                $this->warn('Failed unit ids: '.implode(', ', $result['failed_ids']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Bitrix units directory sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function syncStages(BitrixRestClient $bitrixRestClient, Carbon $now): int
    {
        $categoriesResponse = $bitrixRestClient->postJson('crm.category.list.json', [
            'entityTypeId' => self::UNITS_ENTITY_TYPE_ID,
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

            DB::table('pipelines')->upsert([
                [
                    'entity_type' => 'unit',
                    'bitrix_id' => $categoryId,
                    'name' => trim((string) ($category['name'] ?? 'Units')),
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

            $statusesResponse = $bitrixRestClient->postJson('crm.status.list.json', [
                'filter' => [
                    'ENTITY_ID' => sprintf('DYNAMIC_%d_STAGE_%d', self::UNITS_ENTITY_TYPE_ID, $categoryId),
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
                    'entity_type' => 'unit',
                    'pipeline_id' => $pipelineId,
                    'bitrix_stage_id' => $bitrixStageId,
                    'name' => trim((string) ($status['NAME'] ?? $bitrixStageId)),
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
     * @return array<string, int>
     */
    private function loadStageIdMap(): array
    {
        return DB::table('stages')
            ->where('entity_type', 'unit')
            ->pluck('id', 'bitrix_stage_id')
            ->mapWithKeys(static fn ($id, $stageId): array => [(string) $stageId => (int) $id])
            ->all();
    }

    /**
     * @param  array<string, int>  $stageIdMap
     * @param  array<int, string>  $apartmentIdByBitrixId
     * @return array{total:int,successful:int,failed:int,failed_ids:list<int|string>}
     */
    private function syncUnits(
        BitrixRestClient $bitrixRestClient,
        array $stageIdMap,
        array $apartmentIdByBitrixId,
        Carbon $now
    ): array {
        $start = 0;
        $total = 0;
        $successful = 0;
        $failedIds = [];

        while (true) {
            $response = $bitrixRestClient->postJson('crm.item.list.json', [
                'entityTypeId' => self::UNITS_ENTITY_TYPE_ID,
                'select' => self::UNIT_SELECT_FIELDS,
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
                }
            }

            $existingIds = Unit::query()
                ->whereIn('bitrix_id', $bitrixIds)
                ->pluck('id', 'bitrix_id')
                ->mapWithKeys(static fn ($id, $bitrixId): array => [(int) $bitrixId => (string) $id])
                ->all();

            $rows = [];
            foreach ($items as $item) {
                $total++;

                try {
                    if (! is_array($item)) {
                        throw new \RuntimeException('Invalid unit row.');
                    }

                    $bitrixId = (int) ($item['id'] ?? 0);
                    if ($bitrixId <= 0) {
                        throw new \RuntimeException('Invalid unit ID.');
                    }

                    $apartIds = $item['ufCrm8_1684429208'] ?? null;
                    $apartBitrixId = $this->extractFirstInt($apartIds);
                    if ($apartBitrixId === null) {
                        throw new \RuntimeException('Apartment link is empty.');
                    }

                    $apartmentId = $apartmentIdByBitrixId[$apartBitrixId] ?? null;
                    if (! is_string($apartmentId) || $apartmentId === '') {
                        throw new \RuntimeException('Apartment not found locally.');
                    }

                    $stageCode = trim((string) ($item['stageId'] ?? ''));
                    $internalNumber = $this->firstNonEmptyString(
                        $item['ufCrm8_1698838056004'] ?? null,
                        $item['ufCrm6_1707234311507'] ?? null
                    );

                    $rows[] = [
                        'id' => $existingIds[$bitrixId] ?? (string) Str::uuid(),
                        'bitrix_id' => $bitrixId,
                        'apartment_id' => $apartmentId,
                        'title' => $this->toNullableString($item['title'] ?? null),
                        'stage_id' => $stageCode !== '' ? ($stageIdMap[$stageCode] ?? null) : null,
                        'internal_number' => $internalNumber,
                        'is_deleted' => false,
                        'bitrix_created_at' => $this->toNullableDateTime($item['createdTime'] ?? null),
                        'bitrix_updated_at' => $this->toNullableDateTime($item['updatedTime'] ?? null),
                        'last_synced_at' => $now,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ];
                    $successful++;
                } catch (Throwable) {
                    $failedIds[] = is_array($item) ? ($item['id'] ?? 'unknown') : 'unknown';
                }
            }

            if ($rows !== []) {
                Unit::query()->upsert(
                    $rows,
                    ['bitrix_id'],
                    [
                        'apartment_id',
                        'title',
                        'stage_id',
                        'internal_number',
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

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
        ];
    }

    private function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
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
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $first = reset($value);

            return is_numeric($first) ? (int) $first : null;
        }

        return is_numeric($value) ? (int) $value : null;
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
