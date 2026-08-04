<?php

use App\Http\Controllers\Api\InstallController;
use App\Http\Controllers\Api\OpenLinesController;
use App\Http\Controllers\Api\UnitsSnapshotController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\BpController;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\DiskApi;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key'])->group(function (): void {
    Route::post('/client-balances', [BalanceController::class, 'store']);
    Route::post('/client-balances/batch', [BalanceController::class, 'batchStore']);
    Route::get('/bitrix-units/idle-apartments', [UnitsSnapshotController::class, 'idleApartments']);
    Route::post('/disk/sync', [DiskApi::class, 'sync']);
});

Route::match(['get', 'post'], '/disk/pull', [DiskApi::class, 'pull']);
Route::post('/webhooks/bitrix', WebhookController::class);
Route::post('/webhooks/bitrix/contacts', WebhookController::class);
Route::post('/webhooks/bitrix/open-lines', OpenLinesController::class);
Route::post('/bp/wait', [BpController::class, 'wait']);
Route::post('/bp/handler', [BpController::class, 'wait']);
Route::match(['get', 'post'], '/bp/install', [InstallController::class, 'install'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
