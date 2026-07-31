<?php

namespace App\Agents;

use App\Models\Store;
use App\Services\PromptService;
use Illuminate\Support\Arr;

class StorefrontWriterAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'storefront-writer';
    }

    public function temperature(): float
    {
        return 0.55;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    /**
     * The storefront writer uses the base storefront shape as its output schema,
     * so we don't define a fixed schema — it depends on the incoming base storefront.
     * We fall back to json_object mode.
     */
    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $store = $context['store'] ?? null;
        $baseStorefront = $context['base_storefront'] ?? [];

        if (! ($store instanceof Store)) {
            return null;
        }

        $store->loadMissing('merchant');
        $templateId = Arr::get($baseStorefront, 'template.id', $store->storefront_template_id ?? 'minimalistic');

        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'merchant' => [
                        'business_name' => $store->name,
                        'industry' => $store->merchant?->industry ?? 'other',
                        'description' => $store->description,
                        'contact_email' => $store->contact_email ?? $store->merchant?->owner?->email,
                        'template_id' => $templateId,
                    ],
                    'base_storefront' => $baseStorefront,
                ]),
            ],
        ]);

        if (! is_array($result)) {
            return null;
        }

        return $this->normalizeStorefront($baseStorefront, $result);
    }

    /**
     * Keep stats_row block props aligned with top-level home_stats after generation.
     *
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    private function syncHomeStatsIntoBlocks(array $storefront): array
    {
        $homeStats = $storefront['home_stats'] ?? null;
        if (! is_array($homeStats) || $homeStats === []) {
            return $storefront;
        }

        $blocks = data_get($storefront, 'pages.home.blocks');
        if (! is_array($blocks)) {
            return $storefront;
        }

        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'stats_row') {
                continue;
            }

            $blocks[$index]['props'] = array_merge(
                is_array($block['props'] ?? null) ? $block['props'] : [],
                ['items' => array_values($homeStats)],
            );
        }

        data_set($storefront, 'pages.home.blocks', $blocks);

        return $storefront;
    }

    /**
     * @param  array<string, mixed>  $baseStorefront
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function normalizeStorefront(array $baseStorefront, array $candidate): array
    {
        $storefront = array_replace_recursive($baseStorefront, Arr::only($candidate, [
            'hero',
            'about',
            'value_props',
            'navigation',
            'home_stats',
            'home_testimonials_title',
            'home_testimonials_intro',
            'home_testimonials',
            'pages',
            'products',
            'seo',
        ]));

        $storefront['template'] = $baseStorefront['template'] ?? $storefront['template'] ?? null;
        $storefront['palette'] = $baseStorefront['palette'] ?? $storefront['palette'] ?? null;
        $storefront['data_plugs'] = $baseStorefront['data_plugs'] ?? $storefront['data_plugs'] ?? null;
        $storefront['edit_metadata'] = array_merge($baseStorefront['edit_metadata'] ?? [], [
            'last_generation_prompt' => 'storefront_ai_agent',
            'last_generated_at' => now()->toIso8601String(),
        ]);

        return $this->syncHomeStatsIntoBlocks($storefront);
    }
}
