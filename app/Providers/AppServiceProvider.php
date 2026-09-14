<?php

namespace App\Providers;

use App\Http\Middleware\EnsureSuperAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('super-admin', fn (User $user) => $user->isSuperAdmin());

        // Livewire re-applies only a short list of middleware on its update
        // requests. The admin gate must be on that list, or a page rendered
        // while someone was an admin would keep answering after revocation.
        Livewire::addPersistentMiddleware([EnsureSuperAdmin::class]);
    }
}
