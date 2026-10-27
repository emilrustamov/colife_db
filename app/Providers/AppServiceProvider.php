<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Support\BitrixSync;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
