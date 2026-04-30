<?php

use App\Http\Controllers\Api\BitrixUnitsSnapshotController;
use App\Http\Controllers\Api\ClientBalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key'])->group(function (): void {
    Route::post('/client-balances', [ClientBalanceController::class, 'store']);
    Route::post('/client-balances/batch', [ClientBalanceController::class, 'batchStore']);
    Route::get('/bitrix-units/idle-apartments', [BitrixUnitsSnapshotController::class, 'idleApartments']);
});
