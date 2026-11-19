<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // PRD 5.1: preventLazyLoading di development untuk mencegah N+1 query
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::unguard();

        Gate::before(function ($user, $ability) {
            if ($user->hasRole('admin')) {
                return true;
            }
            return $user->hasPermission($ability) ?: null;
        });
    }
}

