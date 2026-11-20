<?php

namespace App\Providers;

use App\Listeners\NotifyTelegramOnJobFailed;
use App\Listeners\NotifyTelegramOnLongWait;
use App\Listeners\NotifyTelegramOnScheduledTaskFailed;
use App\Models\Contact;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Support\BitrixSync;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Events\LongWaitDetected;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BitrixSync::class, static fn (): BitrixSync => new BitrixSync());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Contact::observe(ContactObserver::class);

        Event::listen(JobFailed::class, NotifyTelegramOnJobFailed::class);
        Event::listen(LongWaitDetected::class, NotifyTelegramOnLongWait::class);
        Event::listen(ScheduledTaskFailed::class, NotifyTelegramOnScheduledTaskFailed::class);

        RateLimiter::for('bitrix-install', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        RateLimiter::for('prices-api', function (Request $request) {
            $action = (string) $request->query('action', '');
            $by = $request->ip() ?: 'unknown';

            return match ($action) {
                'export' => Limit::perMinute(6)->by('prices-export:'.$by),
                'report' => Limit::perMinute(30)->by('prices-report:'.$by),
                default => Limit::perMinute(60)->by('prices-api:'.$by),
            };
        });

        // Cleaning SPA: separate buckets — History thumbnails must not burn the list API limit.
        $cleaningBy = static fn (Request $request): string => $request->ip() ?: 'unknown';
        RateLimiter::for('cleaning-form', fn (Request $request) => Limit::perMinute(60)->by('cleaning-form:'.$cleaningBy($request)));
        RateLimiter::for('cleaning-submit', fn (Request $request) => Limit::perMinute(20)->by('cleaning-submit:'.$cleaningBy($request)));
        RateLimiter::for('cleaning-staff', fn (Request $request) => Limit::perMinute(30)->by('cleaning-staff:'.$cleaningBy($request)));
        RateLimiter::for('cleaning-history', fn (Request $request) => Limit::perMinute(120)->by('cleaning-history:'.$cleaningBy($request)));
        RateLimiter::for('cleaning-history-delete', fn (Request $request) => Limit::perMinute(30)->by('cleaning-history-delete:'.$cleaningBy($request)));
        RateLimiter::for('cleaning-file', fn (Request $request) => Limit::perMinute(600)->by('cleaning-file:'.$cleaningBy($request)));

        Gate::before(function ($user, ?string $ability) {
            if ($user instanceof User && $user->is_superadmin) {
                return true;
            }

            return null;
        });
    }
}
