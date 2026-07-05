<?php

namespace App\Providers;

use App\Services\DnsRecordResolver;
use App\Services\NativeDnsRecordResolver;
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
        //
    }
}
