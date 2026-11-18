<?php

namespace App\Services\Prices;

use App\Support\Prices\ContractTypeMapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MonthlyReportCalculator
{
    public const CACHE_GENERATION_KEY = 'prices:report_cache_generation';

    public const LOCK_KEY = 'prices:calculate';

    public const STATS_KEY = 'prices:stats';

    /**
     * Rebuild price_monthly_reports from unit_stays.
     *
     * @return array{
     *     processed: int,
     *     total: int,
     *     skipped_no_type: int,
     *     skipped_duplicates: int,
     *     skipped_invalid_days: int,
     *     skipped_errors: int,
     *     reports: int
     * }
     */
    public function rebuild(): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 1800);
        if (! $lock->get()) {
            throw new RuntimeException('prices:calculate already running');
        }

        try {
            return $this->rebuildUnlocked();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{
     *     processed: int,
     *     total: int,
     *     skipped_no_type: int,
     *     skipped_duplicates: int,
     *     skipped_invalid_days: int,
     *     skipped_errors: int,
     *     reports: int
     * }
     */
    private function rebuildUnlocked(): array
    {
        $contracts = UnitStayQuery::valid()
            ->orderBy('u.bitrix_id')
            ->orderBy('us.check_in_date')
            ->orderBy('us.check_out_date')
            ->get([
                'us.check_in_date',
                'us.check_out_date',
                'us.opportunity',
                'us.contract_type',
                'u.bitrix_id as unit_bitrix_id',
            ]);

        $aggregates = [];
        $processed = 0;
        $skippedNoType = 0;
        $skippedDuplicates = 0;
        $skippedInvalidDays = 0;
        $skippedErrors = 0;
        $processedContracts = [];

        foreach ($contracts as $contract) {
            try {
                $contractType = ContractTypeMapper::bucketFromLabel($contract->contract_type);
                if ($contractType === null) {
                    $skippedNoType++;

                    continue;
                }

                $start = Carbon::parse($contract->check_in_date)->startOfDay();
                $end = Carbon::parse($contract->check_out_date)->startOfDay();
                if ($end->lt($start)) {
                    $skippedInvalidDays++;

                    continue;
                }

                $contractKey = $contract->unit_bitrix_id.'|'.$start->toDateString().'|'.$end->toDateString();
                if (isset($processedContracts[$contractKey])) {
                    $skippedDuplicates++;

                    continue;
                }

                $months = $this->buildMonthlyBreakdown(
                    $start,
                    $end,
                    (float) $contract->opportunity,
                    $contractType
                );
                if ($months === []) {
                    $skippedInvalidDays++;

                    continue;
                }

                $unitId = (string) $contract->unit_bitrix_id;
                foreach ($months as $monthKey => $monthData) {
                    $aggKey = $unitId.'|'.$monthKey.'|'.$contractType;
                    if (! isset($aggregates[$aggKey])) {
                        $aggregates[$aggKey] = [
                            'unit_bitrix_id' => $unitId,
                            'contract_type' => $contractType,
                            'month_key' => $monthKey,
                            'year' => $monthData['year'],
                            'month_num' => $monthData['month_num'],
                            'occupied_days' => 0,
                            'total_revenue' => 0.0,
                        ];
                    }
                    $aggregates[$aggKey]['occupied_days'] += $monthData['days'];
                    $aggregates[$aggKey]['total_revenue'] += $monthData['revenue'];
                }

                $processedContracts[$contractKey] = true;
                $processed++;
            } catch (Throwable) {
                $skippedErrors++;
            }
        }

        $now = now();
        $rows = [];
        foreach ($aggregates as $row) {
            $days = (int) $row['occupied_days'];
            $revenue = round((float) $row['total_revenue'], 2);
            $rows[] = [
                'unit_bitrix_id' => $row['unit_bitrix_id'],
                'contract_type' => $row['contract_type'],
                'month_key' => $row['month_key'],
                'year' => $row['year'],
                'month_num' => $row['month_num'],
                'occupied_days' => $days,
                'total_revenue' => $revenue,
                'avg_price_per_day' => $days > 0 ? round($revenue / $days, 2) : 0,
                'calculated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            DB::table('price_monthly_reports')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('price_monthly_reports')->insert($chunk);
            }
        });

        Cache::forget(self::STATS_KEY);
        Cache::forever(self::CACHE_GENERATION_KEY, (string) now()->timestamp);

        return [
            'processed' => $processed,
            'total' => $contracts->count(),
            'skipped_no_type' => $skippedNoType,
            'skipped_duplicates' => $skippedDuplicates,
            'skipped_invalid_days' => $skippedInvalidDays,
            'skipped_errors' => $skippedErrors,
            'reports' => count($rows),
        ];
    }

    public function reportCacheGeneration(): string
    {
        return (string) Cache::get(self::CACHE_GENERATION_KEY, '0');
    }

    /**
     * LONG 30–59 days: only the start month counts.
     */
    public static function skipLongNonStartMonth(string $contractType, int $totalDays, bool $isStartMonth): bool
    {
        return $contractType === ContractTypeMapper::LONG
            && $totalDays >= 30
            && $totalDays < 60
            && ! $isStartMonth;
    }

    /**
     * @return array<string, array{days: int, revenue: float, year: int, month_num: int}>
     */
    public function buildMonthlyBreakdown(Carbon $startDate, Carbon $endDate, float $monthlyPrice, string $contractType): array
    {
        $totalDays = $startDate->diff($endDate)->days + 1;
        if ($totalDays <= 0) {
            return [];
        }

        $result = [];
        $currentMonth = $startDate->copy()->startOfMonth();
        $isFirstMonth = true;

        while ($currentMonth->lte($endDate)) {
            if (self::skipLongNonStartMonth($contractType, $totalDays, $isFirstMonth)) {
                $currentMonth->addMonth();

                continue;
            }

            $monthKey = $currentMonth->format('Y-m');
            $year = (int) $currentMonth->format('Y');
            $monthNum = (int) $currentMonth->format('m');

            $monthStart = $currentMonth->copy()->startOfMonth();
            $monthEnd = $currentMonth->copy()->endOfMonth();

            $periodStart = $startDate->gt($monthStart) ? $startDate->copy() : $monthStart->copy();
            $periodEnd = $endDate->lt($monthEnd) ? $endDate->copy() : $monthEnd->copy();

            if ($periodStart->lte($periodEnd)) {
                $daysInMonth = $periodStart->diff($periodEnd)->days + 1;
                $daysInCalendarMonth = (int) $monthEnd->format('j');

                if ($daysInMonth > 0) {
                    if (
                        $contractType === ContractTypeMapper::LONG
                        && $totalDays >= 30
                        && $daysInMonth < $daysInCalendarMonth
                        && ! $isFirstMonth
                    ) {
                        $currentMonth->addMonth();

                        continue;
                    }

                    if ($contractType === ContractTypeMapper::LONG) {
                        $revenue = $totalDays >= 30
                            ? $monthlyPrice
                            : ($monthlyPrice / $daysInCalendarMonth) * $daysInMonth;
                    } else {
                        $revenue = ($monthlyPrice / $totalDays) * $daysInMonth;
                    }

                    $result[$monthKey] = [
                        'days' => $daysInMonth,
                        'revenue' => $revenue,
                        'year' => $year,
                        'month_num' => $monthNum,
                    ];
                }
            }

            $isFirstMonth = false;
            $currentMonth->addMonth();
        }

        return $result;
    }
}
