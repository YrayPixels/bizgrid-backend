<?php

namespace App\Providers;

use App\Agents\AgentRegistry;
use App\Agents\CustomerCommerceAgent;
use App\Agents\MarketingAgent;
use App\Agents\BuilderOrchestratorAgent;
use App\Agents\ColorSpecialistAgent;
use App\Agents\ConversationAgent;
use App\Agents\DesignDirectorAgent;
use App\Agents\EditorAgent;
use App\Agents\InterpreterAgent;
use App\Agents\StorefrontCodeAgent;
use App\Agents\StorefrontWriterAgent;
use App\Agents\VisionAgent;
use App\Services\PromptService;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PromptService::class);
        $this->app->singleton(VisionAgent::class);
        $this->app->singleton(StorefrontCodeAgent::class);

        $this->app->singleton(AgentRegistry::class, function ($app) {
            $registry = new AgentRegistry;

            $registry->register('interpreter', InterpreterAgent::class);
            $registry->register('color-specialist', ColorSpecialistAgent::class);
            $registry->register('design-director', DesignDirectorAgent::class);
            $registry->register('conversation-agent', ConversationAgent::class);
            $registry->register('builder-orchestrator', BuilderOrchestratorAgent::class);
            $registry->register('marketing-agent', MarketingAgent::class);
            $registry->register('customer-commerce-agent', CustomerCommerceAgent::class);
            $registry->register('storefront-writer', StorefrontWriterAgent::class);
            $registry->register('editor-agent', EditorAgent::class);

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
