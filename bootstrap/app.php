<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\DirectoriesAccess;
use App\Http\Middleware\InertiaShare;
use App\Http\Middleware\ApiKey;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            InertiaShare::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'directories.module' => DirectoriesAccess::class,
            'api.key' => ApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e): void {
            if (! app()->bound(App\Services\TelegramAlert::class)) {
                return;
            }

            if (! app()->environment('production')) {
                return;
            }

            try {
                $msg = $e->getMessage();
                if (str_starts_with($msg, 'Scheduled command ')) {
                    return;
                }

                $text = app(App\Services\TelegramAlertText::class)->message(
                    '🧨',
                    'Exception',
                    null,
                    class_basename($e).': '.mb_substr($msg, 0, 300),
                );
                app(App\Services\TelegramAlert::class)->notifyOnce(
                    'exception:'.class_basename($e).':'.md5($msg),
                    $text,
                    (int) config('services.telegram.instant_dedupe_ttl', 3600)
                );
            } catch (Throwable) {
            }
        });
    })->create();
