<?php

namespace App\Services\Prices;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared unit_stays scopes for prices calculate / report / stats.
 */
final class UnitStayQuery
{
    public static function base(): Builder
    {
        return DB::table('unit_stays as us')
            ->join('units as u', 'u.id', '=', 'us.unit_id')
            ->where('us.is_deleted', false)
            ->where('u.is_deleted', false);
    }

    /**
     * Stays that enter monthly occupancy math (dates, opportunity, not fail).
     */
    public static function valid(): Builder
    {
        $query = self::base()
            ->leftJoin('stages as st', 'st.id', '=', 'us.stage_id');
        self::applyValidConstraints($query);

        return $query;
    }

    /**
     * Validity filters on an already-joined unit_stays / units / stages query.
     */
    public static function applyValidConstraints(
        Builder $query,
        string $stay = 'us',
        string $unit = 'u',
        string $stage = 'st',
    ): void {
        $query
            ->whereNotNull("{$stay}.check_in_date")
            ->whereNotNull("{$stay}.check_out_date")
            ->where("{$stay}.opportunity", '>', 0)
            ->whereNotNull("{$unit}.bitrix_id")
            ->where(function (Builder $q) use ($stage): void {
                $q->whereNull("{$stage}.id")
                    ->orWhere("{$stage}.is_fail", false);
            })
            ->whereColumn("{$stay}.check_out_date", '>=', "{$stay}.check_in_date");
    }

    /**
     * @param  list<string|int>  $unitBitrixIds
     * @return list<array<string, mixed>>
     */
    public static function forUnitsInRange(array $unitBitrixIds, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        if ($unitBitrixIds === []) {
            return [];
        }

        return self::valid()
            ->whereIn('u.bitrix_id', $unitBitrixIds)
            ->where('us.check_in_date', '<=', $rangeEnd->toDateString())
            ->where('us.check_out_date', '>=', $rangeStart->toDateString())
            ->orderBy('u.bitrix_id')
            ->orderBy('us.check_in_date')
            ->orderBy('us.check_out_date')
            ->orderBy('us.bitrix_id')
            ->get([
                'us.bitrix_id',
                'us.title',
                'u.bitrix_id as unit_id',
                'us.check_in_date as start_date',
                'us.check_out_date as end_date',
                'us.opportunity',
                'us.contract_type',
            ])
            ->map(static fn ($row): array => [
                'bitrix_id' => $row->bitrix_id,
                'title' => $row->title,
                'unit_id' => (string) $row->unit_id,
                'start_date' => (string) $row->start_date,
                'end_date' => (string) $row->end_date,
                'opportunity' => (float) $row->opportunity,
                'contract_type' => $row->contract_type,
            ])
            ->all();
    }
}
