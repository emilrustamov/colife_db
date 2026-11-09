<?php

namespace App\Providers;

use App\Listeners\NotifyTelegramOnJobFailed;
use App\Listeners\NotifyTelegramOnLongWait;
use App\Models\Contact;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Support\BitrixSync;
use Illuminate\Cache\RateLimiting\Limit;
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

        RateLimiter::for('bitrix-install', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        Gate::before(function ($user, ?string $ability) {
            if ($user instanceof User && $user->is_superadmin) {
                return true;
            }

            return null;
        });
    }
}
