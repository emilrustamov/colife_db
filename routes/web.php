<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DirectoryController;
use App\Http\Controllers\DiskBrowser;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\OlBot\AnalyticsController as OlBotAnalyticsController;
use App\Http\Controllers\OlBot\TemplateController as OlBotTemplateController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\RoleAdmin;
use App\Http\Controllers\UserAdmin;
use App\Http\Middleware\RedirectLocalhost;
use App\Support\AdminMenu;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$pricesDomain = (string) config('services.prices.domain');
if ($pricesDomain !== '') {
    Route::domain($pricesDomain)->group(base_path('routes/prices.php'));
}

Route::middleware([RedirectLocalhost::class])->group(function () {
    Route::get('/', function () {
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->email_verified_at === null) {
            return redirect()->route('email.verify');
        }

        return redirect()->to(AdminMenu::homeUrl($user));
    });

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'create'])->name('login');
        Route::post('/login', [AuthController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('login.store');

        Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('password.email');
        Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.reset');
        Route::post('/reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:5,1')
            ->name('password.update');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

        Route::get('/email/verify', [EmailVerificationController::class, 'show'])->name('email.verify');
        Route::post('/email/verify', [EmailVerificationController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('email.verify.store');
        Route::post('/email/verify/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('email.verify.resend');
    });

    Route::middleware(['auth', 'verified', 'directories.module'])->group(function () {
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

    Route::middleware(['auth', 'verified', 'permission:users.manage|users.edit|users.delete'])->group(function (): void {
        Route::get('/api/admin/users', [UserAdmin::class, 'index']);
    });
    Route::middleware(['auth', 'verified', 'permission:users.manage'])->group(function (): void {
        Route::post('/api/admin/users', [UserAdmin::class, 'store']);
    });
    Route::middleware(['auth', 'verified', 'permission:users.edit'])->group(function (): void {
        Route::put('/api/admin/users/{user}', [UserAdmin::class, 'update']);
    });
    Route::middleware(['auth', 'verified', 'permission:users.delete'])->group(function (): void {
        Route::delete('/api/admin/users/{user}', [UserAdmin::class, 'destroy']);
    });

    Route::middleware(['auth', 'verified', 'permission:roles.manage'])->group(function (): void {
        Route::get('/api/admin/roles', [RoleAdmin::class, 'index']);
        Route::post('/api/admin/roles', [RoleAdmin::class, 'store']);
        Route::put('/api/admin/roles/{role}', [RoleAdmin::class, 'update']);
        Route::delete('/api/admin/roles/{role}', [RoleAdmin::class, 'destroy']);
    });

    Route::middleware(['auth', 'verified'])->prefix('ol-bot')->name('ol-bot.')->group(function (): void {
        Route::get('/', function () {
            $user = Auth::user();

            if ($user !== null && $user->can('ol-bot.analytics')) {
                return redirect()->route('ol-bot.analytics');
            }

            if ($user !== null && $user->can('ol-bot.catalog')) {
                return redirect()->to('/ol-bot/analytics?tab=catalog');
            }

            if ($user !== null && $user->can('ol-bot.templates')) {
                return redirect()->route('ol-bot.templates');
            }

            abort(403);
        });

        Route::middleware(['permission:ol-bot.templates'])->group(function (): void {
            Route::get('/templates', [OlBotTemplateController::class, 'index'])->name('templates');
            Route::get('/templates/main', [OlBotTemplateController::class, 'main'])->name('templates.main');
            Route::get('/templates/scenarios', [OlBotTemplateController::class, 'scenarios'])->name('templates.scenarios');
            Route::get('/templates/scenarios/{name}', [OlBotTemplateController::class, 'scenario'])->name('templates.scenario');
            Route::get('/templates/skills', [OlBotTemplateController::class, 'skills'])->name('templates.skills');
            Route::get('/templates/skills/{name}', [OlBotTemplateController::class, 'skill'])->name('templates.skill');
            Route::get('/templates/tools', [OlBotTemplateController::class, 'tools'])->name('templates.tools');
            Route::get('/templates/tools/{name}', [OlBotTemplateController::class, 'tool'])->name('templates.tool');
            Route::get('/templates/versions/{version}', [OlBotTemplateController::class, 'show'])->name('templates.show');
        });

        Route::middleware(['permission:ol-bot.templates.edit'])->group(function (): void {
            Route::post('/templates', [OlBotTemplateController::class, 'store'])->name('templates.store');
            Route::post('/templates/scope-tools', [OlBotTemplateController::class, 'syncTools'])->name('templates.scope-tools');
            Route::post('/templates/model', [OlBotTemplateController::class, 'updateModel'])->name('templates.model');
            Route::post('/templates/{version}/activate', [OlBotTemplateController::class, 'activate'])->whereNumber('version')->name('templates.activate');
        });

        Route::middleware(['permission:ol-bot.analytics|ol-bot.catalog'])->group(function (): void {
            Route::get('/analytics', [OlBotAnalyticsController::class, 'index'])->name('analytics');
            Route::get('/analytics/chats/{chatId}', [OlBotAnalyticsController::class, 'show'])->name('analytics.chat');
        });
    });
});
