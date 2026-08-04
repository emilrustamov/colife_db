<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DiskBrowser;
use App\Http\Controllers\RoleAdmin;
use App\Http\Controllers\UserAdmin;
use App\Http\Middleware\RedirectLocalhost;
use Illuminate\Support\Facades\Route;

Route::middleware([RedirectLocalhost::class])->group(function () {
    Route::get('/', fn () => redirect()->route('directories.index'));

        Route::middleware('guest')->group(function () {
            Route::get('/login', [AuthController::class, 'create'])->name('login');
            Route::post('/login', [AuthController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('login.store');
        });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    });

    Route::middleware(['auth', 'directories.module'])->group(function () {
        Route::get('/directories', [DirectoryController::class, 'root'])->name('directories.index');
        Route::get('/directories/{directory}', [DirectoryController::class, 'index'])->name('directories.page');
        Route::get('/api/directories/disk/browser/folders', [DiskBrowser::class, 'folders'])->name('directories.disk.folders');
        Route::get('/api/directories/disk/browser/folder', [DiskBrowser::class, 'folder'])->name('directories.disk.folder');
        Route::get('/api/directories/disk/browser/files', [DiskBrowser::class, 'files'])->name('directories.disk.files');
        Route::get('/api/directories/disk/browser/files/{id}/download', [DiskBrowser::class, 'download'])->name('directories.disk.download');
        Route::get('/api/directories/disk/browser/files/{id}/preview', [DiskBrowser::class, 'preview'])->name('directories.disk.preview');
        Route::get('/api/directories/{directory}', [DirectoryController::class, 'list'])->name('directories.list');
        Route::get('/api/directories/{directory}/{id}', [DirectoryController::class, 'show'])->name('directories.show');
    });

    Route::middleware(['auth', 'permission:users.manage'])->group(function (): void {
        Route::get('/api/admin/users', [UserAdmin::class, 'index']);
        Route::post('/api/admin/users', [UserAdmin::class, 'store']);
        Route::put('/api/admin/users/{user}', [UserAdmin::class, 'update']);
        Route::delete('/api/admin/users/{user}', [UserAdmin::class, 'destroy']);
    });

    Route::middleware(['auth', 'permission:roles.manage'])->group(function (): void {
        Route::get('/api/admin/roles', [RoleAdmin::class, 'index']);
        Route::post('/api/admin/roles', [RoleAdmin::class, 'store']);
        Route::put('/api/admin/roles/{role}', [RoleAdmin::class, 'update']);
        Route::delete('/api/admin/roles/{role}', [RoleAdmin::class, 'destroy']);
    });
});
