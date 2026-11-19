<?php

namespace App\Services;

use App\Models\CleaningStaff;
use App\Support\BitrixSoftDelete;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CleaningStaffSync
{
    private const IBLOCK_TYPE_ID = 'lists';

    public function __construct(
        private readonly BitrixRest $bitrixRest,
        private readonly BitrixOAuth $oauth,
    ) {}

    /**
     * Sync TR Bitrix universal list (Cleaning staff) into local cleaning_staff.
     *
     * @return array{total:int, successful:int, failed:int, failed_ids:list<int|string>, marked_deleted:int}
     */
    public function sync(): array
    {
        $domain = $this->oauth->normalizeDomain((string) config('services.b24_tr.portal_domain'));
        if ($domain === '') {
            throw new RuntimeException('TR portal domain is not configured.');
        }

        $iblockId = (int) config('services.cleaning.staff_list_iblock_id', 0);
        if ($iblockId <= 0) {
            throw new RuntimeException('CLEANING staff list iblock id is not configured.');
        }

        $items = $this->fetchAllElements($domain, $iblockId);
        $elements = [];
        foreach ($items as $item) {
            $elements[] = [
                'id' => (int) ($item['ID'] ?? 0),
                'name' => (string) ($item['NAME'] ?? ''),
                'sort' => (int) ($item['SORT'] ?? 500),
            ];
        }

        $result = $this->applyElements($elements);
        Log::channel('bitrix_cleaning')->info('CLEANING_STAFF_SYNC_OK', $result + [
            'iblock_id' => $iblockId,
            'source' => 'oauth',
        ]);

        return $result;
    }

    /**
     * Upsert normalized staff elements and soft-delete missing ones.
     *
     * @param  list<array{id?:int|string, name?:string, sort?:int|string}>  $elements
     * @return array{total:int, successful:int, failed:int, failed_ids:list<int|string>, marked_deleted:int, names:list<string>}
     */
    public function applyElements(array $elements): array
    {
        $now = now();
        $total = 0;
        $successful = 0;
        $failedIds = [];
        $rows = [];
        $bitrixIds = [];

        foreach ($elements as $item) {
            $total++;
            $bitrixId = (int) ($item['id'] ?? $item['ID'] ?? 0);
            $name = trim((string) ($item['name'] ?? $item['NAME'] ?? ''));

            try {
                if ($bitrixId <= 0 || $name === '') {
                    $failedIds[] = $item['id'] ?? $item['ID'] ?? 'unknown';
                    continue;
                }

                $sort = (int) ($item['sort'] ?? $item['SORT'] ?? 500);
                if ($sort <= 0) {
                    $sort = 500;
                }

                $bitrixIds[] = $bitrixId;
                $rows[] = [
                    'bitrix_id' => $bitrixId,
                    'name' => mb_substr($name, 0, 255),
                    'sort' => $sort,
                    'is_deleted' => false,
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $successful++;
            } catch (Throwable $e) {
                $failedIds[] = $item['id'] ?? $item['ID'] ?? 'unknown';
                Log::channel('bitrix_cleaning')->warning('CLEANING_STAFF_SYNC_ITEM_FAILED', [
                    'bitrix_id' => $item['id'] ?? $item['ID'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            CleaningStaff::query()->upsert(
                $chunk,
                ['bitrix_id'],
                ['name', 'sort', 'is_deleted', 'last_synced_at', 'updated_at']
            );
        }

        $markedDeleted = 0;
        if ($bitrixIds !== []) {
            $markedDeleted = BitrixSoftDelete::markMissing(
                CleaningStaff::class,
                $bitrixIds,
                $now
            );
        }

        return [
            'total' => $total,
            'successful' => $successful,
            'failed' => count($failedIds),
            'failed_ids' => $failedIds,
            'marked_deleted' => $markedDeleted,
            'names' => CleaningStaff::namesForForm(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllElements(string $domain, int $iblockId): array
    {
        $start = 0;
        $items = [];

        while (true) {
            $response = $this->bitrixRest->postJson('lists.element.get', [
                'IBLOCK_TYPE_ID' => self::IBLOCK_TYPE_ID,
                'IBLOCK_ID' => $iblockId,
                'FILTER' => [
                    'ACTIVE' => 'Y',
                ],
                'start' => $start,
            ], $domain);

            $batch = data_get($response, 'result', []);
            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = data_get($response, 'next');
            if (! is_numeric($next)) {
                break;
            }

            $start = (int) $next;
        }

        return $items;
    }
}
