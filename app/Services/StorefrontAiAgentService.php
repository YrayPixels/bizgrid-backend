<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StorefrontAiAgentService
{
    private const INDUSTRIES = [
        'food_and_beverage',
        'fashion_and_apparel',
        'beauty_and_skincare',
        'electronics',
        'home_and_living',
        'services',
        'other',
    ];

    public function __construct(
        private readonly StorefrontPageBlockService $pageBlockService,
    ) {}

    public function available(): bool
    {
        return filled(config('openai.api_key'));
    }

    /**
     * @param  array<string, mixed>  $currentProfile
     * @return array<string, mixed>|null
     */
    public function extractBusinessProfile(string $message, array $currentProfile = []): ?array
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Interpreter agent for Storehaus onboarding.',
                    'Extract a storefront business profile from the latest merchant message.',
                    'Return ONLY valid JSON with keys: business_name, description, industry, brand_color, tone.',
                    'industry must be one of: '.implode(', ', self::INDUSTRIES).'.',
                    'Use null for unknown scalar values. tone must be an array of short lowercase style words.',
                    'Do not invent facts the user did not imply.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'current_profile' => $currentProfile,
                    'message' => $message,
                ]),
            ],
        ], 0.2);

        if (! is_array($result)) {
            return null;
        }

        return $this->sanitizeProfile($this->mergeProfile($currentProfile, $result));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{brand_color: string, label: string, palette: array<string, string>}|null
     */
    public function resolveBrandColorFromMessage(string $message, array $context = [], bool $randomPick = false): ?array
    {
        $systemLines = [
            'You choose cohesive website color palettes for small business storefronts.',
            'Return ONLY valid JSON with keys:',
            '- "label": short palette name',
            '- "brand_color": "#RRGGBB" — same as palette.primary',
            '- "palette": object with ALL keys primary, accent, background, surface, text, muted, border — each a six-digit hex',
            'primary must reach at least 4.5:1 contrast against white (#FFFFFF) for button labels — avoid pale pastels unless darkened enough.',
            'text must be dark (#111–#333) with at least 4.5:1 contrast on both background and surface.',
            'background and surface must stay very light (#F5–#FFF); muted must be at least 3:1 on background — never light gray on off-white.',
            'CRITICAL: Never pair similar-lightness text and backgrounds. Verify readability before returning.',
            'The full palette must work together across a storefront page — not primary alone.',
            'Interpret ANY color name or mood: pink, soft lavender, earthy brown, warm sunset, ocean blue, etc.',
        ];

        if ($randomPick) {
            $systemLines[] = 'The merchant asked for a random or surprise palette — pick a distinctive scheme that fits their industry and differs from current_palette if provided.';
        }

        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", $systemLines),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'merchant_request' => $message,
                    'business_name' => $context['business_name'] ?? null,
                    'industry' => $context['industry'] ?? null,
                    'description' => $context['description'] ?? null,
                    'current_brand_color' => $context['brand_color'] ?? null,
                    'current_palette' => $context['current_palette'] ?? null,
                    'wants_random_color' => $randomPick,
                ]),
            ],
        ], $randomPick ? 0.95 : 0.35);

        if (! is_array($result)) {
            return null;
        }

        $brandColor = is_string($result['brand_color'] ?? null) ? trim($result['brand_color']) : '';
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            $palettePrimary = is_array($result['palette'] ?? null)
                ? ($result['palette']['primary'] ?? null)
                : null;
            $brandColor = is_string($palettePrimary) ? trim($palettePrimary) : '';
        }

        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            return null;
        }

        $label = is_string($result['label'] ?? null) && trim($result['label']) !== ''
            ? trim($result['label'])
            : ($randomPick ? 'Surprise palette' : 'Custom palette');

        /** @var StorefrontBuilderService $builder */
        $builder = app(StorefrontBuilderService::class);
        $palette = $builder->sanitizeThemePalette(
            is_array($result['palette'] ?? null) ? $result['palette'] : null,
            strtoupper($brandColor),
        );

        return [
            'brand_color' => strtoupper($brandColor),
            'label' => $label,
            'palette' => $palette,
        ];
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
        if (! $availableTemplateIds) {
            $availableTemplateIds = StorefrontTemplate::activeConcreteIds();
        }

        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are a storefront design director for small business websites.',
                    'Given a merchant request, pick the BEST matching website design from the catalog and a cohesive brand color palette.',
                    'Return ONLY valid JSON with keys:',
                    '- "template_id": string — must be one of the catalog ids exactly',
                    '- "brand_color": "#RRGGBB" — primary button/brand color with enough contrast for white text',
                    '- "color_label": string — short name for the primary color',
                    '- "palette": array of 3-4 objects {"color": "#RRGGBB", "label": "short name"} — harmonious palette including brand_color first',
                    'Contrast rules (WCAG AA): text on background/surface at least 4.5:1; muted on background at least 3:1; primary vs white at least 4.5:1 for button labels.',
                    '- "industry": optional string — one of food_and_beverage, fashion_and_apparel, beauty_and_skincare, electronics, home_and_living, services, other',
                    '- "tone": optional array of tone words',
                    '- "merchant_summary": string — one short phrase describing the look in plain language WITHOUT the words template, theme, or layout',
                    'Match the merchant\'s described business type, vibe, and aesthetic — not just keywords.',
                    'Cosmetic/skincare/beauty shops → prefer cosmetics or beauty.',
                    'Clothing/streetwear/fashion → prefer fashion_lookbook.',
                    'Wellness/minimal/calm catalogs → prefer minimalistic.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'merchant_request' => $message,
                    'business_name' => $context['business_name'] ?? null,
                    'industry' => $context['industry'] ?? null,
                    'description' => $context['description'] ?? null,
                    'current_brand_color' => $context['brand_color'] ?? null,
                    'current_template_id' => $context['current_template_id'] ?? null,
                    'available_templates' => $templateCatalog,
                ]),
            ],
        ], 0.4);

        if (! is_array($result)) {
            return null;
        }

        $templateId = is_string($result['template_id'] ?? null) ? trim($result['template_id']) : '';
        if ($templateId === '' || ! in_array($templateId, $availableTemplateIds, true)) {
            return null;
        }

        $brandColor = is_string($result['brand_color'] ?? null) ? trim($result['brand_color']) : '';
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $brandColor) !== 1) {
            return null;
        }

        $palette = [];
        if (is_array($result['palette'] ?? null)) {
            foreach ($result['palette'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $color = is_string($entry['color'] ?? null) ? trim($entry['color']) : '';
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                    continue;
                }
                $label = is_string($entry['label'] ?? null) && trim($entry['label']) !== ''
                    ? trim($entry['label'])
                    : strtoupper($color);
                $palette[] = ['color' => strtoupper($color), 'label' => $label];
            }
        }

        $colorLabel = is_string($result['color_label'] ?? null) && trim($result['color_label']) !== ''
            ? trim($result['color_label'])
            : ($palette[0]['label'] ?? 'Brand color');

        if ($palette === []) {
            $palette[] = ['color' => strtoupper($brandColor), 'label' => $colorLabel];
        }

        $industry = is_string($result['industry'] ?? null) ? trim($result['industry']) : null;
        $validIndustries = [
            'food_and_beverage',
            'fashion_and_apparel',
            'beauty_and_skincare',
            'electronics',
            'home_and_living',
            'services',
            'other',
        ];
        if (! in_array($industry, $validIndustries, true)) {
            $industry = is_string($context['industry'] ?? null) ? $context['industry'] : null;
            if (! in_array($industry, $validIndustries, true)) {
                $industry = null;
            }
        }

        $tone = [];
        if (is_array($result['tone'] ?? null)) {
            foreach ($result['tone'] as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $tone[] = trim($entry);
                }
            }
        }

        $merchantSummary = is_string($result['merchant_summary'] ?? null) && trim($result['merchant_summary']) !== ''
            ? trim($result['merchant_summary'])
            : 'a refreshed look with '.$colorLabel.' tones';

        return [
            'template_id' => $templateId,
            'brand_color' => strtoupper($brandColor),
            'color_label' => $colorLabel,
            'palette' => array_slice($palette, 0, 4),
            'industry' => $industry,
            'tone' => $tone,
            'merchant_summary' => $merchantSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     */
    public function respondToConversation(string $message, array $sessionContext): ?string
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the StoreHause storefront builder assistant talking to a small business owner.',
                    'Return ONLY valid JSON with key: assistant_message.',
                    'assistant_message should be 1-3 short sentences. Be warm, confident, and practical.',
                    'Speak like a helpful shop consultant — never mention templates, themes, tools, agents, JSON, hero, or CTA.',
                    'Use the merchant\'s own words when reflecting their business back to them.',
                    'Ask at most one clarifying question at a time.',
                    'If the merchant only greeted you, greet them back and explain the next step for their current session state.',
                    'If a draft exists, invite them to request copy changes and check the preview on the right.',
                    'If they have a store but no draft, tell them to say "build my website" when ready.',
                    'If setup is incomplete, ask for business name and what they sell with a short example.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'message' => $message,
                    'session' => $sessionContext,
                ]),
            ],
        ], 0.4);

        $reply = is_array($result) ? ($result['assistant_message'] ?? $result['message'] ?? null) : null;

        return is_string($reply) && trim($reply) !== '' ? trim($reply) : null;
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
        $result = $this->chatWithTools([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the StoreHause Builder Orchestrator agent.',
                    'Use the provided tools to advance the merchant through website setup.',
                    'Merchant-facing replies must be warm, short, and free of jargon — never mention templates, themes, tools, or agents.',
                    'Call one or more tools when they help move the session forward.',
                    'Use recommend_templates only when the merchant asks for options — otherwise pick the best fit silently.',
                    'Use select_template only with available template IDs. Never invent template IDs. Do not expose template names to the merchant.',
                    'Use generate_draft only when a template is selected, or call select_template first.',
                    'Use ask_clarifying_question when required profile or intent is still missing — one question only.',
                    'If the merchant asks you to build, create, draft, generate, or "go ahead", prefer selecting the top recommendation if no template is selected, then generate_draft.',
                    'If a storefront draft already exists and the user asks for copy changes, do not use these tools; the edit endpoint handles that separately.',
                    'Briefly explain what you are doing in plain language when you call tools.',
                    'Available template IDs: '.implode(', ', $availableTemplateIds).'.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'latest_message' => $message,
                    'session' => $sessionContext,
                    'profile' => $profile,
                    'recommendations' => $recommendations,
                ]),
            ],
        ], $this->builderToolDefinitions($availableTemplateIds), 0.25);

        if (! is_array($result)) {
            return null;
        }

        $assistantMessage = is_string($result['content'] ?? null) ? trim($result['content']) : '';
        $toolCalls = $this->sanitizeToolCalls(
            $this->parseNativeToolCalls($result['tool_calls'] ?? []),
            $availableTemplateIds,
        );

        if ($assistantMessage === '' && $toolCalls !== []) {
            foreach ($toolCalls as $toolCall) {
                if ($toolCall['name'] === 'ask_clarifying_question') {
                    $question = $toolCall['arguments']['question'] ?? null;
                    if (is_string($question) && trim($question) !== '') {
                        $assistantMessage = trim($question);
                        break;
                    }
                }
            }
        }

        if ($assistantMessage === '' && $toolCalls === []) {
            return null;
        }

        return [
            'assistant_message' => $assistantMessage !== '' ? $assistantMessage : 'I have a plan for the next storefront step.',
            'plan' => [],
            'tool_calls' => $toolCalls,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseStorefront
     * @return array<string, mixed>|null
     */
    public function synthesizeStorefront(Store $store, array $baseStorefront): ?array
    {
        $store->loadMissing('merchant');
        $templateId = Arr::get($baseStorefront, 'template.id', $store->storefront_template_id ?? 'minimalistic');

        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus Storefront Writer agent.',
                    'Create concise, conversion-focused storefront content for a merchant.',
                    'Return ONLY valid JSON matching the provided base storefront shape.',
                    'Preserve all top-level keys from the base storefront.',
                    'Preserve template, palette, data_plugs, edit_metadata, and product IDs/slugs/currency/image_url values.',
                    'You may improve hero, about, value_props, pages.about, pages.contact.body, pages.faq.items, products names/descriptions/categories, and seo.',
                    'Keep copy honest and grounded in the merchant profile. Do not claim certifications, delivery speeds, or guarantees unless supplied.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'merchant' => [
                        'business_name' => $store->name,
                        'industry' => $store->merchant?->industry ?? 'other',
                        'description' => $store->description,
                        'contact_email' => $store->merchant?->email,
                        'template_id' => $templateId,
                    ],
                    'base_storefront' => $baseStorefront,
                ]),
            ],
        ], 0.55);

        if (! is_array($result)) {
            return null;
        }

        return $this->normalizeStorefront($baseStorefront, $result);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message?: string}|null
     */
    public function applyChatEdit(array $storefront, string $instruction, ?Store $store = null): ?array
    {
        $result = $this->chatJson([
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'You are the Storehaus Storefront Editor agent.',
                    'Apply the merchant instruction as a small structured patch across any page (home, about, contact, faq).',
                    'Return ONLY valid JSON: {"updates": object, "operations": array, "changed_paths": string[], "assistant_message": string}.',
                    'Flat copy paths (updates): '.implode(', ', StorefrontPathEditor::promptAllowedPaths()).'.',
                    'Use dot-path keys inside updates, for example {"hero.headline": "New headline"} or {"pages.contact.body": "Reach us anytime."}.',
                    'Homepage sections — prefer update_block for multi-field rewrites, or matching flat paths:',
                    '- hero-main: eyebrow, headline, subheadline, cta_label (or hero.* / pages.home.blocks.hero-main.props.eyebrow)',
                    '- home-stats: props.items[{value,label}] (or home_stats[N].value / home_stats[N].label)',
                    '- about-spotlight: title, body, badges (or about.* / value_props[N].*)',
                    '- serum-promo: title, body, bullets[], cta_label',
                    '- trust-features: title, body, items[{title,body}]',
                    '- testimonials: home_testimonials_title, home_testimonials_intro, home_testimonials[N].quote / author',
                    'Block operations (operations) — prefer these for section-level layout/copy on any page:',
                    '- update_block: {"op":"update_block","page":"about|contact|faq|home","block_id":"...","props":{...}}',
                    '- regenerate_section: {"op":"regenerate_section","page":"...","block_id":"..."} when the merchant asks to redesign/refresh/fix a whole section',
                    '- reorder_blocks: {"op":"reorder_blocks","page":"home","order":["hero-main", "..."]}',
                    '- remove_block: {"op":"remove_block","page":"...","block_id":"..."} — never remove hero-main, about-main, contact-form, or faq-main',
                    'Registered block types: hero, stats_row, rich_text, feature_grid, cta_banner, product_grid, faq, contact_form.',
                    'Common block ids: hero-main, home-stats, about-spotlight, serum-promo, trust-features, home-faq, about-main, about-features, contact-intro, contact-form, faq-main.',
                    'If the merchant asks to update stats, testimonials, serum promo, why choose us, or hero eyebrow, apply the matching paths or update_block props above.',
                    'Respect edit_metadata.locked on blocks — skip locked blocks.',
                    'For FAQ entries you may use pages.faq.items[N].question and pages.faq.items[N].answer, or update_block on faq-main with props.items.',
                    'If the merchant asks to update, refresh, or improve FAQ without specifics, rewrite all FAQ items tailored to their business.',
                    'Keep about.title/body and pages.about.title/body aligned when editing about copy.',
                    'Do not change locked fields, products, template, palette, or unrelated copy.',
                    'Prefer applying sensible inferred updates over asking clarifying questions. Only ask when a required detail is impossible to infer.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'instruction' => $instruction,
                    'current_storefront' => $this->storefrontEditorContext($storefront),
                ]),
            ],
        ], 0.35);

        if (! is_array($result)) {
            return null;
        }

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

        return Arr::only(array_merge($storefront, ['pages' => $pages]), [
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

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, string>
     */
    private function flattenStorefrontUpdates(array $updates): array
    {
        $flat = [];

        foreach ($updates as $path => $value) {
            if (is_string($path) && is_string($value) && trim($value) !== '') {
                $flat[$path] = trim($value);

                continue;
            }

            if (! is_string($path) || ! is_array($value)) {
                continue;
            }

            if ($path === 'hero' || $path === 'about' || $path === 'seo') {
                foreach ($value as $field => $fieldValue) {
                    if (! is_string($field) || ! is_string($fieldValue) || trim($fieldValue) === '') {
                        continue;
                    }

                    $flat["{$path}.{$field}"] = trim($fieldValue);
                }
            }
        }

        return $flat;
    }

    /**
     * @param  list<string>  $availableTemplateIds
     * @return list<array<string, mixed>>
     */
    private function builderToolDefinitions(array $availableTemplateIds): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'recommend_templates',
                    'description' => 'Show ranked storefront template recommendations for the merchant.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'select_template',
                    'description' => 'Select one storefront template for the merchant.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'template_id' => [
                                'type' => 'string',
                                'enum' => array_values($availableTemplateIds),
                                'description' => 'The template ID to select.',
                            ],
                            'source' => [
                                'type' => 'string',
                                'enum' => ['ai_selected', 'merchant_selected'],
                                'description' => 'Whether the AI or merchant chose the template.',
                            ],
                        ],
                        'required' => ['template_id'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_draft',
                    'description' => 'Generate the first storefront draft with hero copy, about section, FAQs, SEO, and sample products.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) [],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'ask_clarifying_question',
                    'description' => 'Ask the merchant for missing business details or intent before proceeding.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => [
                                'type' => 'string',
                                'description' => 'A short clarifying question for the merchant.',
                            ],
                        ],
                        'required' => ['question'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: list<array<string, mixed>>}|null
     */
    private function chatWithTools(array $messages, array $tools, float $temperature): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('openai.chat_model', 'gpt-4o-mini'),
                    'temperature' => $temperature,
                    'messages' => $messages,
                    'tools' => $tools,
                    'tool_choice' => 'auto',
                ]);

            if (! $response->successful()) {
                Log::warning('Storefront AI agent tool request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $message = $response->json('choices.0.message');
            if (! is_array($message)) {
                return null;
            }

            $toolCalls = $message['tool_calls'] ?? [];

            return [
                'content' => is_string($message['content'] ?? null) ? $message['content'] : null,
                'tool_calls' => is_array($toolCalls) ? $toolCalls : [],
            ];
        } catch (\Throwable $e) {
            Log::warning('Storefront AI agent tool exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nativeToolCalls
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function parseNativeToolCalls(array $nativeToolCalls): array
    {
        $calls = [];

        foreach ($nativeToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $function = $toolCall['function'] ?? null;
            if (! is_array($function)) {
                continue;
            }

            $name = $function['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $argumentsRaw = $function['arguments'] ?? '{}';
            $decoded = json_decode(is_string($argumentsRaw) ? $argumentsRaw : '{}', true);

            $calls[] = [
                'name' => $name,
                'arguments' => is_array($decoded) ? $decoded : [],
            ];
        }

        return $calls;
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>|null
     */
    private function chatJson(array $messages, float $temperature): ?array
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('openai.api_key'))
                ->acceptJson()
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('openai.chat_model', 'gpt-4o-mini'),
                    'temperature' => $temperature,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => $messages,
                ]);

            if (! $response->successful()) {
                Log::warning('Storefront AI agent request failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 500),
                ]);

                return null;
            }

            $content = $response->json('choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return null;
            }

            $decoded = json_decode($content, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            Log::warning('Storefront AI agent exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  mixed  $rawToolCalls
     * @param  list<string>  $availableTemplateIds
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(mixed $rawToolCalls, array $availableTemplateIds): array
    {
        if (! is_array($rawToolCalls)) {
            return [];
        }

        $allowedTools = ['recommend_templates', 'select_template', 'generate_draft', 'ask_clarifying_question'];
        $calls = [];

        foreach ($rawToolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $name = $toolCall['name'] ?? null;
            if (! is_string($name) || ! in_array($name, $allowedTools, true)) {
                continue;
            }

            $arguments = is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];

            if ($name === 'select_template') {
                $templateId = $arguments['template_id'] ?? null;
                if (! is_string($templateId) || ! in_array($templateId, $availableTemplateIds, true)) {
                    continue;
                }

                $source = $arguments['source'] ?? 'ai_selected';
                $arguments = [
                    'template_id' => $templateId,
                    'source' => $source === 'merchant_selected' ? 'merchant_selected' : 'ai_selected',
                ];
            }

            if ($name === 'ask_clarifying_question') {
                $question = $arguments['question'] ?? null;
                if (! is_string($question) || trim($question) === '') {
                    continue;
                }

                $arguments = ['question' => trim($question)];
            }

            $calls[] = [
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $currentProfile
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function mergeProfile(array $currentProfile, array $extracted): array
    {
        $merged = $currentProfile;

        foreach ($extracted as $key => $value) {
            if ($key === 'tone') {
                if (is_array($value) && $value !== []) {
                    $merged['tone'] = $value;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function sanitizeProfile(array $profile): array
    {
        $industry = $profile['industry'] ?? null;
        if (! is_string($industry) || ! in_array($industry, self::INDUSTRIES, true)) {
            $profile['industry'] = null;
        }

        if (isset($profile['brand_color']) && (! is_string($profile['brand_color']) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $profile['brand_color']))) {
            $profile['brand_color'] = null;
        }

        $profile['tone'] = array_values(array_unique(array_filter(
            array_map('strval', is_array($profile['tone'] ?? null) ? $profile['tone'] : []),
            fn (string $tone): bool => trim($tone) !== '',
        )));

        foreach (['business_name', 'description'] as $key) {
            if (isset($profile[$key]) && is_string($profile[$key])) {
                $profile[$key] = trim($profile[$key]) ?: null;
            }
        }

        return $profile;
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

        return $storefront;
    }
}
