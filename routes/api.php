<?php

use App\Http\Controllers\Api\BitrixInstallController;
use App\Http\Controllers\Api\BitrixOpenLinesWebhookController;
use App\Http\Controllers\Api\BitrixUnitsSnapshotController;
use App\Http\Controllers\Api\BitrixWebhookController;
use App\Http\Controllers\Api\BpController;
use App\Http\Controllers\Api\ClientBalanceController;
use App\Http\Controllers\Api\DiskSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key'])->group(function (): void {
    Route::post('/client-balances', [ClientBalanceController::class, 'store']);
    Route::post('/client-balances/batch', [ClientBalanceController::class, 'batchStore']);
    Route::get('/bitrix-units/idle-apartments', [BitrixUnitsSnapshotController::class, 'idleApartments']);
    Route::post('/disk/sync', [DiskSyncController::class, 'sync']);
});

Route::match(['get', 'post'], '/disk/pull', [DiskSyncController::class, 'pull']);
Route::post('/webhooks/bitrix', BitrixWebhookController::class);
Route::post('/webhooks/bitrix/contacts', BitrixWebhookController::class);
Route::post('/webhooks/bitrix/open-lines', BitrixOpenLinesWebhookController::class);
Route::post('/bp/wait', [BpController::class, 'wait']);
Route::post('/bp/handler', [BpController::class, 'wait']);
Route::match(['get', 'post'], '/bp/install', [BitrixInstallController::class, 'install'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
