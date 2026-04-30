<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\RoleAdminController;
use App\Http\Controllers\UserAdminController;
use App\Http\Middleware\RedirectLocalhostToAppUrl;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware([RedirectLocalhostToAppUrl::class])->group(function () {
    Route::get('/', function () {
        if (! Auth::check()) {
            return redirect()->route('directories.index');
        }

        $user = Auth::user();

        if (DirectoryController::userHasAnyDirectoryAccess($user)) {
            return redirect()->route('directories.index');
        }

        return redirect()->route('directories.index');
    });

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware(['auth', 'directories.module'])->group(function () {
        Route::get('/directories', [DirectoryController::class, 'root'])->name('directories.index');
        Route::get('/directories/{directory}', [DirectoryController::class, 'index'])->name('directories.page');
        Route::get('/api/directories/{directory}', [DirectoryController::class, 'list'])->name('directories.list');
        Route::get('/api/directories/{directory}/{id}', [DirectoryController::class, 'show'])->name('directories.show');
    });

    Route::middleware(['auth', 'permission:users.manage'])->group(function (): void {
        Route::get('/api/admin/users', [UserAdminController::class, 'index']);
        Route::post('/api/admin/users', [UserAdminController::class, 'store']);
        Route::put('/api/admin/users/{user}', [UserAdminController::class, 'update']);
        Route::delete('/api/admin/users/{user}', [UserAdminController::class, 'destroy']);
    });

    Route::middleware(['auth', 'permission:roles.manage'])->group(function (): void {
        Route::get('/api/admin/roles', [RoleAdminController::class, 'index']);
        Route::post('/api/admin/roles', [RoleAdminController::class, 'store']);
        Route::put('/api/admin/roles/{role}', [RoleAdminController::class, 'update']);
        Route::delete('/api/admin/roles/{role}', [RoleAdminController::class, 'destroy']);
    });
});
