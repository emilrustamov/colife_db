<?php

namespace App\Services;

use RuntimeException;

class MoveFailedLeads
{
    public const CATEGORY_ID = 52;

    public const STAGE_QUALIFIED = 'C52:NEW';

    public const STAGE_POSTPONED = 'C52:PREPARATION';

    public const STAGE_FAILED = 'C52:UC_07GCQU';

    public const DATE_FROM = null;

    public const DATE_TO = '2026-09-12T23:59:59';

    private const CHUNK_SIZE = 5;

    public function __construct(
        private readonly BitrixWebhook $webhookClient,
    ) {}

    /**
     * Count deals matching move filter.
     */
    public function count(): int
    {
        $response = $this->webhookClient->callRaw('crm.deal.list', [
            'filter' => $this->filter(),
            'select' => ['ID'],
            'start' => 0,
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'crm.deal.list');

        return (int) data_get($response, 'total', 0);
    }

    /**
     * Move one page of matching deals to Failed Leads via small Bitrix batches.
     *
     * @return array{moved: int, failed: int, remaining: bool, failed_ids: list<int>, throttled: bool}
     */
    public function moveBatch(int $limit = 15, int $sleepMs = 0): array
    {
        $limit = max(1, min(50, $limit));

        $response = $this->webhookClient->callRaw('crm.deal.list', [
            'filter' => $this->filter(),
            'select' => ['ID'],
            'order' => ['ID' => 'ASC'],
            'start' => 0,
        ], $this->webhookClient->diskWebhook());

        $this->assertOk($response, 'crm.deal.list');

        $items = data_get($response, 'result', []);
        if (! is_array($items) || $items === []) {
            return ['moved' => 0, 'failed' => 0, 'remaining' => false, 'failed_ids' => [], 'throttled' => false];
        }

        $ids = [];
        foreach ($items as $deal) {
            if (! is_array($deal)) {
                continue;
            }

            $id = (int) ($deal['ID'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $ids[] = $id;
            if (count($ids) >= $limit) {
                break;
            }
        }

        if ($ids === []) {
            return ['moved' => 0, 'failed' => 0, 'remaining' => false, 'failed_ids' => [], 'throttled' => false];
        }

        $moved = 0;
        $failed = 0;
        $failedIds = [];
        $throttled = false;

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            [$chunkMoved, $chunkFailed, $chunkFailedIds, $chunkThrottled] = $this->updateStagesChunk($chunk);
            $moved += $chunkMoved;
            $failed += $chunkFailed;
            $failedIds = array_merge($failedIds, $chunkFailedIds);

            if ($chunkThrottled) {
                $throttled = true;
                break;
            }

            $pauseMs = max(2500, $sleepMs);
            usleep($pauseMs * 1000);
        }

        $remaining = count($items) > count($ids)
            || is_numeric(data_get($response, 'next'))
            || ((int) data_get($response, 'total', 0) > count($ids))
            || $throttled;

        return [
            'moved' => $moved,
            'failed' => $failed,
            'remaining' => $remaining && ($moved > 0 || $throttled || $failed > 0),
            'failed_ids' => $failedIds,
            'throttled' => $throttled,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filter(): array
    {
        $filter = [
            'CATEGORY_ID' => self::CATEGORY_ID,
            'STAGE_ID' => self::STAGE_QUALIFIED,
            '<=DATE_CREATE' => self::DATE_TO,
        ];

        if (is_string(self::DATE_FROM) && self::DATE_FROM !== '') {
            $filter['>=DATE_CREATE'] = self::DATE_FROM;
        }

        return $filter;
    }

    /**
     * @param  list<int>  $dealIds
     * @return array{0: int, 1: int, 2: list<int>, 3: bool}
     */
    private function updateStagesChunk(array $dealIds): array
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->callBatch($dealIds);
            $topError = (string) data_get($response, 'error', '');

            if (in_array($topError, ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true)) {
                $this->waitForReset($response, 15);

                continue;
            }

            if ($topError === 'INTERNAL_SERVER_ERROR') {
                return [...$this->updateStagesOneByOne($dealIds), false];
            }

            if ($topError !== '') {
                throw new RuntimeException('batch: '.(string) (data_get($response, 'error_description') ?: $topError));
            }

            if ($this->batchHasOperatingLimit($response)) {
                $this->waitForReset($response, 15);

                continue;
            }

            [$moved, $failed, $failedIds] = $this->tallyBatchResponse($dealIds, $response);

            return [$moved, $failed, $failedIds, false];
        }

        return [0, count($dealIds), $dealIds, true];
    }

    /**
     * @param  list<int>  $dealIds
     * @return array<string, mixed>
     */
    private function callBatch(array $dealIds): array
    {
        $cmd = [];
        foreach ($dealIds as $dealId) {
            $cmd['d'.$dealId] = 'crm.deal.update?id='.$dealId.'&fields[STAGE_ID]='.rawurlencode(self::STAGE_FAILED);
        }

        return $this->webhookClient->callRaw('batch', [
            'halt' => 0,
            'cmd' => $cmd,
        ], $this->webhookClient->diskWebhook(), 90);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function batchHasOperatingLimit(array $response): bool
    {
        $errorMap = data_get($response, 'result.result_error', []);
        if (! is_array($errorMap) || $errorMap === []) {
            return false;
        }

        foreach ($errorMap as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = (string) ($error['error'] ?? '');
            if (in_array($code, ['OPERATION_TIME_LIMIT', 'QUERY_LIMIT_EXCEEDED'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function waitForReset(array $response, int $fallbackSeconds): void
    {
        $resetAt = (int) data_get($response, 'time.operating_reset_at', 0);
        $wait = $fallbackSeconds;

        if ($resetAt > time()) {
            $wait = max($fallbackSeconds, $resetAt - time() + 2);
        }

        sleep(min(120, $wait));
    }

    /**
     * @param  list<int>  $dealIds
     * @param  array<string, mixed>  $response
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function tallyBatchResponse(array $dealIds, array $response): array
    {
        $resultMap = data_get($response, 'result.result', []);
        $errorMap = data_get($response, 'result.result_error', []);
        if (! is_array($resultMap)) {
            $resultMap = [];
        }
        if (! is_array($errorMap)) {
            $errorMap = [];
        }

        $moved = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($dealIds as $dealId) {
            $key = 'd'.$dealId;
            if (isset($errorMap[$key]) && is_array($errorMap[$key])) {
                $failed++;
                $failedIds[] = $dealId;

                continue;
            }

            if (array_key_exists($key, $resultMap)) {
                $moved++;

                continue;
            }

            $failed++;
            $failedIds[] = $dealId;
        }

        return [$moved, $failed, $failedIds];
    }

    /**
     * @param  list<int>  $dealIds
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function updateStagesOneByOne(array $dealIds): array
    {
        $moved = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($dealIds as $dealId) {
            $response = $this->webhookClient->callRaw('crm.deal.update', [
                'id' => $dealId,
                'fields' => ['STAGE_ID' => self::STAGE_FAILED],
            ], $this->webhookClient->diskWebhook(), 60);

            $error = (string) data_get($response, 'error', '');
            if (in_array($error, ['QUERY_LIMIT_EXCEEDED', 'OPERATION_TIME_LIMIT'], true)) {
                $this->waitForReset($response, 15);
                $response = $this->webhookClient->callRaw('crm.deal.update', [
                    'id' => $dealId,
                    'fields' => ['STAGE_ID' => self::STAGE_FAILED],
                ], $this->webhookClient->diskWebhook(), 60);
                $error = (string) data_get($response, 'error', '');
            }

            if ($error !== '' || data_get($response, 'result') !== true) {
                $failed++;
                $failedIds[] = $dealId;

                continue;
            }

            $moved++;
        }

        return [$moved, $failed, $failedIds];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertOk(array $response, string $method): void
    {
        $error = data_get($response, 'error');
        if (is_string($error) && $error !== '') {
            throw new RuntimeException($method.': '.(string) (data_get($response, 'error_description') ?: $error));
        }
    }
}
