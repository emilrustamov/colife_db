<?php

namespace App\Services\Contracts;

interface EntityProfile
{
    public function entity(): string;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{processed:int, created:int, updated:int, successful:int, skipped:int, failed:int, failed_ids:list<int|string>}
     */
    public function syncBatch(array $items): array;

    public function syncOne(int $bitrixId): bool;

    public function markDeleted(int $bitrixId): int;
}
