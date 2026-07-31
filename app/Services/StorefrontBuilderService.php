<?php

namespace App\Services;

use App\Exceptions\StorefrontAiUnavailableException;
use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use App\Models\StorefrontTemplate;
use Illuminate\Support\Str;

class StorefrontBuilderService
{
    public const CHAT_HISTORY_LIMIT = 20;

    /** @var list<string> */
    public const EDITABLE_PATHS = StorefrontPathEditor::BASE_PATHS;

    public function __construct(
        private readonly StorefrontAiAgentService $aiAgent,
        private readonly StorefrontBlockService $blockService,
    ) {}

    public function synthesizeStorefront(Store $store): array
    {
        $businessName = $store->name;
        $industry = $store->merchant?->industry ?? 'other';
        $industryLabel = Str::headline(str_replace('_', ' ', $industry));
        $description = $store->description ?: "{$businessName} helps customers discover quality {$industryLabel} products and services.";
        $contactEmail = $store->contact_email ?? $store->merchant?->owner?->email;
        $templateId = $this->resolveStorefrontTemplate($store);
        $isBeauty = $templateId === 'beauty';
        $isCosmetics = $templateId === 'cosmetics';
        $isHome = $industry === 'home_and_living';
        $hero = $isCosmetics
            ? [
                'headline' => 'Discover the nature with cosmetics.',
                'subheadline' => 'Botanical skincare, clean formulas, and real glow rituals for everyday skin.',
                'cta_label' => 'Discover the line',
            ]
            : ($isBeauty
            ? [
                'headline' => 'Be beautiful, be you.',
                'subheadline' => 'Premium virgin hair extensions created exclusively for natural textures.',
                'cta_label' => 'Shop now',
            ]
            : ($isHome
            ? [
                'headline' => "Light up your space with {$businessName}",
                'subheadline' => $description,
                'cta_label' => 'Shop candles',
            ]
            : [
                'headline' => "Shop {$businessName} online",
                'subheadline' => $description,
                'cta_label' => 'Shop now',
            ]));
        $about = $isCosmetics
            ? [
                'title' => 'Best skin cleanser',
                'body' => "{$businessName} creates gentle cleansers, serums, moisturisers, and routine kits with botanical ingredients and a clean daily skincare point of view.",
            ]
            : ($isBeauty
            ? [
                'title' => 'The heatfree hair difference',
                'body' => "{$businessName} curates natural-texture extensions, closures, ponytails, and care essentials designed to blend beautifully and last longer.",
            ]
            : [
                'title' => "About {$businessName}",
                'body' => $description,
            ]);
        $valueProps = $isCosmetics
            ? [
                ['title' => '100% organic', 'body' => 'Botanical ingredients chosen for gentle daily care.'],
                ['title' => 'Clinical feel', 'body' => 'Simple formulas that support comfort, glow, and consistency.'],
                ['title' => 'Herbal products', 'body' => 'Clean textures made to layer easily in any routine.'],
            ]
            : ($isBeauty
            ? [
                ['title' => 'Undetectable closures', 'body' => 'Seamless finishes made to blend naturally with your hairline.'],
                ['title' => 'Virgin textures', 'body' => 'Soft, full bundles selected for movement, body, and longevity.'],
                ['title' => 'Ready-to-style', 'body' => 'Curated textures, ponytails, and kits for salon-level looks.'],
            ]
            : [
                ['title' => 'Curated for your customers', 'body' => "A focused {$industryLabel} storefront built around what your buyers need most."],
                ['title' => 'Fast local delivery', 'body' => 'Most orders ship within 2-4 business days across Nigeria.'],
                ['title' => 'Built for trust', 'body' => 'Clear messaging, consistent branding, and a simple shopping experience from the first visit.'],
            ]);
        $products = $isCosmetics
            ? [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-botanical-gel-cleanser"), 'name' => 'Botanical Gel Cleanser', 'description' => "A gentle daily cleanser curated by {$businessName}.", 'price' => 18500, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Cleansers'],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-glow-repair-serum"), 'name' => 'Glow Repair Serum', 'description' => 'Lightweight botanical actives for visible radiance and hydration.', 'price' => 24000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Serums'],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-daily-radiance-kit"), 'name' => 'Daily Radiance Kit', 'description' => 'Customer favourites packed for a full skincare routine.', 'price' => 52000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Routine kits'],
            ]
            : ($isBeauty
            ? [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-kurl-wefted-hair"), 'name' => 'The Kurl Wefted Hair', 'description' => "Premium virgin curls from {$businessName}.", 'price' => 68000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Wefted hair'],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-kurl-closure"), 'name' => 'The Kurl Closure', 'description' => 'A seamless closure for fuller protective styling.', 'price' => 42000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Closures'],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-extensions-care-kit"), 'name' => 'Extensions Care Kit', 'description' => 'Cleanse, condition, and protect every install.', 'price' => 14200, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Care kits'],
            ]
            : [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-signature-item"), 'name' => "{$businessName} Signature Item", 'description' => "A customer favourite from {$businessName}.", 'price' => 8500, 'currency' => 'NGN', 'image_url' => null],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-starter-pack"), 'name' => "{$businessName} Starter Pack", 'description' => "A great way to try {$businessName} for the first time.", 'price' => 12500, 'currency' => 'NGN', 'image_url' => null],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-premium-bundle"), 'name' => "{$businessName} Premium Bundle", 'description' => 'Our best-value bundle for repeat customers.', 'price' => 19900, 'currency' => 'NGN', 'image_url' => null],
            ]);

        $storefront = [
            'template' => [
                'id' => $templateId,
                'source' => ($store->storefront_template_id ?? 'ai_pick') === 'ai_pick' ? 'ai_selected' : 'merchant_selected',
            ],
            'palette' => $this->defaultStorefrontPalette($templateId, $store->brand_color ?? null),
            'data_plugs' => [
                'home_products_source' => 'merchant_products',
            ],
            'hero' => $hero,
            'about' => $about,
            'value_props' => $valueProps,
            'navigation' => $this->defaultNavigation($templateId),
            'home_stats' => $isCosmetics
                ? $this->defaultHomeStats($businessName, $industry)
                : [],
            'home_testimonials_title' => $isCosmetics ? 'Testimonials' : null,
            'home_testimonials_intro' => $isCosmetics
                ? "Real feedback from people who shop {$businessName} for everyday {$industryLabel}."
                : null,
            'home_testimonials' => $isCosmetics
                ? $this->defaultHomeTestimonials($businessName, $industry)
                : [],
            'pages' => [
                'about' => [
                    'title' => $about['title'],
                    'body' => $about['body'],
                    'source' => 'ai_generated',
                ],
                'contact' => [
                    'title' => 'Contact us',
                    'body' => 'Have a question about an order or product? Reach out and our team will get back to you shortly.',
                    'email' => $contactEmail,
                    'phone' => $store->contact_phone,
                    'source' => 'ai_generated',
                ],
                'faq' => [
                    'title' => 'Frequently asked questions',
                    'source' => 'ai_generated',
                    'items' => [
                        [
                            'question' => 'How do I place an order?',
                            'answer' => 'Browse products, add items to your cart, and complete checkout with your delivery details.',
                        ],
                        [
                            'question' => 'What payment methods do you accept?',
                            'answer' => 'We accept card payments and bank transfers through our secure checkout.',
                        ],
                        [
                            'question' => 'How long does delivery take?',
                            'answer' => 'Most orders arrive within 2-4 business days depending on your location.',
                        ],
                        [
                            'question' => 'Can I return an item?',
                            'answer' => 'Yes. Contact us within 7 days of delivery if something is not right with your order.',
                        ],
                    ],
                ],
                'privacy_policy' => [
                    'title' => 'Privacy policy',
                    'source' => 'platform_default',
                    'body' => "This privacy policy explains how {$businessName} and Bizgrid collect, use, and protect your personal information when you shop on this storefront.\n\nWe collect information you provide at checkout such as your name, email, phone number, and delivery address. We use this information to process orders, communicate about your purchase, and improve our service.\n\nPayment details are processed securely by our payment partners. We do not store full card numbers on our servers.\n\nYou may contact us to request access to or correction of your personal data.".($contactEmail ? " Email: {$contactEmail}." : ''),
                ],
            ],
            'products' => $products,
            'seo' => [
                'title' => "{$businessName} | Online Store",
                'description' => Str::limit($description, 150, ''),
            ],
            'edit_metadata' => [
                'ai_generated_paths' => [
                    'hero.headline',
                    'hero.subheadline',
                    'hero.cta_label',
                    'about.title',
                    'about.body',
                    'value_props',
                    'home_stats',
                    'home_testimonials_title',
                    'home_testimonials_intro',
                    'home_testimonials',
                    'pages',
                    'seo.title',
                    'seo.description',
                    'products',
                ],
                'user_edited_paths' => [],
                'last_generation_prompt' => null,
                'last_generated_at' => now()->toIso8601String(),
            ],
        ];

        if ($this->aiAgent->available()) {
            try {
                $enhanced = $this->aiAgent->synthesizeStorefront($store, $storefront);
                if (is_array($enhanced)) {
                    // AI enhancement must not change the merchant-selected template.
                    data_set($enhanced, 'template.id', $templateId);
                    data_set(
                        $enhanced,
                        'template.source',
                        ($store->storefront_template_id ?? 'ai_pick') === 'ai_pick' ? 'ai_selected' : 'merchant_selected',
                    );
                    if (empty($enhanced['palette']) || ! is_array($enhanced['palette'])) {
                        $enhanced['palette'] = $this->defaultStorefrontPalette($templateId, $store->brand_color ?? null);
                    }

                    return $this->blockService->ensureAllPageBlocksOnStorefront($enhanced);
                }
            } catch (\Throwable) {
                // Fall back to the locally generated storefront when AI enhancement fails.
            }
        }

        return $this->blockService->ensureAllPageBlocksOnStorefront($storefront);
    }

    public function resolveStorefrontTemplate(Store $store): string
    {
        $templateId = $store->storefront_template_id ?? StorefrontTemplate::DEFAULT_ID;

        if (in_array($templateId, StorefrontTemplate::concreteIds(), true)) {
            return $templateId;
        }

        $industry = $store->merchant?->industry ?? 'other';
        $activeTemplateIds = StorefrontTemplate::activeConcreteIds();
        $fallback = StorefrontTemplate::DEFAULT_ID;
        $firstActive = fn (array $ids, string $default): string => collect($ids)
            ->first(fn (string $id): bool => in_array($id, $activeTemplateIds, true), $default);

        if ($industry === 'beauty_and_skincare') {
            return $firstActive(['cosmetics', 'beauty', $fallback], $fallback);
        }

        if ($industry === 'fashion_and_apparel') {
            return $firstActive(['fashion_lookbook', $fallback], $fallback);
        }

        if (in_array($industry, ['electronics', 'food_and_beverage', 'home_and_living'], true)) {
            return $firstActive([$fallback, 'cosmetics'], $fallback);
        }

        return in_array($fallback, $activeTemplateIds, true) ? $fallback : ($activeTemplateIds[0] ?? $fallback);
    }

    /**
     * @param  list<string>  $changedPaths
     */
    public function describeStorefrontEdit(array $changedPaths): string
    {
        if ($changedPaths === []) {
            return 'I reviewed your request but did not change any protected fields.';
        }

        $labels = array_map(
            fn (string $path): string => StorefrontPathEditor::pathLabel($path),
            $changedPaths,
        );

        if (count($labels) === 1) {
            return "Done — I updated the {$labels[0]}. Check the preview on the right.";
        }

        if (count($labels) === 2) {
            return "Done — I updated the {$labels[0]} and {$labels[1]}. Check the preview on the right.";
        }

        $last = array_pop($labels);

        return 'Done — I updated the '.implode(', ', $labels).", and {$last}. Check the preview on the right.";
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function defaultNavigation(string $templateId): array
    {
        if ($templateId === 'cosmetics') {
            return [
                ['label' => 'Product', 'href' => '/products'],
                ['label' => 'Features', 'href' => '/'],
                ['label' => 'Reviews', 'href' => '/faq'],
                ['label' => 'About us', 'href' => '/about'],
            ];
        }

        return [
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'Products', 'href' => '/products'],
            ['label' => 'About', 'href' => '/about'],
            ['label' => 'Contact', 'href' => '/contact'],
            ['label' => 'FAQ', 'href' => '/faq'],
        ];
    }

    /**
     * Honest default trust/stat copy — never invent fake client counts or ratings.
     *
     * @return list<array{value: string, label: string}>
     */
    private function defaultHomeStats(string $businessName, string $industry): array
    {
        if ($industry === 'beauty_and_skincare') {
            return [
                ['value' => "Crafted for {$businessName} customers", 'label' => 'calm routines, clean formulas'],
                ['value' => 'Everyday glow', 'label' => 'simple steps that layer easily'],
                ['value' => 'Gentle care', 'label' => 'formulas chosen for comfort'],
            ];
        }

        if ($industry === 'fashion_and_apparel') {
            return [
                ['value' => "Styled by {$businessName}", 'label' => 'looks made to wear on repeat'],
                ['value' => 'Season-ready', 'label' => 'fresh edits for everyday dressing'],
                ['value' => 'Easy fit', 'label' => 'pieces chosen for comfort and movement'],
            ];
        }

        return [
            ['value' => "Welcome to {$businessName}", 'label' => 'thoughtful products, clear shopping'],
            ['value' => 'Made with care', 'label' => 'checked before they reach you'],
            ['value' => 'Human support', 'label' => 'real help when you need it'],
        ];
    }

    /**
     * @return list<array{quote: string, author: string}>
     */
    private function defaultHomeTestimonials(string $businessName, string $industry): array
    {
        if ($industry === 'beauty_and_skincare') {
            return [
                ['quote' => "My routine feels simpler with {$businessName}. Soft finish, no fuss.", 'author' => 'Ada'],
                ['quote' => 'Clean textures and clear product pages made shopping easy.', 'author' => 'Tomi'],
                ['quote' => 'A calm everyday set I actually stick with.', 'author' => 'Chioma'],
            ];
        }

        if ($industry === 'fashion_and_apparel') {
            return [
                ['quote' => "{$businessName} pieces layer easily and feel made for real days.", 'author' => 'Maya'],
                ['quote' => 'Clear sizing and a lookbook that helps me decide fast.', 'author' => 'Jordan'],
                ['quote' => 'Simple staples I reach for every week.', 'author' => 'Amara'],
            ];
        }

        return [
            ['quote' => "{$businessName} made shopping feel personal and straightforward.", 'author' => 'Sam'],
            ['quote' => 'Clear product details and helpful support when I needed it.', 'author' => 'Riley'],
            ['quote' => 'Quality I trust and an easy checkout experience.', 'author' => 'Alex'],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     */
    private function setEditablePath(array &$storefront, string $path, string $value): void
    {
        StorefrontPathEditor::apply($storefront, $path, $value);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function recentConversationHistory(
        StorefrontBuilderSession $session,
        ?string $excludeLatestUserMessage = null,
        int $limit = self::CHAT_HISTORY_LIMIT,
    ): array {
        $session->loadMissing('messages');
        $messages = $session->messages->sortBy('created_at')->values();

        if ($excludeLatestUserMessage !== null && $messages->isNotEmpty()) {
            $last = $messages->last();
            if ($last->role === 'user' && trim($last->content) === trim($excludeLatestUserMessage)) {
                $messages = $messages->slice(0, -1);
            }
        }

        return $messages
            ->slice(-$limit)
            ->filter(fn ($message) => in_array($message->role, ['user', 'assistant'], true))
            ->map(fn ($message) => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{role: string, content: string}>  $conversationHistory
     * @return array<string, mixed>
     */
    public function isSubstantiveMessage(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return false;
        }

        if (preg_match('/^(hi|hello|hey|yo|sup|thanks|thank you|ok|okay|cool|great)[\s,!.?]*$/i', $trimmed)) {
            return false;
        }

        if (preg_match('/^(good\s+(morning|afternoon|evening))[\s,!.?]*$/i', $trimmed)) {
            return false;
        }

        if (preg_match('/^(hi|hello|hey)\s+(there|again)[\s,!.?]*$/i', $trimmed)) {
            return false;
        }

        return strlen($trimmed) >= 12
            || preg_match('/\b(sell|store|brand|business|template|skincare|fashion|product|shop|vibe|style|candle|website|site|build)\b/i', $trimmed) === 1;
    }

    public function isDesignChangeIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return preg_match('/\b(another|different|new|other|change|pick|try|switch|choose|select)\b.*\bdesign\b/', $trimmed) === 1
            || preg_match('/\bdesign\b.*\b(for|for a|for my|that fits)\b/', $trimmed) === 1
            || preg_match('/\b(redesign|re-design|new look|fresh look|different look|another look|new layout|different layout|switch layout|change layout)\b/', $trimmed) === 1
            || preg_match('/\b(pick|choose|try|switch to|use)\b.*\b(cosmetics|cosmetic|beauty|skincare|fashion|lookbook|minimalistic|minimal)\b.*\b(design|look|layout|style)\b/', $trimmed) === 1
            || preg_match('/\b(cosmetics|cosmetic|beauty|skincare|fashion|lookbook|minimalistic|minimal)\b.*\b(design|look|layout|style)\b/', $trimmed) === 1
            || preg_match('/\b(switch|change|try|use|pick|go with)\b.*\b(cosmetics|cosmetic|beauty|skincare|fashion|lookbook|minimalistic|minimal)\b/', $trimmed) === 1
            || preg_match('/\b(something else|different vibe|different aesthetic|different feel|not this look|change it up)\b/', $trimmed) === 1
            || preg_match('/\b(i need|i want|looking for|need)\b.*\b(something else|different|new look|new style|a change|fresh look)\b/', $trimmed) === 1;
    }

    public function isRebuildIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return $this->isDesignChangeIntent($message)
            || (preg_match('/\b(build|create|generate|make|switch|rebuild|redo)\b.*\bfor\b/', $trimmed) === 1
                && preg_match('/\b(cosmetics|cosmetic|beauty|skincare|fashion|lookbook|minimalistic|minimal|candle|food|electronics)\b/', $trimmed) === 1)
            || preg_match('/\blets?\s+build\s+for\b/', $trimmed) === 1;
    }

    public function isBuildIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return preg_match('/\b(build|create|generate|make)\b.*\b(website|site|storefront|store|draft)\b/', $trimmed) === 1
            || preg_match('/\b(build my website|generate my website|create my website|yes proceed|yes,? build|go ahead and build|go ahead)\b/', $trimmed) === 1
            || $this->isRebuildIntent($message);
    }

    public function isStockImageIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return preg_match('/\bstock\s+(?:photo|photos|image|images)\b/', $trimmed) === 1
            || preg_match('/\b(add|suggest|provide|use)\s+(?:suitable\s+)?stock\b/', $trimmed) === 1
            || preg_match('/\bsuitable\s+stock\s+(?:photo|photos|image|images)\b/', $trimmed) === 1;
    }

    public function isProductIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return preg_match('/\b(add|create|new|upload|list)\b.*\b(product|products|item|items|sku)\b/', $trimmed) === 1
            || preg_match('/\bi want to add a product\b/', $trimmed) === 1;
    }

    public function isColorIntent(string $message): bool
    {
        if ($this->isDesignChangeIntent($message) || $this->isRebuildIntent($message)) {
            return false;
        }

        $trimmed = strtolower(trim($message));

        if (preg_match('/^#[0-9a-f]{6}$/i', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(brand color|brand colour|colour scheme|color scheme|color palette|colour palette|pallete|palette)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\buse .+#([0-9a-f]{3}|[0-9a-f]{6})\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\b(make it|try|use|switch to|go with|want|lets|let\'s)\b.*\b(color|colour|palette|pallete|scheme)\b/', $trimmed) === 1) {
            return true;
        }

        if ($this->extractColorHintFromMessage($message) !== null) {
            if (preg_match('/\b(ley|let\s*(?:\'s|s)?|lets|i\s+want|want|wanna|get|go|try|use|make\s+it|switch\s+to|change\s+to|can\s+we|could\s+we|how\s+about|give me)\b/i', $message) === 1) {
                return true;
            }

            if (preg_match('/\b(color|colour|brand|palette|tone|shade|hue)\b/i', $message) === 1) {
                return true;
            }

            if (strlen($trimmed) <= 40
                && preg_match('/\b(headline|subheadline|tagline|cta|button|about|contact|faq|seo|hero|copy|premium|minimal|luxury|warmer|friendlier|professional|playful|bold|shorter|longer)\b/', $trimmed) !== 1) {
                return true;
            }
        }

        if ($this->isOpenEndedColorRequest($message)) {
            return true;
        }

        if (preg_match('/\b(give me|pick|choose|select|suggest)\b.*\b(color|colour|shade|hue)\b/i', $message) === 1) {
            return true;
        }

        return preg_match('/\b(make it|try|use|switch to|go with)\b.*\b(green|teal|terracotta|navy|blush|black|burgundy|sage|amber|coral|cream|blue|ocean(?:ic)?|pink|red|purple|rose|gold|mint)\b/', $trimmed) === 1
            || preg_match('/\b(terracotta|teal|navy|blush|burgundy|sage|amber|coral|cream|black|green|blue|ocean(?:ic)?|pink|red|purple|rose|gold|mint|lavender|peach|mauve|indigo|maroon|ruby|emerald|crimson|charcoal|ivory|beige)\b/', $trimmed) === 1;
    }

    public function isOpenEndedColorRequest(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        if (preg_match('/\bsurprise me\b/', $trimmed) === 1) {
            return true;
        }

        if (preg_match('/\b(random|surprise|unexpected|different|fresh|wild|crazy|fun)\b.*\b(color|colour|shade|hue|palette)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\b(give me|pick|choose|select|suggest|show me)\b.*\b(random|any|a?\s*color|a?\s*colour|something|anything)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\b(very\s+)?random\s+(color|colour)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\bany\s+(color|colour)\b/i', $message) === 1) {
            return true;
        }

        if (preg_match('/\bdifferent\s+(color|colour)\b/i', $message) === 1) {
            return true;
        }

        return preg_match('/\bgive me a very random color\b/', $trimmed) === 1;
    }

    public function extractColorHintFromMessage(string $message): ?string
    {
        $lower = strtolower(trim($message));

        if (preg_match('/\bcoral\s+blue\b/i', $message, $matches) === 1) {
            return trim($matches[0]);
        }

        if (preg_match('/\bocean(?:ic)?(?:\s+(?:blue|palette|pallete|scheme))?\b/i', $message, $matches) === 1) {
            return trim($matches[0]);
        }

        if (preg_match('/\bmake\s+it\s+(?:a|an|the|some\s+)?([a-z][a-z\s-]{1,30}?)(?:\s+please|\?|!|$|\.)/i', $message, $matches) === 1) {
            $hint = trim($matches[1]);
            if ($hint !== '' && ! preg_match('/^(color|colour|palette|scheme|brand|more|less)$/i', $hint)) {
                return $hint;
            }
        }

        if (preg_match('/\b(?:ley|let\s*(?:\'s|s)?|lets)\s+try\s+(?:a|an|the|some\s+)?([a-z][a-z\s-]{1,30}?)(?:\s+please|\?|!|$|\.)/i', $message, $matches) === 1) {
            $hint = trim($matches[1]);
            if ($hint !== '') {
                return $hint;
            }
        }

        $words = [
            'terracotta', 'teal', 'navy', 'blush', 'burgundy', 'sage', 'amber', 'coral', 'cream', 'black', 'green',
            'blue', 'oceanic', 'ocean', 'pink', 'rose', 'red', 'purple', 'gold', 'mint', 'lavender', 'peach', 'mauve',
            'indigo', 'maroon', 'ruby', 'emerald', 'crimson', 'charcoal', 'ivory', 'beige', 'yellow', 'orange', 'grey', 'gray',
        ];

        foreach ($words as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/', $lower) === 1) {
                return $word;
            }
        }

        if (preg_match('/\b(?:ley|let\s*(?:\'s|s)?|lets|want|get|go|try|use|make\s+it|switch\s+to|change\s+to|can\s+we|could\s+we)\s+(?:an?\s+|the\s+|some\s+|a\s+)?([a-z][a-z\s-]{1,30}?)(?:\s+please|\?|!|$|\.)/i', $message, $matches) === 1) {
            $hint = trim($matches[1]);
            if ($hint !== '' && ! preg_match('/^(color|colour|palette|scheme|brand)$/i', $hint)) {
                return $hint;
            }
        }

        if (preg_match('/^[a-z][a-z\s-]{1,24}$/', $lower) === 1) {
            return $lower;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{brand_color: string, label: string, palette: array<string, string>}|null
     */
    public function isImageIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));

        return preg_match('/\b(upload|add|use|set|change|replace)\b.*\b(photo|image|picture|header|background|banner)\b/i', $trimmed) === 1
            || $this->isStockImageIntent($message);
    }

    public function isEditIntent(string $message): bool
    {
        $trimmed = strtolower(trim($message));
        if ($trimmed === '' || $this->isBuildIntent($message)) {
            return false;
        }

        if ($this->isColorIntent($message) || $this->isImageIntent($message) || $this->isProductIntent($message)) {
            return false;
        }

        return preg_match('/\b(change|update|edit|rewrite|revise|shorten|lengthen|improve|fix|replace|make (?:the|it)|set (?:the|my))\b/', $trimmed) === 1
            || preg_match('/\b(headline|subheadline|tagline|cta|button|about(?:\s+section|\s+copy|\s+page)?|contact(?:\s+page|\s+intro|\s+copy)?|faq|seo|title|description|copy|hero|value prop|trust)\b/', $trimmed) === 1
            || preg_match('/\b(more premium|more luxury|more minimal|warmer|friendlier|professional|playful|bold|shorter|longer)\b/', $trimmed) === 1;
    }

    public function extractColorFromMessage(string $message): ?string
    {
        if (preg_match('/#([0-9A-Fa-f]{6})\b/', $message, $matches)) {
            return '#'.$matches[1];
        }

        $phrases = [
            '/\bcoral\s+blue\b/i' => '#3D8DAE',
            '/\bocean(?:ic)?(?:\s+(?:blue|palette|pallete|scheme))?\b/i' => '#0077B6',
            '/\belectric\s+blue\b/i' => '#0F4C81',
            '/\bsky\s+blue\b/i' => '#4A90A4',
        ];

        foreach ($phrases as $pattern => $color) {
            if (preg_match($pattern, $message) === 1) {
                return $color;
            }
        }

        $lower = strtolower($message);
        $named = [
            'terracotta' => '#C47A2C',
            'teal' => '#0E7C66',
            'navy' => '#1E3A5F',
            'blush' => '#E6A79F',
            'burgundy' => '#80131B',
            'sage' => '#6B7F5E',
            'amber' => '#D99359',
            'coral' => '#E07A5F',
            'cream' => '#F5E6D3',
            'black' => '#111111',
            'green' => '#2D6A4F',
            'blue' => '#0F4C81',
            'oceanic' => '#0077B6',
            'ocean' => '#0077B6',
            'pink' => '#D4577A',
            'rose' => '#C76B7F',
            'red' => '#B42318',
            'purple' => '#6B4EFF',
            'gold' => '#B8860B',
            'mint' => '#5CB8A8',
            'lavender' => '#9B8EC4',
            'peach' => '#E8A87C',
            'mauve' => '#9D7E9E',
            'indigo' => '#4338CA',
            'maroon' => '#7B2D3B',
            'ruby' => '#9B1B30',
            'emerald' => '#047857',
            'crimson' => '#991B1B',
            'charcoal' => '#36454F',
            'ivory' => '#FFFFF0',
            'beige' => '#C8B89A',
        ];

        foreach ($named as $name => $color) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/', $lower) === 1) {
                return $color;
            }
        }

        return null;
    }

    public function resolveTemplateFromMessage(string $message): ?string
    {
        $lower = strtolower($message);
        $active = StorefrontTemplate::activeConcreteIds();
        $map = [
            'cosmetics' => 'cosmetics',
            'cosmetic' => 'cosmetics',
            'skincare' => 'cosmetics',
            'beauty' => 'beauty',
            'fashion' => 'fashion_lookbook',
            'lookbook' => 'fashion_lookbook',
            'minimalistic' => 'minimalistic',
            'minimal' => 'minimalistic',
        ];

        foreach ($map as $keyword => $templateId) {
            if (str_contains($lower, $keyword) && in_array($templateId, $active, true)) {
                return $templateId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $profile
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
    public function defaultStorefrontPalette(string $templateId, ?string $brandColor = null): array
    {
        return match ($templateId) {
            'cosmetics' => [
                'primary' => $brandColor ?: '#82934C',
                'accent' => '#F7E7D3',
                'background' => '#FFFFFF',
                'surface' => '#F4F6F1',
                'text' => '#172012',
                'muted' => '#6E7564',
                'border' => '#E2E6D9',
            ],
            'beauty' => [
                'primary' => $brandColor ?: '#6F2F2B',
                'accent' => '#E6A79F',
                'background' => '#FFF7F3',
                'surface' => '#FFFFFF',
                'text' => '#211313',
                'muted' => '#80615C',
                'border' => '#F0D6D0',
            ],
            'minimalistic' => [
                'primary' => $brandColor ?: '#073E3F',
                'accent' => '#D99359',
                'background' => '#FBFBDC',
                'surface' => '#FFFFFF',
                'text' => '#073E3F',
                'muted' => '#5F7A6F',
                'border' => '#D8DEC1',
            ],
            'fashion_lookbook' => [
                'primary' => $brandColor ?: '#111111',
                'accent' => '#80131B',
                'background' => '#FFFFFF',
                'surface' => '#EEF0EF',
                'text' => '#111111',
                'muted' => '#6E6E6E',
                'border' => '#E3E3E3',
            ],
            'editorial' => [
                'primary' => $brandColor ?: '#7C3A2D',
                'accent' => '#D8A48F',
                'background' => '#FFFFFF',
                'surface' => '#F8F3F0',
                'text' => '#241613',
                'muted' => '#75615B',
                'border' => '#E8DAD5',
            ],
            'bold_grid' => [
                'primary' => $brandColor ?: '#0F4C81',
                'accent' => '#F59E0B',
                'background' => '#FFFFFF',
                'surface' => '#F3F7FB',
                'text' => '#102033',
                'muted' => '#607085',
                'border' => '#DCE7F2',
            ],
            'furniture-hardware' => [
                'primary' => $brandColor ?: '#2C2416',
                'accent' => '#C4A574',
                'background' => '#F7F3EB',
                'surface' => '#FFFFFF',
                'text' => '#1C1812',
                'muted' => '#7A6E5E',
                'border' => '#E8E0D4',
            ],
            'hair-and-fashion' => [
                'primary' => $brandColor ?: '#1A1410',
                'accent' => '#D4A574',
                'background' => '#FDF8F3',
                'surface' => '#FFFFFF',
                'text' => '#1A1410',
                'muted' => '#7A6B5E',
                'border' => '#EDE4D8',
            ],
            default => [
                'primary' => $brandColor ?: '#1F6F5B',
                'accent' => '#F4B860',
                'background' => '#FFFFFF',
                'surface' => '#F7FAF8',
                'text' => '#10201B',
                'muted' => '#64736E',
                'border' => '#DCE7E1',
            ],
        };
    }

    private function ensureAiAvailable(): void
    {
        if (! $this->aiAgent->available()) {
            throw new StorefrontAiUnavailableException('OpenAI is not configured.');
        }
    }

    /**
     * @return list<string>
     */
    public function colorPresetHexValues(?string $industry = null): array
    {
        $presets = match ($industry) {
            'food_and_beverage', 'home_and_living' => ['#C47A2C', '#2D6A4F', '#5C4033'],
            'fashion_and_apparel' => ['#111111', '#80131B', '#C4A77D'],
            'beauty_and_skincare' => ['#82934C', '#B56B62', '#E6A79F'],
            default => ['#0E7C66', '#C47A2C', '#1E3A5F'],
        };

        return $presets;
    }

    /**
     * @return list<array{type: string, label: string, color: string}>
     */
    public function colorPresetActions(?string $industry = null, int $limit = 3): array
    {
        $presets = match ($industry) {
            'food_and_beverage', 'home_and_living' => [
                ['label' => 'Warm terracotta', 'color' => '#C47A2C'],
                ['label' => 'Forest green', 'color' => '#2D6A4F'],
                ['label' => 'Deep cocoa', 'color' => '#5C4033'],
            ],
            'fashion_and_apparel' => [
                ['label' => 'Classic black', 'color' => '#111111'],
                ['label' => 'Burgundy', 'color' => '#80131B'],
                ['label' => 'Sand neutral', 'color' => '#C4A77D'],
            ],
            'beauty_and_skincare' => [
                ['label' => 'Botanical green', 'color' => '#82934C'],
                ['label' => 'Rose clay', 'color' => '#B56B62'],
                ['label' => 'Soft blush', 'color' => '#E6A79F'],
            ],
            default => [
                ['label' => 'Bizgrid green', 'color' => '#0E7C66'],
                ['label' => 'Warm terracotta', 'color' => '#C47A2C'],
                ['label' => 'Deep navy', 'color' => '#1E3A5F'],
            ],
        };

        return array_map(
            fn (array $preset): array => ['type' => 'color', 'label' => $preset['label'], 'color' => $preset['color']],
            array_slice($presets, 0, $limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggestedActionsFor(array $sessionContext): array
    {
        $industry = $sessionContext['industry'] ?? null;
        $colorActions = $this->colorPresetActions($industry, 2);

        if (! empty($sessionContext['has_storefront_draft'])) {
            return [
                ['type' => 'prompt', 'label' => 'Make it more premium', 'message' => 'Make the homepage more premium'],
                ['type' => 'prompt', 'label' => 'Update shop button', 'message' => 'Change the button to Shop now'],
                ['type' => 'upload', 'label' => 'Upload header photo', 'target' => 'media.hero_image_url'],
                ['type' => 'upload', 'label' => 'Upload about photo', 'target' => 'media.about_image_url'],
                ['type' => 'prompt', 'label' => 'Suggest stock photos', 'message' => 'Add suitable stock photos to my website'],
                ...$colorActions,
            ];
        }

        if (! empty($sessionContext['has_store'])) {
            return [
                ['type' => 'prompt', 'label' => 'Build my website', 'message' => 'build my website'],
                ['type' => 'prompt', 'label' => 'Go ahead', 'message' => 'Go ahead and create my site'],
                ...$this->colorPresetActions($industry, 3),
            ];
        }

        return [
            ['type' => 'prompt', 'label' => 'Handmade candles', 'message' => 'I sell handmade soy candles. Warm, cozy, gift-friendly.'],
            ['type' => 'prompt', 'label' => 'Skincare brand', 'message' => 'Skincare for busy professionals — clean, premium, not flashy.'],
            ...$this->colorPresetActions($industry, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}
     */
    public function applyBrandColor(array $storefront, Store $store, string $brandColor, ?array $palette = null): array
    {
        $templateId = $storefront['template']['id'] ?? null;
        if (! is_string($templateId) || $templateId === '' || $templateId === 'ai_pick') {
            $templateId = $store->storefront_template_id && $store->storefront_template_id !== 'ai_pick'
                ? $store->storefront_template_id
                : 'minimalistic';
        }

        $storefront['template'] = [
            'id' => $templateId,
            'source' => $storefront['template']['source'] ?? 'merchant_selected',
        ];
        $storefront['palette'] = $palette
            ? $this->sanitizeThemePalette($palette, $brandColor)
            : $this->derivePaletteFromPrimary($brandColor);
        $store->brand_color = strtoupper($brandColor);
        $store->save();

        return [
            'storefront' => $storefront,
            'changed_paths' => [
                'palette.primary',
                'palette.accent',
                'palette.background',
                'palette.surface',
                'palette.text',
                'palette.muted',
                'palette.border',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $partial
     * @return array{primary: string, accent: string, background: string, surface: string, text: string, muted: string, border: string}
     */
    public function sanitizeThemePalette(?array $partial, string $fallbackPrimary): array
    {
        $derived = $this->derivePaletteFromPrimary($fallbackPrimary);
        if (! is_array($partial)) {
            return $derived;
        }

        $keys = ['primary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];
        foreach ($keys as $key) {
            $value = $partial[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($value)) === 1) {
                $derived[$key] = strtoupper(trim($value));
            }
        }

        if (isset($partial['primary']) && is_string($partial['primary']) && preg_match('/^#[0-9A-Fa-f]{6}$/', trim($partial['primary'])) === 1) {
            $derived['primary'] = strtoupper(trim($partial['primary']));
        } else {
            $derived['primary'] = strtoupper($fallbackPrimary);
        }

        return $this->ensureReadablePalette($derived);
    }

    /**
     * @param  array{primary: string, accent: string, background: string, surface: string, text: string, muted: string, border: string}  $palette
     * @return array{primary: string, accent: string, background: string, surface: string, text: string, muted: string, border: string}
     */
    public function ensureReadablePalette(array $palette): array
    {
        $minBodyContrast = 4.5;
        $minMutedContrast = 3.0;
        $minButtonContrast = 4.5;
        $white = '#FFFFFF';

        [$bgH, $bgS, $bgL] = $this->hexToHsl($palette['background']);
        if ($bgL < 85) {
            $palette['background'] = $this->hslToHex($bgH, max(0, min(22, $bgS)), max(94, min(98, $bgL + 30)));
        }

        [$surfaceH, $surfaceS, $surfaceL] = $this->hexToHsl($palette['surface']);
        if ($surfaceL < 85) {
            $palette['surface'] = $this->hslToHex($surfaceH, max(0, min(12, $surfaceS)), max(96, min(99, $surfaceL + 30)));
        }

        $palette['text'] = $this->ensureForegroundContrast($palette['text'], $palette['background'], $minBodyContrast);
        if ($this->contrastRatio($palette['text'], $palette['surface']) < $minBodyContrast) {
            $palette['text'] = $this->ensureForegroundContrast($palette['text'], $palette['surface'], $minBodyContrast);
        }

        $palette['muted'] = $this->ensureForegroundContrast($palette['muted'], $palette['background'], $minMutedContrast);

        if ($this->contrastRatio($white, $palette['primary']) < $minButtonContrast) {
            $palette['primary'] = $this->adjustHslForContrast($palette['primary'], $white, $minButtonContrast, 'darker');
        }

        if ($this->contrastRatio($palette['border'], $palette['background']) < 1.15) {
            [$h, $s, $l] = $this->hexToHsl($palette['border']);
            $palette['border'] = $this->hslToHex($h, $s, max(72, min(88, $l - 10)));
        }

        return $palette;
    }

    private function channelLuminance(float $channel): float
    {
        $c = $channel / 255;
        if ($c <= 0.03928) {
            return $c / 12.92;
        }

        return (($c + 0.055) / 1.055) ** 2.4;
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return 0.2126 * $this->channelLuminance((float) $r)
            + 0.7152 * $this->channelLuminance((float) $g)
            + 0.0722 * $this->channelLuminance((float) $b);
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $fg = $this->relativeLuminance($foreground);
        $bg = $this->relativeLuminance($background);
        $lighter = max($fg, $bg);
        $darker = min($fg, $bg);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function bestReadableFallback(string $against, float $minRatio): string
    {
        $dark = '#1A1A1A';
        $light = '#FFFFFF';
        $darkRatio = $this->contrastRatio($dark, $against);
        $lightRatio = $this->contrastRatio($light, $against);

        if ($darkRatio >= $minRatio) {
            return $dark;
        }
        if ($lightRatio >= $minRatio) {
            return $light;
        }

        return $darkRatio >= $lightRatio ? $dark : $light;
    }

    private function adjustHslForContrast(string $color, string $against, float $minRatio, string $direction): string
    {
        if ($this->contrastRatio($color, $against) >= $minRatio) {
            return $color;
        }

        [$h, $s, $l] = $this->hexToHsl($color);
        $step = $direction === 'darker' ? -1.0 : 1.0;

        for ($lightness = $l; $lightness >= 0 && $lightness <= 100; $lightness += $step) {
            $candidate = $this->hslToHex($h, $s, $lightness);
            if ($this->contrastRatio($candidate, $against) >= $minRatio) {
                return $candidate;
            }
            if ($lightness === 0.0 || $lightness === 100.0) {
                break;
            }
        }

        return $this->bestReadableFallback($against, $minRatio);
    }

    private function ensureForegroundContrast(string $foreground, string $background, float $minRatio): string
    {
        if ($this->contrastRatio($foreground, $background) >= $minRatio) {
            return $foreground;
        }

        [, , $fgL] = $this->hexToHsl($foreground);
        [, , $bgL] = $this->hexToHsl($background);
        $direction = $bgL >= $fgL ? 'darker' : 'lighter';

        return $this->adjustHslForContrast($foreground, $background, $minRatio, $direction);
    }

    /**
     * @return array{primary: string, accent: string, background: string, surface: string, text: string, muted: string, border: string}
     */
    public function derivePaletteFromPrimary(string $primary): array
    {
        $normalized = strtoupper($primary);
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $normalized) !== 1) {
            $normalized = '#0E7C66';
        }

        [$h, $s, $l] = $this->hexToHsl($normalized);
        $sat = max(18, min(72, $s));

        return $this->ensureReadablePalette([
            'primary' => $normalized,
            'accent' => $this->hslToHex(fmod($h + 28, 360), max(20, min(55, $sat * 0.75)), max(52, min(78, $l + 18))),
            'background' => $this->hslToHex($h, max(6, min(22, $sat * 0.18)), max(96, min(98, 96 + ($l < 40 ? 2 : 0)))),
            'surface' => $this->hslToHex($h, max(2, min(12, $sat * 0.06)), 99),
            'text' => $this->hslToHex($h, max(10, min(30, $sat * 0.35)), max(10, min(16, 12))),
            'muted' => $this->hslToHex($h, max(12, min(35, $sat * 0.28)), max(38, min(54, 46))),
            'border' => $this->hslToHex($h, max(8, min(28, $sat * 0.22)), max(82, min(90, 86))),
        ]);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $h = 0.0;
        $s = 0.0;
        $l = ($max + $min) / 2;

        if ($max !== $min) {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
            if ($max === $r) {
                $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
            } elseif ($max === $g) {
                $h = (($b - $r) / $d + 2) / 6;
            } else {
                $h = (($r - $g) / $d + 4) / 6;
            }
        }

        return [$h * 360, $s * 100, $l * 100];
    }

    private function hslToHex(float $h, float $s, float $l): string
    {
        $h = fmod($h + 360, 360);
        $s = max(0, min(100, $s)) / 100;
        $l = max(0, min(100, $l)) / 100;

        if ($s === 0.0) {
            $gray = (int) round($l * 255);

            return sprintf('#%02X%02X%02X', $gray, $gray, $gray);
        }

        $hue2rgb = static function (float $p, float $q, float $t): float {
            if ($t < 0) {
                $t += 1;
            }
            if ($t > 1) {
                $t -= 1;
            }
            if ($t < 1 / 6) {
                return $p + ($q - $p) * 6 * $t;
            }
            if ($t < 1 / 2) {
                return $q;
            }
            if ($t < 2 / 3) {
                return $p + ($q - $p) * (2 / 3 - $t) * 6;
            }

            return $p;
        };

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $hk = $h / 360;

        $r = (int) round($hue2rgb($p, $q, $hk + 1 / 3) * 255);
        $g = (int) round($hue2rgb($p, $q, $hk) * 255);
        $b = (int) round($hue2rgb($p, $q, $hk - 1 / 3) * 255);

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  array<string, string>  $mediaUpdates
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}
     */
    public function applyMediaUpdates(array $storefront, array $mediaUpdates): array
    {
        $changedPaths = [];

        foreach ($mediaUpdates as $path => $url) {
            if (! in_array($path, ['media.hero_image_url', 'media.hero_video_url', 'media.about_image_url'], true) || ! is_string($url) || trim($url) === '') {
                continue;
            }

            $this->setEditablePath($storefront, $path, trim($url));
            $changedPaths[] = $path;
        }

        return [
            'storefront' => $storefront,
            'changed_paths' => $changedPaths,
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}
     */
    public function applyStockImages(array $storefront, Store $store): array
    {
        $templateId = $store->storefront_template_id && $store->storefront_template_id !== 'ai_pick'
            ? $store->storefront_template_id
            : ($storefront['template']['id'] ?? 'cosmetics');

        $images = match ($templateId) {
            'beauty' => [
                'hero' => 'https://images.unsplash.com/photo-1612817288484-6f916006741a?auto=format&fit=crop&w=1400&q=90',
                'about' => 'https://images.unsplash.com/photo-1612817288484-6f916006741a?auto=format&fit=crop&w=1400&q=90',
            ],
            'fashion_lookbook' => [
                'hero' => 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=1800&q=90',
                'about' => 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=1400&q=90',
            ],
            'minimalistic' => [
                'hero' => 'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=1800&q=90',
                'about' => 'https://images.unsplash.com/photo-1556228578-8c89e6adf883?auto=format&fit=crop&w=1400&q=90',
            ],
            default => [
                'hero' => 'https://images.unsplash.com/photo-1749599018738-b8fb6c4a83e0?auto=format&fit=crop&w=1400&q=90',
                'about' => 'https://images.unsplash.com/photo-1612817288484-6f916006741a?auto=format&fit=crop&w=1400&q=90',
            ],
        };

        return $this->applyMediaUpdates($storefront, [
            'media.hero_image_url' => $images['hero'],
            'media.about_image_url' => $images['about'],
        ]);
    }

    /**
     * @template T
     *
     * @param  T|null  $result
     * @return T
     */
    private function requireAiResult(mixed $result, string $context): mixed
    {
        if ($result === null) {
            throw new StorefrontAiUnavailableException(
                "Storefront AI could not complete {$context}. Please try again.",
            );
        }

        return $result;
    }
}
