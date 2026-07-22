<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Services\PlatformAiConfigService;
use RuntimeException;

class AgentRegistry
{
    /**
     * @var array<string, AgentInterface>
     */
    private array $agents = [];

    /**
     * @var array<string, class-string<AgentInterface>>
     */
    private array $bindings = [];

    /**
     * Register an agent binding.
     *
     * @param  class-string<AgentInterface>  $class
     */
    public function register(string $name, string $class): void
    {
        $this->bindings[$name] = $class;
    }

    /**
     * Resolve an agent by name.
     */
    public function agent(string $name): AgentInterface
    {
        if (isset($this->agents[$name])) {
            return $this->agents[$name];
        }

        if (! isset($this->bindings[$name])) {
            throw new RuntimeException("Agent [{$name}] is not registered.");
        }

        return $this->agents[$name] = app($this->bindings[$name]);
    }

    /**
     * Check if an agent is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->bindings[$name]);
    }

    /**
     * Execute an agent with the given context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(string $name, array $context): ?array
    {
        return $this->agent($name)->execute($context);
    }

    /**
     * Get all registered agent names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->bindings);
    }

    /**
     * Check if AI is available (API key configured).
     */
    public function available(): bool
    {
        return app(PlatformAiConfigService::class)->available();
    }
}
