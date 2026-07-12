<?php

namespace App\Services;

use App\Models\Store;

class StorefrontPageBlockService
{
    private const MAX_PAGE_BLOCKS = 12;

    /** @var array<string, list<string>> */
    private const PROTECTED_BLOCK_IDS = [
        'home' => ['hero-main'],
        'about' => ['about-main'],
        'contact' => ['contact-form'],
        'faq' => ['faq-main'],
    ];

    public function __construct(
        private readonly StorefrontBlockService $blockService,
    ) {}

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message: string}|null
     */
    public function tryApplyPageBlockInstructionFormatted(array $storefront, string $instruction, ?Store $store = null): ?array
    {
        $regenerate = $this->tryRegenerateSectionFormatted($storefront, $instruction, $store);
        if (is_array($regenerate)) {
            return $regenerate;
        }

        $page = $this->resolvePageFromInstruction($instruction);
        $operations = $this->parsePageBlockInstruction($storefront, $instruction, $page);
        if ($operations === null || $operations === []) {
            return null;
        }

        $result = $this->applyPageBlockOperations($storefront, $page, $operations, $store);
        if (($result['changed_block_ids'] ?? []) === []) {
            return null;
        }

        return $this->formatPageBlockEditResult(
            $result['storefront'],
            $page,
            $result['changed_block_ids'],
            $instruction,
            $operations,
        );
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message: string}|null
     */
    public function tryRegenerateSectionFormatted(array $storefront, string $instruction, ?Store $store = null): ?array
    {
        $lower = strtolower($instruction);
        $sectionRefresh = preg_match('/\b(redesign|regenerate|refresh|rewrite|fix)\b/u', $lower) === 1
            || (preg_match('/\b(essentials|essentials page|shop the essentials|category showcase|categories section|category grid|shop by category)\b/u', $lower) === 1
                && preg_match('/\b(update|refined|change|copy|images?|photos?|labels?|titles?|theme)\b/u', $lower) === 1);
        if (! $sectionRefresh) {
            return null;
        }

        if (preg_match('/\b(entire|whole|full|all)\b/u', $lower) === 1
            && preg_match('/\b(site|storefront|website)\b/u', $lower) === 1) {
            return null;
        }

        $page = $this->resolvePageFromInstruction($instruction);
        $blocks = $this->resolvePageBlocks($storefront, $page);
        $blockId = $this->resolveBlockIdFromInstruction($instruction, $page, $blocks);
        if (! is_string($blockId)) {
            return null;
        }

        $result = $this->applyPageBlockOperations($storefront, $page, [[
            'op' => 'regenerate_section',
            'page' => $page,
            'block_id' => $blockId,
        ]], $store);

        if (($result['changed_block_ids'] ?? []) === []) {
            return null;
        }

        return $this->formatPageBlockEditResult(
            $result['storefront'],
            $page,
            $result['changed_block_ids'],
            $instruction,
            [['op' => 'regenerate_section', 'page' => $page, 'block_id' => $blockId]],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $operations
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>}
     */
    public function applyAiBlockOperations(array $storefront, array $operations, ?Store $store = null): array
    {
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $changedPaths = [];
        $byPage = [];

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $page = (string) ($operation['page'] ?? 'home');
            $byPage[$page] ??= [];
            $byPage[$page][] = $operation;
        }

        foreach ($byPage as $page => $pageOps) {
            $result = $this->applyPageBlockOperations($next, (string) $page, $pageOps, $store);
            $next = $result['storefront'];
            foreach ($result['changed_block_ids'] as $blockId) {
                $changedPaths[] = "pages.{$page}.blocks.{$blockId}";
            }
        }

        return [
            'storefront' => $next,
            'changed_paths' => array_values(array_unique($changedPaths)),
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $operations
     * @return array{storefront: array<string, mixed>, changed_block_ids: list<string>}
     */
    public function applyPageBlockOperations(array $storefront, string $page, array $operations, ?Store $store = null): array
    {
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $blocks = collect($this->resolvePageBlocks($next, $page))
            ->map(fn (array $block): array => [
                ...$block,
                'props' => is_array($block['props'] ?? null) ? $block['props'] : [],
            ])
            ->values()
            ->all();

        $changed = [];

        foreach ($operations as $operation) {
            $op = (string) ($operation['op'] ?? '');
            $targetPage = (string) ($operation['page'] ?? $page);
            if ($targetPage !== $page) {
                continue;
            }

            if ($op === 'update_block') {
                $blockId = (string) ($operation['block_id'] ?? '');
                $index = $this->findBlockIndex($blocks, $blockId);
                if ($index < 0 || $this->isBlockLocked($blocks[$index] ?? null)) {
                    continue;
                }

                $props = is_array($operation['props'] ?? null) ? $operation['props'] : [];
                $blocks[$index]['props'] = array_merge($blocks[$index]['props'] ?? [], $props);
                $blocks[$index]['edit_metadata'] = ['source' => 'ai_generated', 'locked' => false];
                $changed[] = $blockId;

                continue;
            }

            if ($op === 'regenerate_section') {
                $blockId = (string) ($operation['block_id'] ?? '');
                $index = $this->findBlockIndex($blocks, $blockId);
                if ($index < 0 || $this->isBlockLocked($blocks[$index] ?? null)) {
                    continue;
                }

                $regenerated = $this->regenerateSectionProps($blocks[$index], $next, $store);
                $override = is_array($operation['props'] ?? null) ? $operation['props'] : [];
                $blocks[$index]['props'] = array_merge($blocks[$index]['props'] ?? [], $regenerated, $override);
                $blocks[$index]['edit_metadata'] = ['source' => 'ai_generated', 'locked' => false];
                $changed[] = $blockId;

                continue;
            }

            if ($op === 'reorder_blocks') {
                $order = is_array($operation['order'] ?? null) ? $operation['order'] : [];
                $lookup = collect($blocks)->keyBy('id');
                $reordered = [];
                foreach ($order as $id) {
                    $block = $lookup->get((string) $id);
                    if (is_array($block)) {
                        $reordered[] = $block;
                    }
                }
                $remaining = collect($blocks)
                    ->reject(fn (array $block): bool => in_array($block['id'] ?? '', $order, true))
                    ->values()
                    ->all();
                $blocks = [...$reordered, ...$remaining];
                $changed = [...$changed, ...array_map(strval(...), $order)];

                continue;
            }

            if ($op === 'remove_block') {
                $blockId = (string) ($operation['block_id'] ?? '');
                if (! $this->canRemovePageBlock($page, $blockId)) {
                    continue;
                }

                $index = $this->findBlockIndex($blocks, $blockId);
                if ($index < 0) {
                    continue;
                }

                array_splice($blocks, $index, 1);
                $changed[] = $blockId;
            }
        }

        $this->persistPageBlocks($next, $page, $blocks);

        return [
            'storefront' => $next,
            'changed_block_ids' => array_values(array_unique($changed)),
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>|null
     */
    public function parsePageBlockInstruction(array $storefront, string $instruction, string $page): ?array
    {
        $blockService = app(StorefrontBlockService::class);
        if ($page === 'home') {
            return $blockService->parseHomeBlockInstruction($storefront, $instruction);
        }

        $lower = strtolower($instruction);
        $blocks = $this->resolvePageBlocks($storefront, $page);
        $blockId = $this->resolveBlockIdFromInstruction($instruction, $page, $blocks);

        if (preg_match('/\b(remove|delete|hide)\b/u', $lower) === 1 && is_string($blockId)) {
            if ($this->canRemovePageBlock($page, $blockId) && $this->findBlockIndex($blocks, $blockId) >= 0) {
                return [['op' => 'remove_block', 'page' => $page, 'block_id' => $blockId]];
            }
        }

        if (preg_match('/\b(redesign|regenerate|refresh|rewrite|fix)\b/u', $lower) === 1 && is_string($blockId)) {
            return [['op' => 'regenerate_section', 'page' => $page, 'block_id' => $blockId]];
        }

        if (preg_match('/\b(make|update).*\bpremium|luxury|refined/u', $lower) === 1 && is_string($blockId)) {
            $block = collect($blocks)->firstWhere('id', $blockId);
            $type = is_array($block) ? (string) ($block['type'] ?? '') : '';

            if ($type === 'feature_grid') {
                return [[
                    'op' => 'update_block',
                    'page' => $page,
                    'block_id' => $blockId,
                    'props' => [
                        'title' => 'Why Choose Us',
                        'body' => 'Premium formulas and trust blocks designed for a refined everyday routine.',
                    ],
                ]];
            }

            if ($type === 'rich_text') {
                return [[
                    'op' => 'update_block',
                    'page' => $page,
                    'block_id' => $blockId,
                    'props' => [
                        'title' => data_get($storefront, 'pages.'.$page.'.title', 'About us'),
                        'body' => 'A refined story that matches your brand — clear, honest, and easy to trust.',
                    ],
                ]];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    public function resolvePageBlocks(array $storefront, string $page): array
    {
        return $this->blockService->resolvePageBlocks($storefront, $page);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<string>  $changedPaths
     * @return array<string, mixed>
     */
    public function maybeSyncHomeBlocksFromLegacyPaths(array $storefront, array $changedPaths): array
    {
        return $this->blockService->maybeSyncHomeBlocksFromLegacyPaths($storefront, $changedPaths);
    }

    /**
     * @param  list<string>  $changedBlockIds
     * @param  list<array<string, mixed>>  $operations
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message: string}
     */
    private function formatPageBlockEditResult(
        array $storefront,
        string $page,
        array $changedBlockIds,
        string $instruction,
        array $operations = [],
        bool $regenerated = false,
    ): array {
        $changedPaths = array_map(
            fn (string $id): string => "pages.{$page}.blocks.{$id}",
            $changedBlockIds,
        );

        $next = $storefront;
        $next['edit_metadata'] = array_merge($next['edit_metadata'] ?? [], [
            'user_edited_paths' => array_values(array_unique([
                ...($next['edit_metadata']['user_edited_paths'] ?? []),
                ...$changedPaths,
            ])),
            'last_generation_prompt' => $instruction,
            'last_generated_at' => now()->toIso8601String(),
        ]);

        $pageLabel = match ($page) {
            'about' => 'about',
            'contact' => 'contact',
            'faq' => 'FAQ',
            default => 'homepage',
        };

        $message = $regenerated
            ? "Done — I redesigned that {$pageLabel} section. Check the preview on the right."
            : "Done — I updated your {$pageLabel} page sections. Check the preview on the right.";

        return [
            'storefront' => $next,
            'changed_paths' => $changedPaths,
            'assistant_message' => $message,
        ];
    }

    private function resolvePageFromInstruction(string $instruction): string
    {
        $lower = strtolower($instruction);

        if (preg_match('/\babout(\s+page|\s+us)?\b/u', $lower) === 1) {
            return 'about';
        }

        if (preg_match('/\bcontact(\s+page)?\b/u', $lower) === 1) {
            return 'contact';
        }

        if (preg_match('/\bfaq(\s+page)?\b|\bquestions\b/u', $lower) === 1) {
            return 'faq';
        }

        return 'home';
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function resolveBlockIdFromInstruction(string $instruction, string $page, array $blocks): ?string
    {
        $lower = strtolower($instruction);
        $aliases = [
            'hero' => 'hero-main',
            'stats' => 'home-stats',
            'statistics' => 'home-stats',
            'trust' => $page === 'about' ? 'about-features' : 'trust-features',
            'features' => $page === 'about' ? 'about-features' : 'trust-features',
            'faq' => $page === 'faq' ? 'faq-main' : 'home-faq',
            'contact form' => 'contact-form',
            'form' => 'contact-form',
            'about' => $page === 'about' ? 'about-main' : 'about-spotlight',
            'products' => 'featured-products',
            'promo' => 'serum-promo',
            'banner' => 'serum-promo',
            'essentials' => 'category-showcase',
            'essentials page' => 'category-showcase',
            'shop the essentials' => 'category-showcase',
            'category showcase' => 'category-showcase',
            'categories section' => 'category-showcase',
        ];

        foreach ($aliases as $keyword => $blockId) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $lower) === 1) {
                $match = collect($blocks)->firstWhere('id', $blockId);
                if (is_array($match)) {
                    return $blockId;
                }
            }
        }

        if (preg_match('/\b(essentials|essentials page|shop the essentials|category showcase|categories section|category grid|shop by category)\b/u', $lower) === 1) {
            $block = collect($blocks)->firstWhere('type', 'category_showcase');
            if (is_array($block)) {
                return (string) ($block['id'] ?? 'category-showcase');
            }

            return 'category-showcase';
        }

        if (preg_match('/\b(products?|shop)\b/u', $lower) === 1 && preg_match('/\bshop the essentials\b/u', $lower) !== 1) {
            $block = collect($blocks)->firstWhere('type', 'product_grid');
            if (is_array($block)) {
                return (string) ($block['id'] ?? 'featured-products');
            }

            return 'featured-products';
        }

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'hero' && preg_match('/\bhero\b/u', $lower) === 1) {
                return (string) ($block['id'] ?? 'hero-main');
            }
            if ($type === 'faq' && preg_match('/\bfaq\b|\bquestions\b/u', $lower) === 1) {
                return (string) ($block['id'] ?? 'faq-main');
            }
            if ($type === 'contact_form' && preg_match('/\bform\b/u', $lower) === 1) {
                return (string) ($block['id'] ?? 'contact-form');
            }
            if ($type === 'rich_text' && preg_match('/\b(intro|about|story)\b/u', $lower) === 1) {
                return (string) ($block['id'] ?? 'about-main');
            }
        }

        return isset($blocks[0]['id']) ? (string) $blocks[0]['id'] : null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    private function regenerateSectionProps(array $block, array $storefront, ?Store $store = null): array
    {
        $businessName = $store?->name ?: (string) data_get($storefront, 'hero.headline', 'Our store');
        $industry = (string) ($store?->merchant?->industry ?? 'other');
        $type = (string) ($block['type'] ?? '');

        return match ($type) {
            'hero' => [
                'eyebrow' => 'Crafted for everyday care',
                'headline' => $businessName,
                'subheadline' => 'Thoughtful '.str_replace('_', ' ', $industry).' essentials with a calm, premium feel.',
                'cta_label' => 'Shop now',
                'layout' => collect(['split', 'centered', 'image_right'])->random(),
            ],
            'stats_row' => [
                'items' => [
                    ['value' => 'Trusted by thousands', 'label' => 'of happy customers'],
                    ['value' => 'Fast delivery', 'label' => 'across Nigeria'],
                    ['value' => '4.8', 'label' => 'average customer rating'],
                ],
            ],
            'rich_text' => [
                'title' => (string) data_get($storefront, 'pages.about.title', data_get($storefront, 'about.title', 'About us')),
                'body' => (string) ($store?->description ?: data_get($storefront, 'about.body', '')),
            ],
            'feature_grid' => [
                'title' => 'Why customers choose us',
                'body' => 'Clear quality, honest messaging, and a shopping experience that feels easy from the first visit.',
                'items' => array_slice(is_array($storefront['value_props'] ?? null) ? $storefront['value_props'] : [], 0, 3),
            ],
            'cta_banner' => [
                'title' => 'Discover something new',
                'body' => 'A calm add-on for your routine — easy to understand and simple to shop.',
                'cta_label' => 'Explore',
                'cta_href' => '/products',
            ],
            'product_grid' => ['title' => 'Shop the line', 'limit' => 4],
            'category_showcase' => $this->blockService->categoryShowcaseBlockProps($storefront),
            'faq' => [
                'title' => (string) data_get($storefront, 'pages.faq.title', 'Frequently asked questions'),
                'items' => [
                    ['question' => 'How do I place an order?', 'answer' => "Browse {$businessName}, add items to your cart, and complete checkout."],
                    ['question' => 'How long does delivery take?', 'answer' => 'Most orders arrive within 2–4 business days depending on your location.'],
                    ['question' => "What makes {$businessName} different?", 'answer' => 'We focus on clear information, reliable service, and products customers can trust.'],
                    ['question' => 'How can I get help?', 'answer' => 'Use the contact page and our team will reply as quickly as possible.'],
                ],
            ],
            'contact_form' => [
                'title' => 'Get in touch',
                'intro' => 'Tell us what you need — we typically reply within one business day.',
                'submit_label' => 'Send message',
                'success_message' => "Thanks — we'll reply soon.",
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $blocks
     */
    private function persistPageBlocks(array &$storefront, string $page, array $blocks): void
    {
        $this->blockService->persistPageBlocks($storefront, $page, $blocks);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function findBlockIndex(array $blocks, string $blockId): int
    {
        foreach ($blocks as $index => $block) {
            if (($block['id'] ?? null) === $blockId) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param  array<string, mixed>|null  $block
     */
    private function isBlockLocked(?array $block): bool
    {
        return is_array($block) && (bool) data_get($block, 'edit_metadata.locked', false);
    }

    private function canRemovePageBlock(string $page, string $blockId): bool
    {
        return ! in_array($blockId, self::PROTECTED_BLOCK_IDS[$page] ?? [], true);
    }
}
