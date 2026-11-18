<?php

namespace App\Services\Prices;

use App\Support\Prices\ContractTypeMapper;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class ReportQueryService
{
    /**
     * @var array<int, string>
     */
    public const MONTH_LABELS = [
        1 => 'Янв', 2 => 'Фев', 3 => 'Мар', 4 => 'Апр',
        5 => 'Май', 6 => 'Июн', 7 => 'Июл', 8 => 'Авг',
        9 => 'Сен', 10 => 'Окт', 11 => 'Ноя', 12 => 'Дек',
    ];

    public function __construct(
        private readonly MonthlyReportCalculator $calculator,
        private readonly XlsxExporter $xlsxExporter,
    ) {}

    /**
     * @return array{
     *     years: list<int>,
     *     districts: list<string>,
     *     buildings: list<string>,
     *     contract_types: array<string, list<array{id: int, name: string}>>,
     *     apartment_types: list<string>,
     *     month_labels: array<int, string>
     * }
     */
    public function meta(): array
    {
        return [
            'years' => DB::table('price_monthly_reports')
                ->where('year', '>=', ReportFilters::MIN_REPORT_YEAR)
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(static fn ($y): int => (int) $y)
                ->all(),
            'districts' => DB::table('districts')
                ->where('is_deleted', false)
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('sort')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'buildings' => DB::table('buildings')
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->where('is_deleted', false)
                ->distinct()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
            'contract_types' => ContractTypeMapper::subtypesByBucket(),
            'apartment_types' => DB::table('apartment_types')
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->where('name', 'not like', 'Unknown Type%')
                ->orderBy('sort')
                ->orderBy('name')
                ->pluck('name')
                ->unique()
                ->values()
                ->all(),
            'month_labels' => self::MONTH_LABELS,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public function units(array $input): array
    {
        $filters = ReportFilters::fromInput($input);

        return $this->unitsBaseQuery($filters)
            ->select([
                'u.bitrix_id',
                'u.title as name',
                'd.name as district',
                'b.name as building',
                'at.name as apartment_type',
                DB::raw('COUNT(mr.id) as reports_count'),
            ])
            ->groupBy('u.bitrix_id', 'u.title', 'd.name', 'b.name', 'at.name')
            ->having('reports_count', '>', 0)
            ->orderBy('u.bitrix_id')
            ->get()
            ->map(fn ($row): array => $this->mapUnitRow($row, true))
            ->all();
    }

    /**
     * @return array{total: int, valid: int, last_sync: ?string, last_calculate: ?string}
     */
    public function stats(): array
    {
        return Cache::remember(MonthlyReportCalculator::STATS_KEY, 60, function (): array {
            $total = UnitStayQuery::base()->count();
            $valid = UnitStayQuery::valid()->count();
            $lastSync = DB::table('unit_stays')->max('last_synced_at');
            $calculatedAt = DB::table('price_monthly_reports')->max('calculated_at');

            return [
                'total' => $total,
                'valid' => $valid,
                'last_sync' => $lastSync ? Carbon::parse($lastSync)->format('d.m.Y H:i:s') : null,
                'last_calculate' => $calculatedAt ? Carbon::parse($calculatedAt)->format('d.m.Y H:i:s') : null,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{id: mixed, title: mixed}>
     */
    public function monthContracts(array $input): array
    {
        $unitId = (string) ($input['unit_id'] ?? '');
        $monthKey = (string) ($input['month_key'] ?? '');
        $contractType = (string) ($input['contract_type'] ?? '');
        $contractTypeIds = ReportFilters::ints($input['contract_type_ids'] ?? null);

        if ($unitId === '' || $monthKey === '' || ! in_array($contractType, [ContractTypeMapper::SHORT, ContractTypeMapper::LONG], true)) {
            throw new InvalidArgumentException('unit_id, month_key и contract_type обязательны');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthKey)) {
            throw new InvalidArgumentException('Некорректный month_key');
        }
        if (strlen($unitId) > 32 || ! preg_match('/^\d+$/', $unitId)) {
            throw new InvalidArgumentException('Некорректный unit_id');
        }

        $monthStart = Carbon::createFromFormat('Y-m-d', $monthKey.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $matched = $this->matchContractsForMonth(
            UnitStayQuery::forUnitsInRange([$unitId], $monthStart, $monthEnd),
            $contractType,
            $monthKey,
            $monthStart,
            $monthEnd,
            $contractTypeIds
        );

        return $matched['items'];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function report(array $input): array
    {
        $filters = ReportFilters::fromInput($input);
        $cacheKey = 'prices:report:'.$this->calculator->reportCacheGeneration().':'.md5(json_encode(
            $filters->cachePayload(),
            JSON_UNESCAPED_UNICODE
        ));
        $ttl = (int) config('services.prices.cache_ttl', 300);

        return Cache::remember($cacheKey, $ttl, function () use ($filters): array {
            $allUnits = $this->unitsBaseQuery($filters)
                ->select([
                    'u.bitrix_id',
                    'u.title as name',
                    'd.name as district',
                    'b.name as building',
                    'at.name as apartment_type',
                ])
                ->distinct()
                ->orderBy('u.bitrix_id')
                ->get()
                ->map(fn ($row): array => $this->mapUnitRow($row))
                ->all();

            $total = count($allUnits);
            $unitsList = array_slice($allUnits, $filters->offset, $filters->limit);

            if ($unitsList === []) {
                return [
                    'units' => [],
                    'reports' => new stdClass,
                    'total' => $total,
                    'offset' => $filters->offset,
                    'limit' => $filters->limit,
                ];
            }

            $unitIds = array_column($unitsList, 'bitrix_id');
            $monthRowsQuery = DB::table('price_monthly_reports')
                ->whereIn('unit_bitrix_id', $unitIds)
                ->whereIn('year', $filters->years);
            if ($filters->months !== []) {
                $monthRowsQuery->whereIn('month_num', $filters->months);
            }
            if ($filters->contractType !== null) {
                $monthRowsQuery->where('contract_type', $filters->contractType);
            }

            $monthRows = $monthRowsQuery
                ->orderBy('unit_bitrix_id')
                ->orderBy('year')
                ->orderBy('month_key')
                ->orderBy('contract_type')
                ->get([
                    'unit_bitrix_id as unit_id',
                    'contract_type',
                    'month_key',
                    'year',
                    'occupied_days',
                    'total_revenue',
                    'avg_price_per_day',
                ]);

            [$rangeStart, $rangeEnd] = $this->dateRange($filters->years, $filters->months);
            $contractsByUnit = [];
            foreach (UnitStayQuery::forUnitsInRange($unitIds, $rangeStart, $rangeEnd) as $contract) {
                $contractsByUnit[$contract['unit_id']][] = $contract;
            }

            $monthBounds = [];
            $reportData = [];
            foreach ($monthRows as $m) {
                $unitId = (string) $m->unit_id;
                $mContractType = (string) $m->contract_type;
                $monthKey = (string) $m->month_key;

                if (! isset($monthBounds[$monthKey])) {
                    $monthStart = Carbon::createFromFormat('Y-m-d', $monthKey.'-01')->startOfDay();
                    $monthBounds[$monthKey] = [$monthStart, $monthStart->copy()->endOfMonth()];
                }
                [$monthStart, $monthEnd] = $monthBounds[$monthKey];

                $matched = $this->matchContractsForMonth(
                    $contractsByUnit[$unitId] ?? [],
                    $mContractType,
                    $monthKey,
                    $monthStart,
                    $monthEnd,
                    $filters->contractTypeIds
                );

                if ($filters->contractTypeIds !== [] && $matched['items'] === []) {
                    continue;
                }

                $cell = [
                    'contract_type' => $mContractType,
                    'month_key' => $monthKey,
                    'year' => (int) $m->year,
                    'occupied_days' => (int) $m->occupied_days,
                    'total_revenue' => (float) $m->total_revenue,
                    'avg_price_per_day' => (float) $m->avg_price_per_day,
                    'contracts_count' => count($matched['items']),
                ];
                if (count($matched['items']) === 1) {
                    $cell['contract_id'] = $matched['items'][0]['id'];
                }
                if ($mContractType === ContractTypeMapper::LONG && $matched['earliestStart'] !== null && $matched['latestEnd'] !== null) {
                    $cell['contract_start_date'] = $matched['earliestStart']->toDateString();
                    $cell['contract_end_date'] = $matched['latestEnd']->toDateString();
                }

                $reportData[$unitId][$mContractType][$monthKey] = $cell;
            }

            return [
                'units' => $unitsList,
                'reports' => $reportData === [] ? new stdClass : $reportData,
                'total' => $total,
                'offset' => $filters->offset,
                'limit' => $filters->limit,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{path: string, filename: string}
     */
    public function export(array $input): array
    {
        $filters = ReportFilters::fromInput($input);

        $unitsRows = $this->unitsBaseQuery($filters)
            ->select(['u.bitrix_id', 'u.title as name'])
            ->distinct()
            ->orderBy('u.bitrix_id')
            ->get();

        $unitIds = [];
        $unitNames = [];
        foreach ($unitsRows as $unitRow) {
            $id = (string) $unitRow->bitrix_id;
            $unitIds[] = $id;
            $unitNames[$id] = $unitRow->name ?? '';
        }

        $monthKeys = $this->monthKeys($filters->years, $filters->months);
        $showYear = count($filters->years) > 1;

        $header = ['Unit', 'Unit ID', 'Contract ID', 'Contract', 'Тип', 'Подтип'];
        foreach ($monthKeys as $monthKey) {
            [$y, $m] = array_map('intval', explode('-', $monthKey));
            $label = $showYear
                ? (self::MONTH_LABELS[$m].' '.$y)
                : ($m.' - '.self::MONTH_LABELS[$m]);
            $header[] = $label.' дни';
            $header[] = $label.' выручка';
        }

        $rows = [$header];

        if ($unitIds !== []) {
            [$rangeStart, $rangeEnd] = $this->dateRange($filters->years, $filters->months);
            $processedContracts = [];
            $allowedLabels = $filters->contractTypeIds !== []
                ? ContractTypeMapper::labelsFromIds($filters->contractTypeIds)
                : [];

            foreach (UnitStayQuery::forUnitsInRange($unitIds, $rangeStart, $rangeEnd) as $contract) {
                $typeName = ContractTypeMapper::bucketFromLabel($contract['contract_type']);
                if ($typeName === null) {
                    continue;
                }
                if ($filters->contractType !== null && $typeName !== $filters->contractType) {
                    continue;
                }
                if ($allowedLabels !== [] && ! in_array($contract['contract_type'], $allowedLabels, true)) {
                    continue;
                }

                $contractKey = $contract['unit_id'].'|'.$contract['start_date'].'|'.$contract['end_date'];
                if (isset($processedContracts[$contractKey])) {
                    continue;
                }
                $processedContracts[$contractKey] = true;

                $breakdown = $this->calculator->buildMonthlyBreakdown(
                    Carbon::parse($contract['start_date']),
                    Carbon::parse($contract['end_date']),
                    (float) $contract['opportunity'],
                    $typeName
                );

                $hasData = false;
                foreach ($monthKeys as $monthKey) {
                    if (isset($breakdown[$monthKey])) {
                        $hasData = true;
                        break;
                    }
                }
                if (! $hasData) {
                    continue;
                }

                $row = [
                    $unitNames[$contract['unit_id']] ?? '',
                    $contract['unit_id'],
                    $contract['bitrix_id'],
                    $contract['title'] ?? '',
                    $typeName,
                    $contract['contract_type'] ?? '',
                ];
                foreach ($monthKeys as $monthKey) {
                    if (isset($breakdown[$monthKey])) {
                        $row[] = $breakdown[$monthKey]['days'];
                        $row[] = round($breakdown[$monthKey]['revenue'], 2);
                    } else {
                        $row[] = '';
                        $row[] = '';
                    }
                }
                $rows[] = $row;
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_out_');
        if ($tmp === false) {
            throw new \RuntimeException('Не удалось создать временный файл');
        }
        $xlsxPath = $tmp.'.xlsx';
        rename($tmp, $xlsxPath);
        $this->xlsxExporter->write($rows, $xlsxPath);

        return [
            'path' => $xlsxPath,
            'filename' => 'report_'.now()->format('Y-m-d_H-i-s').'.xlsx',
        ];
    }

    /**
     * @return array{bitrix_id: string, name: mixed, district: mixed, building: mixed, apartment_type: mixed, reports_count?: int}
     */
    private function mapUnitRow(object $row, bool $withReportsCount = false): array
    {
        $mapped = [
            'bitrix_id' => (string) $row->bitrix_id,
            'name' => $row->name,
            'district' => $row->district ?? null,
            'building' => $row->building ?? null,
            'apartment_type' => $row->apartment_type ?? null,
        ];
        if ($withReportsCount) {
            $mapped['reports_count'] = (int) ($row->reports_count ?? 0);
        }

        return $mapped;
    }

    private function unitsBaseQuery(ReportFilters $filters): Builder
    {
        $query = DB::table('units as u')
            ->join('price_monthly_reports as mr', 'mr.unit_bitrix_id', '=', 'u.bitrix_id')
            ->leftJoin('apartments as a', 'a.id', '=', 'u.apartment_id')
            ->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->leftJoin('buildings as b', 'b.id', '=', 'a.building_id')
            ->leftJoin('apartment_types as at', 'at.id', '=', 'a.apartment_type_id')
            ->where('u.is_deleted', false)
            ->whereIn('mr.year', $filters->years);

        if ($filters->months !== []) {
            $query->whereIn('mr.month_num', $filters->months);
        }
        if ($filters->districts !== []) {
            $query->whereIn('d.name', $filters->districts);
        }
        if ($filters->buildings !== []) {
            $query->whereIn('b.name', $filters->buildings);
        }
        if ($filters->apartmentTypes !== []) {
            $query->whereIn('at.name', $filters->apartmentTypes);
        }
        if ($filters->contractType !== null) {
            $query->where('mr.contract_type', $filters->contractType);
        }
        if ($filters->contractTypeIds !== []) {
            [$rangeStart, $rangeEnd] = $this->dateRange($filters->years, $filters->months);
            $labels = ContractTypeMapper::labelsFromIds($filters->contractTypeIds);
            $query->whereExists(function (Builder $sub) use ($labels, $rangeStart, $rangeEnd): void {
                $sub->select(DB::raw(1))
                    ->from('unit_stays as us')
                    ->join('units as uu', 'uu.id', '=', 'us.unit_id')
                    ->leftJoin('stages as st', 'st.id', '=', 'us.stage_id')
                    ->whereColumn('uu.bitrix_id', 'u.bitrix_id')
                    ->where('us.is_deleted', false)
                    ->where('uu.is_deleted', false)
                    ->whereIn('us.contract_type', $labels);
                UnitStayQuery::applyValidConstraints($sub, 'us', 'uu', 'st');
                $sub->where('us.check_in_date', '<=', $rangeEnd->toDateString())
                    ->where('us.check_out_date', '>=', $rangeStart->toDateString());
            });
        }
        if ($filters->units !== []) {
            $query->whereIn('u.bitrix_id', $filters->units);
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $contracts
     * @param  list<int>  $contractTypeIds
     * @return array{items: list<array{id: mixed, title: mixed}>, earliestStart: ?Carbon, latestEnd: ?Carbon}
     */
    private function matchContractsForMonth(
        array $contracts,
        string $mContractType,
        string $monthKey,
        Carbon $monthStart,
        Carbon $monthEnd,
        array $contractTypeIds
    ): array {
        $relevantContracts = [];
        $earliestStart = null;
        $latestEnd = null;
        $allowedLabels = $contractTypeIds !== [] ? ContractTypeMapper::labelsFromIds($contractTypeIds) : [];

        foreach ($contracts as $contract) {
            $bucket = ContractTypeMapper::bucketFromLabel($contract['contract_type'] ?? null);
            if ($bucket !== $mContractType) {
                continue;
            }
            if ($allowedLabels !== [] && ! in_array($contract['contract_type'], $allowedLabels, true)) {
                continue;
            }

            $contractStart = Carbon::parse($contract['start_date'])->startOfDay();
            $contractEnd = Carbon::parse($contract['end_date'])->startOfDay();
            if ($contractStart->gt($monthEnd) || $contractEnd->lt($monthStart)) {
                continue;
            }

            $contractTotalDays = $contractStart->diff($contractEnd)->days + 1;
            if (MonthlyReportCalculator::skipLongNonStartMonth(
                $mContractType,
                $contractTotalDays,
                $contractStart->format('Y-m') === $monthKey
            )) {
                continue;
            }

            if ($mContractType === ContractTypeMapper::LONG) {
                if ($earliestStart === null || $contractStart->lt($earliestStart)) {
                    $earliestStart = $contractStart->copy();
                }
                if ($latestEnd === null || $contractEnd->gt($latestEnd)) {
                    $latestEnd = $contractEnd->copy();
                }
            }

            $relevantContracts[] = [
                'id' => $contract['bitrix_id'],
                'title' => $contract['title'],
            ];
        }

        return [
            'items' => $relevantContracts,
            'earliestStart' => $earliestStart,
            'latestEnd' => $latestEnd,
        ];
    }

    /**
     * @param  list<int>  $years
     * @param  list<int>  $months
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(array $years, array $months): array
    {
        $monthNums = $months !== [] ? $months : range(1, 12);
        $rangeStart = null;
        $rangeEnd = null;
        foreach ($years as $year) {
            foreach ($monthNums as $monthNum) {
                $start = Carbon::createFromDate($year, $monthNum, 1)->startOfDay();
                $end = $start->copy()->endOfMonth();
                if ($rangeStart === null || $start->lt($rangeStart)) {
                    $rangeStart = $start;
                }
                if ($rangeEnd === null || $end->gt($rangeEnd)) {
                    $rangeEnd = $end;
                }
            }
        }

        return [$rangeStart ?? now()->startOfYear(), $rangeEnd ?? now()->endOfYear()];
    }

    /**
     * @param  list<int>  $years
     * @param  list<int>  $months
     * @return list<string>
     */
    private function monthKeys(array $years, array $months): array
    {
        $years = array_values(array_unique(array_map('intval', $years)));
        sort($years);
        $monthNums = $months !== []
            ? array_values(array_unique(array_map('intval', $months)))
            : range(1, 12);
        sort($monthNums);
        $keys = [];
        foreach ($years as $year) {
            foreach ($monthNums as $monthNum) {
                $keys[] = sprintf('%04d-%02d', $year, $monthNum);
            }
        }

        return $keys;
    }
}
