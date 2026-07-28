<?php

namespace App\Providers;

use App\Models\Store;
use App\Policies\StorePolicy;
use App\Services\DnsRecordResolver;
use App\Services\NativeDnsRecordResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DnsRecordResolver::class, NativeDnsRecordResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Store::class, StorePolicy::class);
    }
}
