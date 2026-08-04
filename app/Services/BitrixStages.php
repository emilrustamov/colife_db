<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BitrixStages
{
    /**
     * Sync CRM SPA categories and stages into local pipelines/stages tables.
     */
    public function sync(
        BitrixRest $bitrixRestClient,
        int $entityTypeId,
        string $entityType,
        string $defaultPipelineName,
        Carbon $now
    ): int {
        $categoriesResponse = $bitrixRestClient->postJson('crm.category.list.json', [
            'entityTypeId' => $entityTypeId,
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

            $pipelineName = trim((string) ($category['name'] ?? ''));
            if ($pipelineName === '') {
                $pipelineName = $defaultPipelineName;
            }

            DB::table('pipelines')->upsert([
                [
                    'entity_type' => $entityType,
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

            $statusesResponse = $bitrixRestClient->postJson('crm.status.list.json', [
                'filter' => [
                    'ENTITY_ID' => sprintf('DYNAMIC_%d_STAGE_%d', $entityTypeId, $categoryId),
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

                $stageName = trim((string) ($status['NAME'] ?? ''));
                if ($stageName === '') {
                    $stageName = $bitrixStageId;
                }

                $rows[] = [
                    'entity_type' => $entityType,
                    'pipeline_id' => $pipelineId,
                    'bitrix_stage_id' => $bitrixStageId,
                    'name' => $stageName,
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
}
