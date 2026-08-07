<?php

namespace App\Providers;

use App\Agents\AgentRegistry;
use App\Agents\CustomerCommerceAgent;
use App\Agents\MarketingAgent;
use App\Agents\SentimentAgent;
use App\Agents\StorefrontCodeAgent;
use App\Agents\StorefrontWriterAgent;
use App\Agents\VisionAgent;
use App\Services\AgentExecutionLogService;
use App\Services\PromptService;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AgentExecutionLogService::class);
        $this->app->singleton(PromptService::class);
        $this->app->singleton(VisionAgent::class);
        $this->app->singleton(StorefrontCodeAgent::class);

        $this->app->singleton(AgentRegistry::class, function ($app) {
            $registry = new AgentRegistry;

            $registry->register('marketing-agent', MarketingAgent::class);
            $registry->register('customer-commerce-agent', CustomerCommerceAgent::class);
            $registry->register('sentiment-agent', SentimentAgent::class);
            $registry->register('storefront-writer', StorefrontWriterAgent::class);

            return $registry;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
