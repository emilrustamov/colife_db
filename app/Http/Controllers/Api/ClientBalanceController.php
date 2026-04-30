<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BatchStoreClientBalanceRequest;
use App\Http\Requests\StoreClientBalanceRequest;
use App\Models\ClientBalance;
use Illuminate\Http\JsonResponse;

class ClientBalanceController extends Controller
{
    /**
     * Upsert balance for client, apartment, year and month.
     */
    public function store(StoreClientBalanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $balance = ClientBalance::updateOrCreate(
            [
                'apartment_id' => (string) $data['apart_id'],
                'year' => $data['year'],
                'month' => $data['month'],
            ],
            [
                'balance' => $data['balance'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Balance saved successfully.',
            'data' => $balance,
        ]);
    }

    /**
     * Upsert many balances in one round-trip.
     */
    public function batchStore(BatchStoreClientBalanceRequest $request): JsonResponse
    {
        $items = $request->validated()['items'];

        $now = now();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'apartment_id' => (string) $item['apart_id'],
                'year' => $item['year'],
                'month' => $item['month'],
                'balance' => $item['balance'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ClientBalance::upsert(
            $rows,
            ['apartment_id', 'year', 'month'],
            ['balance', 'updated_at']
        );

        return response()->json([
            'success' => true,
            'message' => 'Balances saved successfully.',
            'count' => count($rows),
        ]);
    }
}
