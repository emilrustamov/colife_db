<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BitrixUnitsSnapshot;
use App\Models\ClientBalance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BitrixUnitsSnapshotController extends Controller
{
    /**
     * @return array{year: int, month: int}
     */
    private function balancePeriodForDisplay(Carbon $now): array
    {
        if ($now->day <= 15) {
            $balanceFor = $now->copy()->subMonthsNoOverflow(2);
        } else {
            $balanceFor = $now->copy()->subMonthNoOverflow();
        }

        return [
            'year' => (int) $balanceFor->year,
            'month' => (int) $balanceFor->month,
        ];
    }

    /**
     * Apartments with aggregated idle flag from Bitrix unit snapshot rows.
     */
    public function idleApartments(): JsonResponse
    {
        $period = $this->balancePeriodForDisplay(now());

        $balances = ClientBalance::query()
            ->where('year', $period['year'])
            ->where('month', $period['month'])
            ->get(['apartment_id', 'balance'])
            ->keyBy(static fn ($row): int => (int) $row->apartment_id);

        $items = BitrixUnitsSnapshot::query()
            ->select([
                'apart_id',
                DB::raw('MIN(CASE WHEN is_idle = 1 THEN 1 ELSE 0 END) as is_idle'),
            ])
            ->whereNotNull('apart_id')
            ->groupBy('apart_id')
            ->orderBy('apart_id')
            ->get()
            ->map(function ($row) use ($balances, $period) {
                $isIdle = (bool) $row->is_idle;
                $apartId = (int) $row->apart_id;
                $balanceRow = $balances->get($apartId);

                return [
                    'apart_id' => $apartId,
                    'is_idle' => $isIdle,
                    'status' => $isIdle ? 'Idle' : 'Not idle',
                    'balance' => $balanceRow !== null ? (float) $balanceRow->balance : null,
                    'balance_year' => $period['year'],
                    'balance_month' => $period['month'],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'count' => $items->count(),
            'balance_year' => $period['year'],
            'balance_month' => $period['month'],
            'items' => $items,
        ]);
    }
}
