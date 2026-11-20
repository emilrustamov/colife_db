<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BitrixSoftDelete
{
    private const UPDATE_CHUNK = 1000;

    /**
     * Soft-mark local rows missing from Bitrix as deleted (never hard-delete).
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $presentBitrixIds
     */
    public static function markMissing(
        string $modelClass,
        array $presentBitrixIds,
        ?Carbon $now = null,
        ?int $portalId = null
    ): int {
        $now ??= now();
        $query = $modelClass::query()->where('is_deleted', false);

        if ($portalId !== null) {
            $query->where('portal_id', $portalId);
        }

        if ($presentBitrixIds === []) {
            return $query->update([
                'is_deleted' => true,
                'last_synced_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $present = array_fill_keys($presentBitrixIds, true);
        /** @var list<int|string> $missingIds */
        $missingIds = [];

        $query
            ->orderBy('id')
            ->select(['id', 'bitrix_id'])
            ->lazyById(self::UPDATE_CHUNK)
            ->each(function (Model $row) use ($present, &$missingIds): void {
                if (! isset($present[(int) $row->getAttribute('bitrix_id')])) {
                    // Keep UUID/string PKs as-is; casting to int turns them into 0 and
                    // makes MySQL compare uuid `id` to DECIMAL (SQLSTATE[22007]).
                    $missingIds[] = $row->getKey();
                }
            });

        if ($missingIds === []) {
            return 0;
        }

        $marked = 0;
        foreach (array_chunk($missingIds, self::UPDATE_CHUNK) as $chunk) {
            $marked += $modelClass::query()
                ->whereIn('id', $chunk)
                ->update([
                    'is_deleted' => true,
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        return $marked;
    }
}
