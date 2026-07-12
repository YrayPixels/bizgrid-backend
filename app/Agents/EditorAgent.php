<?php

namespace App\Agents;

use App\Models\Store;
use App\Services\PromptService;
use App\Services\StorefrontPageBlockService;
use App\Services\StorefrontPathEditor;

class EditorAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
        private readonly StorefrontPageBlockService $pageBlockService,
    ) {}

    public function name(): string
    {
        return 'editor-agent';
    }

    public function temperature(): float
    {
        return 0.35;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'updates' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'operations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'op' => ['type' => 'string'],
                            'page' => ['type' => 'string'],
                            'block_id' => ['type' => ['string', 'null']],
                            'props' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            'order' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['op'],
                        'additionalProperties' => false,
                    ],
                ],
                'changed_paths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'assistant_message' => ['type' => 'string'],
            ],
            'required' => ['updates', 'operations', 'changed_paths', 'assistant_message'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message?: string}|null
     */
    public function execute(array $context): ?array
    {
        $storefront = $context['storefront'] ?? [];
        $instruction = $context['instruction'] ?? '';
        $store = $context['store'] ?? null;

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'instruction' => $instruction,
                    'current_storefront' => $this->storefrontEditorContext($storefront),
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        return $this->applyPatch($storefront, $result, $instruction, $store);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  array<string, mixed>  $result
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message?: string}|null
     */
    private function applyPatch(array $storefront, array $result, string $instruction, ?Store $store): ?array
    {
        $storefront = json_decode(json_encode($storefront), true);
        if (! is_array($storefront)) {
            return null;
        }

        $changedPaths = [];

        $operations = is_array($result['operations'] ?? null) ? $result['operations'] : [];
        if ($operations !== []) {
            $operationResult = $this->pageBlockService->applyAiBlockOperations($storefront, $operations, $store);
            $storefront = $operationResult['storefront'];
            $changedPaths = array_merge($changedPaths, $operationResult['changed_paths']);
        }

        $updates = StorefrontPathEditor::flattenUpdates(is_array($result['updates'] ?? null) ? $result['updates'] : []);
        $changedPaths = array_merge($changedPaths, StorefrontPathEditor::applyMany($storefront, $updates));

        $metadata = $storefront['edit_metadata'] ?? [
            'ai_generated_paths' => [],
            'user_edited_paths' => [],
        ];
        $lockedPaths = is_array($metadata['locked_paths'] ?? null) ? $metadata['locked_paths'] : [];
        $userEditedPaths = is_array($metadata['user_edited_paths'] ?? null) ? $metadata['user_edited_paths'] : [];
        $aiGeneratedPaths = is_array($metadata['ai_generated_paths'] ?? null) ? $metadata['ai_generated_paths'] : [];

        $changedPaths = array_values(array_filter(
            $changedPaths,
            fn (string $path): bool => ! in_array($path, $lockedPaths, true),
        ));

        foreach ($changedPaths as $path) {
            $userEditedPaths[] = $path;
            $aiGeneratedPaths = array_values(array_diff($aiGeneratedPaths, [$path]));
        }

        $changedPaths = array_values(array_unique($changedPaths));

        if ($changedPaths === []) {
            return null;
        }

        $storefront = $this->pageBlockService->maybeSyncHomeBlocksFromLegacyPaths($storefront, $changedPaths);

        $storefront['edit_metadata'] = array_merge($metadata, [
            'user_edited_paths' => array_values(array_unique($userEditedPaths)),
            'ai_generated_paths' => $aiGeneratedPaths,
            'last_generation_prompt' => $instruction,
            'last_generated_at' => now()->toIso8601String(),
        ]);

        return [
            'storefront' => $storefront,
            'changed_paths' => $changedPaths,
            'assistant_message' => is_string($result['assistant_message'] ?? null) && trim($result['assistant_message']) !== ''
                ? trim($result['assistant_message'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    private function storefrontEditorContext(array $storefront): array
    {
        $pages = is_array($storefront['pages'] ?? null) ? $storefront['pages'] : [];

        foreach (['home', 'about', 'contact', 'faq'] as $page) {
            $pages[$page] = array_merge(is_array($pages[$page] ?? null) ? $pages[$page] : [], [
                'blocks' => $this->pageBlockService->resolvePageBlocks($storefront, $page),
            ]);
        }

        return \Illuminate\Support\Arr::only(array_merge($storefront, ['pages' => $pages]), [
            'hero',
            'about',
            'seo',
            'media',
            'value_props',
            'navigation',
            'home_stats',
            'home_testimonials_title',
            'home_testimonials_intro',
            'home_testimonials',
            'pages',
            'edit_metadata',
        ]);
    }
}
