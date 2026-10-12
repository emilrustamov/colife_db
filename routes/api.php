<?php

use App\Http\Controllers\Api\InstallController;
use App\Http\Controllers\Api\OpenLinesController;
use App\Http\Controllers\Api\UnitsSnapshotController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\BpController;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\DiskApi;
use App\Http\Controllers\Api\TerminateListBpApi;
use App\Http\Controllers\Api\CleaningInstallController;
use App\Http\Controllers\Api\CleaningFormController;
use App\Http\Controllers\Api\CleaningSubmitController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key'])->group(function (): void {
    Route::post('/client-balances', [BalanceController::class, 'store']);
    Route::post('/client-balances/batch', [BalanceController::class, 'batchStore']);
    Route::get('/bitrix-units/idle-apartments', [UnitsSnapshotController::class, 'idleApartments']);
    Route::post('/disk/sync', [DiskApi::class, 'sync']);
});

Route::match(['get', 'post'], '/disk/pull', [DiskApi::class, 'pull']);
Route::match(['get', 'post'], '/bp/terminate-list', [TerminateListBpApi::class, 'pull']);
Route::post('/webhooks/bitrix', WebhookController::class);
Route::post('/webhooks/bitrix/contacts', WebhookController::class);
Route::post('/webhooks/bitrix/open-lines', OpenLinesController::class);
Route::post('/bp/wait', [BpController::class, 'wait']);
Route::post('/bp/handler', [BpController::class, 'wait']);
Route::match(['get', 'post'], '/bp/install', [InstallController::class, 'install'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);

Route::match(['get', 'post'], '/cleaning/install', [CleaningInstallController::class, 'install'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::post('/cleaning/install/save', [CleaningInstallController::class, 'save'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::match(['get', 'post'], '/cleaning', [CleaningFormController::class, 'index'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::post('/cleaning/submit', [CleaningSubmitController::class, 'submit'])
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
