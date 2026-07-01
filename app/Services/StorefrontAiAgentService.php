<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\Store;

class StorefrontAiAgentService
{
    public function __construct(
        private readonly AgentRegistry $registry,
    ) {}

    public function available(): bool
    {
        return $this->registry->available();
    }

    /**
     * @param  array<string, mixed>  $currentProfile
     * @param  list<array{role: string, content: string}>  $conversationHistory
     * @return array<string, mixed>|null
     */
    public function extractBusinessProfile(
        string $message,
        array $currentProfile = [],
        array $conversationHistory = [],
    ): ?array {
        return $this->registry->execute('interpreter', [
            'message' => $message,
            'current_profile' => $currentProfile,
            'conversation_history' => $conversationHistory,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{brand_color: string, label: string, palette: array<string, string>}|null
     */
    public function resolveBrandColorFromMessage(string $message, array $context = [], bool $randomPick = false): ?array
    {
        return $this->registry->execute('color-specialist', array_merge($context, [
            'message' => $message,
            'random_pick' => $randomPick,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $availableTemplateIds
     * @param  list<array<string, mixed>>  $templateCatalog
     * @return array{
     *     template_id: string,
     *     brand_color: string,
     *     color_label: string,
     *     palette: list<array{color: string, label: string}>,
     *     industry: string|null,
     *     tone: list<string>,
     *     merchant_summary: string
     * }|null
     */
    public function resolveDesignDirectionFromMessage(
        string $message,
        array $context = [],
        array $availableTemplateIds = [],
        array $templateCatalog = [],
    ): ?array {
        return $this->registry->execute('design-director', array_merge($context, [
            'message' => $message,
            'available_template_ids' => $availableTemplateIds,
            'template_catalog' => $templateCatalog,
        ]));
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     */
    public function respondToConversation(string $message, array $sessionContext): ?string
    {
        $result = $this->registry->execute('conversation-agent', [
            'message' => $message,
            'session' => $sessionContext,
        ]);

        return is_array($result) ? ($result['reply'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $recommendations
     * @param  list<string>  $availableTemplateIds
     * @return array{assistant_message: string, plan?: array<int, array<string, mixed>>, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function planBuilderTurn(
        string $message,
        array $sessionContext,
        array $profile,
        array $recommendations,
        array $availableTemplateIds,
    ): ?array {
        return $this->registry->execute('builder-orchestrator', [
            'message' => $message,
            'session' => $sessionContext,
            'profile' => $profile,
            'recommendations' => $recommendations,
            'available_template_ids' => $availableTemplateIds,
        ]);
    }

    /**
     * @param  array<string, mixed>  $baseStorefront
     * @return array<string, mixed>|null
     */
    public function synthesizeStorefront(Store $store, array $baseStorefront): ?array
    {
        return $this->registry->execute('storefront-writer', [
            'store' => $store,
            'base_storefront' => $baseStorefront,
        ]);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message?: string}|null
     */
    public function applyChatEdit(array $storefront, string $instruction, ?Store $store = null): ?array
    {
        return $this->registry->execute('editor-agent', [
            'storefront' => $storefront,
            'instruction' => $instruction,
            'store' => $store,
        ]);
    }
}
