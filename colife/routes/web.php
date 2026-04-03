<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DirectoryController;
use App\Http\Middleware\RedirectLocalhostToAppUrl;
use Illuminate\Support\Facades\Route;

Route::middleware([RedirectLocalhostToAppUrl::class])->group(function () {
    Route::get('/', fn () => redirect()->route('directories.index'));

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware(['auth', 'permission:directories.view'])->group(function () {
        Route::get('/directories', [DirectoryController::class, 'index'])->name('directories.index');
        Route::get('/api/directories/{directory}', [DirectoryController::class, 'list'])->name('directories.list');
        Route::get('/api/directories/{directory}/{id}', [DirectoryController::class, 'show'])->name('directories.show');
    });
});
