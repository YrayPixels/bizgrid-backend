<?php

namespace App\Agents\Contracts;

interface AgentInterface
{
    /**
     * Execute the agent with the given context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array;

    /**
     * The system prompt for this agent.
     */
    public function systemPrompt(): string;

    /**
     * JSON Schema for structured output.
     *
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * Unique name for this agent (e.g. 'interpreter', 'design-director').
     */
    public function name(): string;

    /**
     * Prompt version to use (e.g. 'v1', 'v2').
     */
    public function promptVersion(): string;

    /**
     * Temperature for generation (0.0 - 2.0).
     */
    public function temperature(): float;
}
