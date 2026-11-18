<?php

use App\Http\Controllers\Prices\ApiController;
use App\Http\Controllers\Prices\InstallController;
use App\Http\Controllers\Prices\ReportController;
use App\Http\Middleware\InertiaShare;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;

$pricesPublicWithout = [
    ValidateCsrfToken::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    InertiaShare::class,
];

// Bitrix opens the handler with POST (DOMAIN/AUTH_*); allow both methods.
Route::match(['get', 'post'], '/', ReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware($pricesPublicWithout)
    ->name('prices.report');

// Bitrix local-app handler often points at /index.php (same UI as /).
Route::match(['get', 'post'], '/index.php', ReportController::class)
    ->middleware('throttle:60,1')
    ->withoutMiddleware($pricesPublicWithout)
    ->name('prices.report.index_php');

// Stateless JSON/export API: no CSRF/session (public report, same as legacy prices).
Route::match(['get', 'post'], '/api', ApiController::class)
    ->middleware('throttle:prices-api')
    ->withoutMiddleware($pricesPublicWithout)
    ->name('prices.api');

// Bitrix local-app install callback (BX24 posts without CSRF).
// /install.php is mapped to /install in nginx.
Route::match(['get', 'post'], '/install', InstallController::class)
    ->middleware('throttle:bitrix-install')
    ->withoutMiddleware($pricesPublicWithout)
    ->name('prices.install');
