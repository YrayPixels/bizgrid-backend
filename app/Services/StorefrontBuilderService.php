<?php

namespace App\Services;

use App\Exceptions\StorefrontAiUnavailableException;
use App\Models\Store;
use App\Models\StorefrontTemplate;
use Illuminate\Support\Str;

class StorefrontBuilderService
{
    /** @var list<string> */
    public const EDITABLE_PATHS = [
        'hero.headline',
        'hero.subheadline',
        'hero.cta_label',
        'about.title',
        'about.body',
        'seo.title',
        'seo.description',
    ];

    public function __construct(
        private readonly StorefrontAiAgentService $aiAgent,
    ) {}

    public function synthesizeStorefront(Store $store): array
    {
        $businessName = $store->name;
        $industry = $store->merchant?->industry ?? 'other';
        $industryLabel = Str::headline(str_replace('_', ' ', $industry));
        $description = $store->description ?: "{$businessName} helps customers discover quality {$industryLabel} products and services.";
        $contactEmail = $store->merchant?->email;
        $templateId = $this->resolveStorefrontTemplate($store);
        $isBeauty = $templateId === 'beauty';
        $isCosmetics = $templateId === 'cosmetics';
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
            : [
                'headline' => "Shop {$businessName} online",
                'subheadline' => $description,
                'cta_label' => 'Shop now',
            ]);
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
                    'phone' => $store->merchant?->phone,
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
                    'body' => "This privacy policy explains how {$businessName} and Storehaus collect, use, and protect your personal information when you shop on this storefront.\n\nWe collect information you provide at checkout such as your name, email, phone number, and delivery address. We use this information to process orders, communicate about your purchase, and improve our service.\n\nPayment details are processed securely by our payment partners. We do not store full card numbers on our servers.\n\nYou may contact us to request access to or correction of your personal data.".($contactEmail ? " Email: {$contactEmail}." : ''),
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

        $this->ensureAiAvailable();

        return $this->requireAiResult(
            $this->aiAgent->synthesizeStorefront($store, $storefront),
            'storefront synthesis',
        );
    }

    public function resolveStorefrontTemplate(Store $store): string
    {
        $templateId = $store->storefront_template_id ?? 'ai_pick';

        if (in_array($templateId, StorefrontTemplate::concreteIds(), true)) {
            return $templateId;
        }

        $industry = $store->merchant?->industry ?? 'other';
        $activeTemplateIds = StorefrontTemplate::activeConcreteIds();
        $firstActive = fn (array $ids, string $fallback): string => collect($ids)
            ->first(fn (string $id): bool => in_array($id, $activeTemplateIds, true), $fallback);

        if ($industry === 'beauty_and_skincare') {
            return $firstActive(['cosmetics', 'beauty', 'minimalistic'], 'minimalistic');
        }

        if ($industry === 'fashion_and_apparel') {
            return $firstActive(['fashion_lookbook', 'minimalistic'], 'minimalistic');
        }

        if (in_array($industry, ['electronics', 'food_and_beverage', 'home_and_living'], true)) {
            return $firstActive(['minimalistic', 'cosmetics'], 'minimalistic');
        }

        return in_array('minimalistic', $activeTemplateIds, true) ? 'minimalistic' : ($activeTemplateIds[0] ?? 'minimalistic');
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}
     */
    public function applyChatEdit(array $storefront, string $instruction): array
    {
        $this->ensureAiAvailable();

        return $this->requireAiResult(
            $this->aiAgent->applyChatEdit($storefront, $instruction),
            'chat edit',
        );
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function extractBusinessProfileFromMessage(string $message, array $profile = []): array
    {
        $this->ensureAiAvailable();

        $profile = array_merge([
            'business_name' => null,
            'description' => null,
            'industry' => null,
            'brand_color' => null,
            'tone' => [],
        ], $profile);

        return $this->requireAiResult(
            $this->aiAgent->extractBusinessProfile($message, $profile),
            'business profile extraction',
        );
    }

    public function inferIndustryFromProfile(array $profile): string
    {
        return $profile['industry'] ?? 'other';
    }

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
            || preg_match('/\b(sell|store|brand|business|template|skincare|fashion|product|shop|vibe|style)\b/i', $trimmed) === 1;
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     */
    public function conversationalReply(array $sessionContext, string $message): string
    {
        $this->ensureAiAvailable();

        return $this->requireAiResult(
            $this->aiAgent->respondToConversation($message, $sessionContext),
            'conversation reply',
        );
    }

    /**
     * @param  array<string, mixed>  $sessionContext
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $recommendations
     * @return array{assistant_message: string, plan?: array<int, array<string, mixed>>, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}|null
     */
    public function planBuilderTurn(
        string $message,
        array $sessionContext,
        array $profile,
        array $recommendations,
    ): array {
        $this->ensureAiAvailable();
        $availableTemplateIds = StorefrontTemplate::activeConcreteIds();

        return $this->requireAiResult(
            $this->aiAgent->planBuilderTurn(
                $message,
                $sessionContext,
                $profile,
                $recommendations,
                $availableTemplateIds,
            ),
            'builder turn planning',
        );
    }

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
            throw new StorefrontAiUnavailableException();
        }
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
