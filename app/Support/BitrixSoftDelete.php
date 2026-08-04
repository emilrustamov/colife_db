<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class BitrixSoftDelete
{
    /**
     * Soft-mark local rows missing from Bitrix as deleted (never hard-delete).
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $presentBitrixIds
     */
    public static function markMissing(string $modelClass, array $presentBitrixIds, ?Carbon $now = null): int
    {
        $now ??= now();
        $query = $modelClass::query()->where('is_deleted', false);

        if ($presentBitrixIds === []) {
            return $query->update([
                'is_deleted' => true,
                'last_synced_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $query
            ->whereNotIn('bitrix_id', $presentBitrixIds)
            ->update([
                'is_deleted' => true,
                'last_synced_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
