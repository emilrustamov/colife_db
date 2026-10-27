<?php

use App\Http\Controllers\Api\InstallController;
use App\Http\Controllers\Api\OpenLinesController;
use App\Http\Controllers\Api\UnitsSnapshotController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\BpController;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\AlmaContactController;
use App\Http\Controllers\Api\AlmaBridge\ApartmentController as AlmaBridgeApartmentController;
use App\Http\Controllers\Api\AlmaBridge\BlockingController as AlmaBridgeBlockingController;
use App\Http\Controllers\Api\AlmaBridge\LandlordContractController as AlmaBridgeLandlordContractController;
use App\Http\Controllers\Api\AlmaBridge\TenantContractController as AlmaBridgeTenantContractController;
use App\Http\Controllers\Api\AlmaBridge\TerminationController as AlmaBridgeTerminationController;
use App\Http\Controllers\Api\AlmaBridge\UnitController as AlmaBridgeUnitController;
use App\Http\Controllers\Api\AlmaBridge\WebhookController as AlmaBridgeWebhookController;
use App\Http\Controllers\Api\DiskApi;
use App\Http\Controllers\Api\TerminateBpApi;
use App\Http\Controllers\Api\CleaningInstallController;
use App\Http\Controllers\Api\CleaningFormController;
use App\Http\Controllers\Api\CleaningSubmitController;
use App\Http\Controllers\Api\OpenLinesInstallController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.key'])->group(function (): void {
    Route::post('/client-balances', [BalanceController::class, 'store']);
    Route::post('/client-balances/batch', [BalanceController::class, 'batchStore']);
    Route::post('/alma/contacts/external-id', [AlmaContactController::class, 'updateExternalId']);
    Route::get('/bitrix-units/idle-apartments', [UnitsSnapshotController::class, 'idleApartments']);
    Route::post('/disk/sync', [DiskApi::class, 'sync']);

    Route::post('/alma-bridge/contracts', [AlmaBridgeTenantContractController::class, 'store']);
    Route::patch('/alma-bridge/contracts/{bitrixId}', [AlmaBridgeTenantContractController::class, 'update']);
    Route::post('/alma-bridge/contracts/upsert', [AlmaBridgeTenantContractController::class, 'upsert']);
    Route::post('/alma-bridge/apartments/upsert', [AlmaBridgeApartmentController::class, 'upsert']);
    Route::post('/alma-bridge/units/upsert', [AlmaBridgeUnitController::class, 'upsert']);
    Route::post('/alma-bridge/landlord-contracts', [AlmaBridgeLandlordContractController::class, 'store']);
    Route::patch('/alma-bridge/landlord-contracts/{bitrixId}', [AlmaBridgeLandlordContractController::class, 'update']);
    Route::post('/alma-bridge/blocking/apply', [AlmaBridgeBlockingController::class, 'apply']);
    Route::post('/alma-bridge/termination/apply', [AlmaBridgeTerminationController::class, 'apply']);
});

Route::middleware(['throttle:60,1'])->group(function (): void {
    Route::match(['get', 'post'], '/disk/pull', [DiskApi::class, 'pull']);
    Route::match(['get', 'post'], '/bp/terminate-list', [TerminateBpApi::class, 'pull']);
    Route::post('/webhooks/bitrix', WebhookController::class);
    Route::post('/webhooks/bitrix/contacts', WebhookController::class);
    Route::post('/webhooks/bitrix/alma', AlmaBridgeWebhookController::class);
    Route::post('/webhooks/bitrix/open-lines', OpenLinesController::class);
    Route::post('/bp/wait', [BpController::class, 'wait']);
    Route::post('/bp/handler', [BpController::class, 'wait']);
});

Route::match(['get', 'post'], '/open-lines/install', [OpenLinesInstallController::class, 'install'])
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::post('/open-lines/install/save', [OpenLinesInstallController::class, 'save'])
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::match(['get', 'post'], '/bp/install', [InstallController::class, 'install'])
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);

Route::match(['get', 'post'], '/cleaning/install', [CleaningInstallController::class, 'install'])
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::post('/cleaning/install/save', [CleaningInstallController::class, 'save'])
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::match(['get', 'post'], '/cleaning', [CleaningFormController::class, 'index'])
    ->middleware('throttle:60,1')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
Route::post('/cleaning/submit', [CleaningSubmitController::class, 'submit'])
    ->middleware('throttle:20,1')
    ->withoutMiddleware(['auth', 'auth:sanctum', 'api.key']);
