<?php

namespace App\Services\Contracts;

interface BitrixEntityProfile
{
    public function entity(): string;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatchItems(array $items): array;

    public function syncSingleItemByBitrixId(int $bitrixId): bool;

    public function markItemDeleted(int $bitrixId): int;
}
