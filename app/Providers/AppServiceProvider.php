<?php

namespace App\Providers;

use App\Models\Contact;
use App\Models\User;
use App\Observers\ContactObserver;
use App\Support\BitrixSyncContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BitrixSyncContext::class, static fn (): BitrixSyncContext => new BitrixSyncContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Contact::observe(ContactObserver::class);

        Gate::before(function ($user, ?string $ability) {
            if ($user instanceof User && $user->is_superadmin) {
                return true;
            }

            return null;
        });
    }
}
