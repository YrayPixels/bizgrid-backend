<?php

namespace App\Services;

use Illuminate\Support\Str;

class StorefrontPathEditor
{
    /** @var list<string> */
    public const BASE_PATHS = [
        'hero.headline',
        'hero.subheadline',
        'hero.cta_label',
        'about.title',
        'about.body',
        'seo.title',
        'seo.description',
        'media.hero_image_url',
        'media.about_image_url',
        'pages.contact.title',
        'pages.contact.body',
        'pages.contact.email',
        'pages.contact.phone',
        'pages.about.title',
        'pages.about.body',
        'pages.faq.title',
        'home_testimonials_title',
        'home_testimonials_intro',
    ];

    /** @var list<string> */
    private const PATH_PATTERNS = [
        '/^pages\.faq\.items\.\d+\.(question|answer)$/',
        '/^value_props\.\d+\.(title|body)$/',
        '/^home_stats\.\d+\.(value|label)$/',
        '/^home_testimonials\.\d+\.(quote|author)$/',
        '/^navigation\.\d+\.label$/',
        '/^pages\.home\.blocks\.[\w-]+\.props\.[\w.]+$/',
    ];

    public static function isEditablePath(string $path): bool
    {
        if (in_array($path, self::BASE_PATHS, true)) {
            return true;
        }

        foreach (self::PATH_PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function promptAllowedPaths(): array
    {
        return [
            ...self::BASE_PATHS,
            'pages.faq.items[N].question',
            'pages.faq.items[N].answer',
            'value_props[N].title',
            'value_props[N].body',
            'home_stats[N].value',
            'home_stats[N].label',
            'home_testimonials_title',
            'home_testimonials_intro',
            'home_testimonials[N].quote',
            'home_testimonials[N].author',
            'navigation[N].label',
            'pages.home.blocks.hero-main.props.eyebrow',
            'pages.home.blocks.serum-promo.props.title',
            'pages.home.blocks.serum-promo.props.body',
            'pages.home.blocks.serum-promo.props.bullets[N]',
            'pages.home.blocks.serum-promo.props.cta_label',
            'pages.home.blocks.trust-features.props.title',
            'pages.home.blocks.trust-features.props.body',
            'pages.home.blocks.trust-features.props.items[N].title',
            'pages.home.blocks.trust-features.props.items[N].body',
        ];
    }

    public static function pathLabel(string $path): string
    {
        return match ($path) {
            'hero.headline' => 'homepage headline',
            'hero.subheadline' => 'homepage intro',
            'hero.cta_label' => 'shop button',
            'about.title', 'pages.about.title' => 'about page title',
            'about.body', 'pages.about.body' => 'about section',
            'seo.title' => 'search title',
            'seo.description' => 'search description',
            'media.hero_image_url' => 'homepage header photo',
            'media.about_image_url' => 'about section photo',
            'pages.contact.title' => 'contact page title',
            'pages.contact.body' => 'contact page intro',
            'pages.contact.email' => 'contact email',
            'pages.contact.phone' => 'contact phone',
            'pages.faq.title' => 'FAQ page title',
            'home_testimonials_title' => 'testimonials heading',
            'home_testimonials_intro' => 'testimonials intro',
            default => self::dynamicPathLabel($path),
        };
    }

    /**
     * @param  array<string, mixed>  $storefront
     */
    public static function apply(array &$storefront, string $path, string $value): bool
    {
        if (! self::isEditablePath($path)) {
            return false;
        }

        $value = match ($path) {
            'seo.title' => Str::limit($value, 160, ''),
            'seo.description' => Str::limit($value, 300, ''),
            default => trim($value),
        };

        if ($value === '') {
            return false;
        }

        if ($path === 'about.title' || $path === 'about.body') {
            self::setAboutField($storefront, substr($path, 6), $value);

            return true;
        }

        if ($path === 'pages.about.title' || $path === 'pages.about.body') {
            $field = str_ends_with($path, '.title') ? 'title' : 'body';
            self::setAboutField($storefront, $field, $value);

            return true;
        }

        if (str_starts_with($path, 'pages.home.blocks.')) {
            return self::applyHomeBlockPropField($storefront, $path, $value);
        }

        data_set($storefront, $path, $value);

        if (str_starts_with($path, 'pages.contact.')) {
            data_set($storefront, 'pages.contact.source', 'merchant');
        }

        if (str_starts_with($path, 'pages.faq.')) {
            data_set($storefront, 'pages.faq.source', 'merchant');
        }

        return true;
    }

    /**
     * @param  array<string, string>  $updates
     * @return list<string>
     */
    public static function applyMany(array &$storefront, array $updates): array
    {
        $changedPaths = [];

        foreach ($updates as $path => $value) {
            if (! is_string($path) || ! is_string($value)) {
                continue;
            }

            if (self::apply($storefront, $path, $value)) {
                $changedPaths[] = $path;
            }
        }

        return array_values(array_unique($changedPaths));
    }

    /**
     * True when the merchant wants a new FAQ list item, not a homepage FAQ block.
     */
    public static function isFaqItemAppendInstruction(string $instruction): bool
    {
        $lower = strtolower($instruction);
        $isFaqAdd = preg_match('/\b(add|create|new|another)\b.*\b(faq|question)\b/', $lower) === 1
            || preg_match('/\b(faq|question)\b.*\b(add|about)\b/', $lower) === 1
            || preg_match('/\b(third|fourth|fifth|another)\b.*\bfaq\b/', $lower) === 1;

        if (! $isFaqAdd) {
            return false;
        }

        if (preg_match('/\b(banner|promo|section|block|homepage|home page)\b/', $lower) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}|null
     */
    public static function tryAppendFaqItem(array $storefront, string $instruction): ?array
    {
        $lower = strtolower($instruction);
        $isFaqAdd = preg_match('/\b(add|create|new|another)\b.*\b(faq|question)\b/', $lower) === 1
            || preg_match('/\b(faq|question)\b.*\b(add|about)\b/', $lower) === 1
            || preg_match('/\b(third|fourth|fifth|another)\b.*\bfaq\b/', $lower) === 1;

        if (! $isFaqAdd) {
            return null;
        }

        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            return null;
        }

        if (! isset($next['pages']['faq']) || ! is_array($next['pages']['faq'])) {
            $next['pages']['faq'] = [
                'title' => 'Frequently asked questions',
                'source' => 'ai_generated',
                'items' => [],
            ];
        }

        $question = 'Can I return an item?';
        $answer = 'Yes. Contact us within 7 days of delivery if something is not right with your order.';

        if (preg_match('/["“](.+?)["”]\s*(?:[—–-]\s*|,\s*)["“](.+?)["”]/s', $instruction, $matches)) {
            $question = trim($matches[1]);
            $answer = trim($matches[2]);
        } elseif (preg_match('/about\s+["“](.+?)["”]/i', $instruction, $matches)) {
            $topic = trim($matches[1]);
            $question = "What is your policy on {$topic}?";
            $answer = "Contact us and we'll help with any questions about {$topic}.";
        } elseif (str_contains($lower, 'return')) {
            $question = 'What is your return policy?';
            $answer = 'Contact us within 7 days of delivery if you need a return or exchange.';
        } elseif (str_contains($lower, 'shipping') || str_contains($lower, 'delivery')) {
            $question = 'How long does delivery take?';
            $answer = 'Most orders arrive within 2-4 business days depending on your location.';
        }

        $items = is_array($next['pages']['faq']['items'] ?? null) ? $next['pages']['faq']['items'] : [];
        $index = count($items);
        $items[] = ['question' => $question, 'answer' => $answer];
        $next['pages']['faq']['items'] = $items;
        $next['pages']['faq']['source'] = 'merchant';

        return [
            'storefront' => $next,
            'changed_paths' => [
                "pages.faq.items.{$index}.question",
                "pages.faq.items.{$index}.answer",
            ],
        ];
    }

    public static function shouldRefreshFaq(string $instruction): bool
    {
        $lower = strtolower($instruction);

        return preg_match('/\b(update|refresh|rewrite|revise|improve|fix|change|regenerate|redo)\b.*\bfaq\b/i', $lower) === 1
            || preg_match('/\bfaq\b.*\b(update|refresh|rewrite|revise|improve|fix|change|answers?|questions?)\b/i', $lower) === 1
            || (preg_match('/\bfaq\b/i', $lower) === 1
                && preg_match('/\b(update|refresh|rewrite|revise|improve|fix|change|answers?|questions?)\b/i', $lower) === 1);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array{question: string, answer: string}>
     */
    public static function defaultFaqItemsForStore(array $storefront, ?string $businessName = null, ?string $industry = null): array
    {
        $name = trim((string) ($businessName ?: data_get($storefront, 'seo.title', 'Our brand')));
        if ($name === '' || $name === 'Our brand') {
            $seoMatch = [];
            if (preg_match('/^(.+?)\s*[|–-]/', (string) data_get($storefront, 'seo.title', ''), $seoMatch) === 1) {
                $name = trim($seoMatch[1]);
            }
        }
        if ($name === '') {
            $name = 'Our brand';
        }

        if ($industry === 'fashion_and_apparel') {
            return [
                [
                    'question' => "How do I choose the right size at {$name}?",
                    'answer' => 'Check the size notes on each product page, or message us with your usual size and we will help you pick the best fit.',
                ],
                [
                    'question' => 'What is your return policy?',
                    'answer' => 'Unworn items with tags attached can be returned within 14 days. Contact us through the contact page to start a return.',
                ],
                [
                    'question' => 'How long does delivery take?',
                    'answer' => 'Most orders arrive within 2-4 business days across Nigeria once your order is confirmed.',
                ],
                [
                    'question' => 'Can I change or cancel my order?',
                    'answer' => 'If your order has not shipped yet, reach out as soon as possible and we will do our best to update it for you.',
                ],
            ];
        }

        if ($industry === 'beauty_and_skincare') {
            return [
                [
                    'question' => "Are {$name} products suitable for daily use?",
                    'answer' => 'Yes — our formulas are designed for gentle everyday routines. Patch-test new products if you have sensitive skin.',
                ],
                [
                    'question' => 'How do I build a routine with your products?',
                    'answer' => 'Start with cleanser, treatment serum, then moisturizer. Message us if you want a simple routine recommendation.',
                ],
                [
                    'question' => 'How long does delivery take?',
                    'answer' => 'Most orders arrive within 2-4 business days depending on your location.',
                ],
            ];
        }

        return [
            [
                'question' => 'How do I place an order?',
                'answer' => "Browse {$name}, add items to your cart, and complete checkout with your delivery details.",
            ],
            [
                'question' => 'What payment methods do you accept?',
                'answer' => 'We accept card payments and bank transfers through secure checkout.',
            ],
            [
                'question' => 'How long does delivery take?',
                'answer' => 'Most orders arrive within 2-4 business days depending on your location.',
            ],
            [
                'question' => 'How can I contact support?',
                'answer' => 'Use our contact page and our team will reply as quickly as possible.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}|null
     */
    public static function tryRefreshFaqItems(
        array $storefront,
        string $instruction,
        ?string $businessName = null,
        ?string $industry = null,
    ): ?array {
        if (! self::shouldRefreshFaq($instruction)) {
            return null;
        }

        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            return null;
        }

        $items = self::defaultFaqItemsForStore($next, $businessName, $industry);
        $title = trim((string) data_get($next, 'pages.faq.title', 'Frequently asked questions'));
        if ($title === '') {
            $title = 'Frequently asked questions';
        }

        data_set($next, 'pages.faq', [
            'title' => $title,
            'source' => 'merchant',
            'items' => $items,
        ]);

        $changedPaths = [];
        foreach ($items as $index => $item) {
            $changedPaths[] = "pages.faq.items.{$index}.question";
            $changedPaths[] = "pages.faq.items.{$index}.answer";
        }

        return [
            'storefront' => $next,
            'changed_paths' => $changedPaths,
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, string>
     */
    public static function flattenUpdates(array $updates): array
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

            if (in_array($path, ['hero', 'about', 'seo'], true)) {
                foreach ($value as $field => $fieldValue) {
                    if (! is_string($field) || ! is_string($fieldValue) || trim($fieldValue) === '') {
                        continue;
                    }

                    $flat["{$path}.{$field}"] = trim($fieldValue);
                }

                continue;
            }

            if ($path === 'pages' && is_array($value)) {
                $flat = array_merge($flat, self::flattenNestedUpdates('pages', $value));

                continue;
            }

            if ($path === 'value_props' && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    foreach (['title', 'body'] as $field) {
                        if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                            $flat["value_props.{$index}.{$field}"] = trim($item[$field]);
                        }
                    }
                }
            }

            if ($path === 'home_testimonials' && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    foreach (['quote', 'author'] as $field) {
                        if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                            $flat["home_testimonials.{$index}.{$field}"] = trim($item[$field]);
                        }
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $storefront
     */
    private static function setAboutField(array &$storefront, string $field, string $value): void
    {
        data_set($storefront, "about.{$field}", $value);

        if (! isset($storefront['pages']['about']) || ! is_array($storefront['pages']['about'])) {
            $storefront['pages']['about'] = [
                'title' => (string) ($storefront['about']['title'] ?? ''),
                'body' => (string) ($storefront['about']['body'] ?? ''),
                'source' => 'merchant',
            ];
        }

        data_set($storefront, "pages.about.{$field}", $value);
        data_set($storefront, 'pages.about.source', 'merchant');
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private static function flattenNestedUpdates(string $prefix, array $value): array
    {
        $flat = [];

        foreach ($value as $key => $nested) {
            $path = "{$prefix}.{$key}";

            if (is_string($nested) && trim($nested) !== '') {
                $flat[$path] = trim($nested);

                continue;
            }

            if (! is_array($nested)) {
                continue;
            }

            if ($key === 'faq' && isset($nested['items']) && is_array($nested['items'])) {
                if (isset($nested['title']) && is_string($nested['title'])) {
                    $flat['pages.faq.title'] = trim($nested['title']);
                }

                foreach ($nested['items'] as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    foreach (['question', 'answer'] as $field) {
                        if (isset($item[$field]) && is_string($item[$field]) && trim($item[$field]) !== '') {
                            $flat["pages.faq.items.{$index}.{$field}"] = trim($item[$field]);
                        }
                    }
                }

                continue;
            }

            foreach (['title', 'body', 'email', 'phone'] as $field) {
                if (isset($nested[$field]) && is_string($nested[$field]) && trim($nested[$field]) !== '') {
                    $flat["{$path}.{$field}"] = trim($nested[$field]);
                }
            }
        }

        return $flat;
    }

    private static function dynamicPathLabel(string $path): string
    {
        if (preg_match('/^pages\.faq\.items\.(\d+)\.question$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "FAQ question {$number}";
        }

        if (preg_match('/^pages\.faq\.items\.(\d+)\.answer$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "FAQ answer {$number}";
        }

        if (preg_match('/^value_props\.(\d+)\.title$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "trust highlight {$number} title";
        }

        if (preg_match('/^value_props\.(\d+)\.body$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "trust highlight {$number} description";
        }

        if (preg_match('/^home_stats\.(\d+)\.value$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "homepage stat {$number}";
        }

        if (preg_match('/^home_stats\.(\d+)\.label$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "homepage stat {$number} label";
        }

        if (preg_match('/^navigation\.(\d+)\.label$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "navigation link {$number}";
        }

        if (preg_match('/^home_testimonials\.(\d+)\.quote$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "testimonial {$number} quote";
        }

        if (preg_match('/^home_testimonials\.(\d+)\.author$/', $path, $matches)) {
            $number = ((int) $matches[1]) + 1;

            return "testimonial {$number} author";
        }

        if (preg_match('/^pages\.home\.blocks\.([\w-]+)$/', $path, $matches)) {
            $labels = [
                'hero-main' => 'homepage hero',
                'home-stats' => 'homepage stats',
                'about-spotlight' => 'about spotlight',
                'serum-promo' => 'promo banner',
                'trust-features' => 'trust highlights',
                'featured-products' => 'product section',
                'home-faq' => 'homepage FAQ',
            ];

            return $labels[$matches[1]] ?? 'homepage section';
        }

        if (preg_match('/^pages\.home\.blocks\.([\w-]+)\.props\.(.+)$/', $path, $matches)) {
            $labels = [
                'hero-main' => 'homepage hero',
                'serum-promo' => 'serum promo',
                'trust-features' => 'why choose us',
            ];
            $section = $labels[$matches[1]] ?? 'homepage section';
            $prop = preg_replace('/\.\d+/', '', $matches[2]) ?? $matches[2];

            return match ($prop) {
                'eyebrow' => "{$section} eyebrow",
                'title' => "{$section} title",
                'body' => "{$section} copy",
                'cta_label' => "{$section} button",
                'bullets' => "{$section} bullet",
                'items.title', 'items.body' => "{$section} feature",
                default => $section,
            };
        }

        return str_replace('.', ' ', $path);
    }

    /**
     * @param  array<string, mixed>  $storefront
     */
    private static function applyHomeBlockPropField(array &$storefront, string $path, string $value): bool
    {
        if (! preg_match('/^pages\.home\.blocks\.([\w-]+)\.props\.(.+)$/', $path, $matches)) {
            return false;
        }

        [, $blockId, $propPath] = $matches;
        $blockService = app(StorefrontBlockService::class);
        $blocks = $blockService->resolvePageBlocks($storefront, 'home');
        $index = collect($blocks)->search(fn (array $block): bool => ($block['id'] ?? '') === $blockId);

        if ($index === false) {
            return false;
        }

        $props = is_array($blocks[$index]['props'] ?? null) ? $blocks[$index]['props'] : [];
        $blocks[$index]['props'] = self::setNestedBlockProp($props, $propPath, $value);
        $blockService->persistPageBlocks($storefront, 'home', $blocks);

        return true;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function setNestedBlockProp(array $props, string $propPath, string $value): array
    {
        $parts = explode('.', $propPath);

        if (count($parts) === 1) {
            $props[$parts[0]] = $value;

            return $props;
        }

        if ($parts[0] === 'items' && count($parts) === 3) {
            $index = (int) $parts[1];
            $field = $parts[2];
            $items = is_array($props['items'] ?? null) ? $props['items'] : [];
            $current = is_array($items[$index] ?? null) ? $items[$index] : [];
            $current[$field] = $value;
            $items[$index] = $current;
            $props['items'] = $items;

            return $props;
        }

        if ($parts[0] === 'bullets' && count($parts) === 2) {
            $index = (int) $parts[1];
            $bullets = is_array($props['bullets'] ?? null) ? $props['bullets'] : [];
            $bullets[$index] = $value;
            $props['bullets'] = $bullets;

            return $props;
        }

        return $props;
    }
}
