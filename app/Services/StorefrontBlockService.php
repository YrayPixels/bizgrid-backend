<?php

namespace App\Services;

class StorefrontBlockService
{
    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    public function resolveHomeBlocks(array $storefront): array
    {
        return $this->migrateHomeBlocks($storefront);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function ensureHomeBlocksOnStorefront(array $storefront): array
    {
        return $this->ensureAllPageBlocksOnStorefront($storefront);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function ensureAllPageBlocksOnStorefront(array $storefront): array
    {
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $pages = is_array($next['pages'] ?? null) ? $next['pages'] : [];
        $pages['home'] = ['blocks' => $this->migrateHomeBlocks($next)];
        $pages['about'] = array_merge(
            is_array($pages['about'] ?? null) ? $pages['about'] : [
                'title' => (string) data_get($next, 'about.title', ''),
                'body' => (string) data_get($next, 'about.body', ''),
                'source' => 'ai_generated',
            ],
            ['blocks' => $this->migrateAboutBlocks($next)],
        );
        $pages['contact'] = array_merge(
            is_array($pages['contact'] ?? null) ? $pages['contact'] : [
                'title' => 'Contact us',
                'body' => '',
                'email' => null,
                'phone' => null,
                'source' => 'ai_generated',
            ],
            ['blocks' => $this->migrateContactBlocks($next)],
        );
        $pages['faq'] = array_merge(
            is_array($pages['faq'] ?? null) ? $pages['faq'] : [
                'title' => 'Frequently asked questions',
                'source' => 'ai_generated',
                'items' => [],
            ],
            ['blocks' => $this->migrateFaqBlocks($next)],
        );
        $next['pages'] = $this->ensureDefaultPages($next, $pages);
        $this->syncLegacyFieldsFromHomeBlocks($next, $pages['home']['blocks']);

        return $next;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message: string}|null
     */
    public function tryApplyContactFormInstructionFormatted(array $storefront, string $instruction): ?array
    {
        $lower = strtolower($instruction);
        if (
            preg_match('/\bcontact form\b/u', $lower) !== 1
            && ! (preg_match('/\b(add|create|include)\b/u', $lower) === 1 && preg_match('/\bform\b/u', $lower) === 1)
        ) {
            return null;
        }

        $fields = $this->resolveContactFormFieldsFromInstruction($instruction);
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $blocks = $this->migrateContactBlocks($next);
        $formIndex = collect($blocks)->search(fn (array $block): bool => ($block['type'] ?? null) === 'contact_form');
        if (! is_int($formIndex)) {
            $blocks[] = [
                'id' => 'contact-form',
                'type' => 'contact_form',
                'props' => $this->defaultContactFormProps($next, $fields),
            ];
        } else {
            $existingProps = is_array($blocks[$formIndex]['props'] ?? null) ? $blocks[$formIndex]['props'] : [];
            $blocks[$formIndex]['props'] = array_merge($existingProps, [
                'fields' => $fields,
            ]);
        }

        $pages = is_array($next['pages'] ?? null) ? $next['pages'] : [];
        $pages['contact'] = array_merge(
            is_array($pages['contact'] ?? null) ? $pages['contact'] : [
                'title' => 'Contact us',
                'body' => '',
                'email' => null,
                'phone' => null,
                'source' => 'ai_generated',
            ],
            ['blocks' => $blocks],
        );
        $next['pages'] = $this->ensureDefaultPages($next, $pages);

        return $this->formatBlockEditResult(
            $next,
            ['contact-form'],
            $instruction,
            [[
                'op' => 'update_block',
                'page' => 'contact',
                'block_id' => 'contact-form',
                'props' => ['fields' => $fields],
            ]],
            'contact page form',
        );
    }

    /**
     * @param  list<string>  $changedBlockIds
     */
    public function formatBlockEditResult(
        array $storefront,
        array $changedBlockIds,
        string $instruction,
        array $operations = [],
        ?string $pageLabel = null,
    ): array {
        $page = 'home';
        foreach ($operations as $operation) {
            if (is_string($operation['page'] ?? null)) {
                $page = (string) $operation['page'];
                break;
            }
        }

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

        $message = $pageLabel
            ? "Done — I updated the {$pageLabel}. Check the preview on the right."
            : $this->describeBlockChanges($changedBlockIds, $operations);

        return [
            'storefront' => $next,
            'changed_paths' => $changedPaths,
            'assistant_message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $operations
     * @return array{storefront: array<string, mixed>, changed_block_ids: list<string>}|null
     */
    public function tryApplyHomeBlockInstruction(array $storefront, string $instruction): ?array
    {
        $operations = $this->parseHomeBlockInstruction($storefront, $instruction);
        if ($operations === null || $operations === []) {
            return null;
        }

        return $this->applyHomeBlockOperations($storefront, $operations);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $operations
     * @return array{storefront: array<string, mixed>, changed_block_ids: list<string>}
     */
    public function applyHomeBlockOperations(array $storefront, array $operations): array
    {
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $blocks = $this->resolveHomeBlocks($next);
        $changed = [];

        foreach ($operations as $operation) {
            $op = (string) ($operation['op'] ?? '');

            if ($op === 'update_block') {
                $blockId = (string) ($operation['block_id'] ?? '');
                $index = $this->findHomeBlockIndex($blocks, $blockId);
                if ($index < 0) {
                    continue;
                }

                $props = is_array($operation['props'] ?? null) ? $operation['props'] : [];
                $existingProps = is_array($blocks[$index]['props'] ?? null) ? $blocks[$index]['props'] : [];
                $blocks[$index]['props'] = array_merge($existingProps, $props);
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
                if (! $this->canRemoveHomeBlock($blockId)) {
                    continue;
                }

                $index = $this->findHomeBlockIndex($blocks, $blockId);
                if ($index < 0) {
                    continue;
                }

                array_splice($blocks, $index, 1);
                $changed[] = $blockId;

                continue;
            }

            if ($op === 'add_block') {
                $type = (string) ($operation['type'] ?? '');
                if (! $this->isAddableHomeBlockType($type) || count($blocks) >= 12) {
                    continue;
                }

                $block = $this->createHomeBlock(
                    $next,
                    $type,
                    collect($blocks)->pluck('id')->filter(fn ($id) => is_string($id))->values()->all(),
                    is_array($operation['props'] ?? null) ? $operation['props'] : [],
                );
                $this->insertHomeBlock(
                    $blocks,
                    $block,
                    isset($operation['after']) ? (string) $operation['after'] : null,
                    isset($operation['before']) ? (string) $operation['before'] : null,
                );
                $changed[] = (string) ($block['id'] ?? '');
            }
        }

        $pages = is_array($next['pages'] ?? null) ? $next['pages'] : [];
        $pages['home'] = ['blocks' => $blocks];
        $next['pages'] = $this->ensureDefaultPages($next, $pages);
        $this->syncLegacyFieldsFromHomeBlocks($next, $blocks);

        return [
            'storefront' => $next,
            'changed_block_ids' => array_values(array_unique($changed)),
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>|null
     */
    public function parseHomeBlockInstruction(array $storefront, string $instruction): ?array
    {
        $lower = strtolower($instruction);
        $blocks = $this->resolveHomeBlocks($storefront);
        $currentOrder = collect($blocks)
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values()
            ->all();

        if (preg_match('/\bmove\b.*\bfaq\b.*\babove\b.*\bproduct/u', $lower) === 1) {
            return [['op' => 'reorder_blocks', 'order' => $this->buildOrderWithFaqBeforeProducts($currentOrder)]];
        }

        if (preg_match('/\bmove\b.*\bproduct/u', $lower) === 1 && preg_match('/\babove\b.*\bfaq/u', $lower) === 1) {
            return [['op' => 'reorder_blocks', 'order' => $this->buildOrderWithProductsBeforeFaq($currentOrder)]];
        }

        if (preg_match('/\b(make|update).*\b(trust|feature|highlight)/u', $lower) === 1
            && preg_match('/\bpremium|luxury|refined/u', $lower) === 1) {
            return [[
                'op' => 'update_block',
                'block_id' => 'trust-features',
                'props' => [
                    'title' => 'Why Choose Us',
                    'body' => 'Premium formulas, calm textures, and trust blocks designed for a refined everyday routine.',
                ],
            ]];
        }

        if (preg_match('/\b(make|update).*\bhero\b.*\bpremium|luxury/u', $lower) === 1) {
            return [[
                'op' => 'update_block',
                'block_id' => 'hero-main',
                'props' => [
                    'subheadline' => 'Premium botanical skincare with clean formulas and a refined daily ritual.',
                ],
            ]];
        }

        if (preg_match('/\b(remove|delete|hide)\b/u', $lower) === 1) {
            $blockId = $this->resolveRemoveBlockId($instruction, $blocks);
            if (is_string($blockId) && $this->canRemoveHomeBlock($blockId) && $this->findHomeBlockIndex($blocks, $blockId) >= 0) {
                return [['op' => 'remove_block', 'block_id' => $blockId]];
            }
        }

        if (preg_match('/\b(add|insert|create|include)\b/u', $lower) === 1
            && ! StorefrontPathEditor::isFaqItemAppendInstruction($instruction)) {
            $type = $this->resolveBlockTypeFromInstruction($instruction);
            if (is_string($type) && $this->isAddableHomeBlockType($type)) {
                $placement = $this->resolvePlacementFromInstruction($instruction, $blocks);

                return [[
                    'op' => 'add_block',
                    'type' => $type,
                    'after' => $placement['after'] ?? null,
                    'before' => $placement['before'] ?? null,
                ]];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function syncHomeBlocksFromLegacyFields(array $storefront): array
    {
        $next = json_decode(json_encode($storefront), true);
        if (! is_array($next)) {
            $next = $storefront;
        }

        $blocks = collect($this->migrateHomeBlocks($next))
            ->map(fn (array $block): array => [
                ...$block,
                'props' => is_array($block['props'] ?? null) ? $block['props'] : [],
            ])
            ->values()
            ->all();

        foreach ($blocks as $index => $block) {
            $type = (string) ($block['type'] ?? '');

            if ($type === 'hero') {
                $blocks[$index]['props'] = array_merge($block['props'], [
                    'headline' => (string) data_get($next, 'hero.headline', data_get($block, 'props.headline', '')),
                    'subheadline' => (string) data_get($next, 'hero.subheadline', data_get($block, 'props.subheadline', '')),
                    'cta_label' => (string) data_get($next, 'hero.cta_label', data_get($block, 'props.cta_label', 'Shop now')),
                    'image_url' => data_get($next, 'media.hero_image_url', data_get($block, 'props.image_url')),
                ]);

                continue;
            }

            if ($type === 'stats_row' && is_array($next['home_stats'] ?? null) && ($next['home_stats'] ?? []) !== []) {
                $blocks[$index]['props'] = ['items' => $next['home_stats']];

                continue;
            }

            if ($type === 'rich_text') {
                $valueProps = is_array($next['value_props'] ?? null) ? $next['value_props'] : [];
                $blocks[$index]['props'] = array_merge($block['props'], [
                    'title' => (string) data_get($next, 'about.title', data_get($block, 'props.title', '')),
                    'body' => (string) data_get($next, 'about.body', data_get($block, 'props.body', '')),
                    'badges' => collect(array_slice($valueProps, 0, 3))
                        ->map(fn (array $item): array => [
                            'value' => (string) ($item['title'] ?? ''),
                            'label' => (string) ($item['body'] ?? ''),
                        ])
                        ->values()
                        ->all(),
                ]);

                continue;
            }

            if ($type === 'feature_grid' && is_array($next['value_props'] ?? null) && ($next['value_props'] ?? []) !== []) {
                $blocks[$index]['props'] = array_merge($block['props'], [
                    'items' => array_slice($next['value_props'], 0, 3),
                ]);

                continue;
            }

            if ($type === 'faq') {
                $blocks[$index]['props'] = array_merge($block['props'], [
                    'title' => (string) data_get($next, 'pages.faq.title', data_get($block, 'props.title', 'Frequently asked questions')),
                    'items' => is_array(data_get($next, 'pages.faq.items')) ? data_get($next, 'pages.faq.items') : (data_get($block, 'props.items') ?? []),
                ]);
            }
        }

        $pages = is_array($next['pages'] ?? null) ? $next['pages'] : [];
        $pages['home'] = ['blocks' => $blocks];
        $next['pages'] = $this->ensureDefaultPages($next, $pages);

        return $next;
    }

    /**
     * @param  list<string>  $changedPaths
     */
    public function shouldSyncHomeBlocksFromLegacyPaths(array $changedPaths): bool
    {
        foreach ($changedPaths as $path) {
            if (
                str_starts_with($path, 'hero.')
                || str_starts_with($path, 'home_stats.')
                || str_starts_with($path, 'about.')
                || str_starts_with($path, 'pages.about.')
                || str_starts_with($path, 'value_props.')
                || str_starts_with($path, 'pages.faq.')
                || str_starts_with($path, 'media.hero_image_url')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<string>  $changedPaths
     * @return array<string, mixed>
     */
    public function maybeSyncHomeBlocksFromLegacyPaths(array $storefront, array $changedPaths): array
    {
        if (! $this->shouldSyncHomeBlocksFromLegacyPaths($changedPaths)) {
            return $storefront;
        }

        return $this->syncHomeBlocksFromLegacyFields($storefront);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array{storefront: array<string, mixed>, changed_paths: list<string>, assistant_message: string}|null
     */
    public function tryApplyHomeBlockInstructionFormatted(array $storefront, string $instruction): ?array
    {
        $blockResult = $this->tryApplyHomeBlockInstruction($storefront, $instruction);
        if (! is_array($blockResult) || ($blockResult['changed_block_ids'] ?? []) === []) {
            return null;
        }

        return $this->formatBlockEditResult(
            $blockResult['storefront'],
            $blockResult['changed_block_ids'],
            $instruction,
            $this->parseHomeBlockInstruction($storefront, $instruction) ?? [],
        );
    }

    /**
     * @param  list<string>  $changedBlockIds
     */
    public function describeBlockChanges(array $changedBlockIds, array $operations = []): string
    {
        foreach ($operations as $operation) {
            if (($operation['op'] ?? null) === 'add_block') {
                $type = (string) ($operation['type'] ?? 'section');

                return 'Done — I added a '.$this->blockTypeLabel($type).' to your homepage. Check the preview on the right.';
            }

            if (($operation['op'] ?? null) === 'remove_block') {
                $blockId = (string) ($operation['block_id'] ?? '');
                $label = $this->blockLabel($blockId);

                return "Done — I removed the {$label} from your homepage. Check the preview on the right.";
            }
        }

        $labels = [
            'hero-main' => 'homepage hero',
            'home-stats' => 'homepage stats',
            'about-spotlight' => 'about spotlight',
            'serum-promo' => 'promo banner',
            'trust-features' => 'trust highlights',
            'featured-products' => 'product section',
            'home-faq' => 'homepage FAQ',
        ];

        $readable = array_map(
            fn (string $id): string => $labels[$id] ?? 'homepage section',
            $changedBlockIds,
        );

        if (count($readable) === 1) {
            return "Done — I updated the {$readable[0]}. Check the preview on the right.";
        }

        if (count($readable) === 2) {
            return "Done — I updated the {$readable[0]} and {$readable[1]}. Check the preview on the right.";
        }

        return 'Done — I updated your homepage sections. Check the preview on the right.';
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function homeBlocksMatchTemplate(array $blocks, string $templateId): bool
    {
        $hasCosmeticsRecipe = collect($blocks)->contains(fn (array $block): bool => ($block['id'] ?? null) === 'serum-promo');
        $hasFurnitureRecipe = collect($blocks)->contains(fn (array $block): bool => ($block['id'] ?? null) === 'collections');
        $hasHairRecipe = collect($blocks)->contains(fn (array $block): bool => ($block['id'] ?? null) === 'choose-style');

        if ($templateId === 'cosmetics') {
            return $hasCosmeticsRecipe;
        }

        if ($templateId === 'furniture-hardware') {
            return $hasFurnitureRecipe;
        }

        if ($templateId === 'hair-and-fashion') {
            return $hasHairRecipe;
        }

        return ! $hasCosmeticsRecipe && ! $hasFurnitureRecipe && ! $hasHairRecipe;
    }

    private function migrateHomeBlocks(array $storefront): array
    {
        $templateId = (string) data_get($storefront, 'template.id', 'classic');
        $existing = data_get($storefront, 'pages.home.blocks');

        if (is_array($existing) && $existing !== [] && $this->homeBlocksMatchTemplate($existing, $templateId)) {
            return $this->ensureCategoryShowcaseBlock($existing, $storefront);
        }

        return $this->buildDefaultHomeBlocks($storefront, $templateId);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function ensureCategoryShowcaseBlock(array $blocks, array $storefront): array
    {
        $templateId = (string) data_get($storefront, 'template.id', 'classic');
        if (! in_array($templateId, ['fashion_lookbook', 'beauty'], true)) {
            return $blocks;
        }

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'category_showcase') {
                return $blocks;
            }
        }

        $categoryBlock = [
            'id' => 'category-showcase',
            'type' => 'category_showcase',
            'props' => $this->defaultCategoryShowcaseProps($storefront),
        ];

        $trustIndex = collect($blocks)->search(fn (array $block): bool => ($block['id'] ?? null) === 'trust-features');
        if (is_int($trustIndex)) {
            array_splice($blocks, $trustIndex + 1, 0, [$categoryBlock]);

            return $blocks;
        }

        $heroIndex = collect($blocks)->search(fn (array $block): bool => ($block['id'] ?? null) === 'hero-main');
        if (is_int($heroIndex)) {
            array_splice($blocks, $heroIndex + 1, 0, [$categoryBlock]);

            return $blocks;
        }

        array_unshift($blocks, $categoryBlock);

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function buildDefaultHomeBlocks(array $storefront, string $templateId): array
    {
        if ($templateId === 'cosmetics') {
            return $this->buildCosmeticsHomeBlocks($storefront);
        }

        $layout = match ($templateId) {
            'editorial', 'minimalistic' => 'centered',
            'bold_grid' => 'image_right',
            default => 'split',
        };

        $productLimit = match ($templateId) {
            'bold_grid' => 6,
            'classic' => 3,
            default => 4,
        };

        $productTitle = $templateId === 'beauty' ? 'Shop the collection' : 'Featured products';

        $blocks = [
            [
                'id' => 'hero-main',
                'type' => 'hero',
                'props' => [
                    'headline' => (string) data_get($storefront, 'hero.headline', ''),
                    'subheadline' => (string) data_get($storefront, 'hero.subheadline', ''),
                    'cta_label' => (string) data_get($storefront, 'hero.cta_label', 'Shop now'),
                    'cta_href' => '/products',
                    'image_url' => data_get($storefront, 'media.hero_image_url'),
                    'layout' => $layout,
                ],
            ],
            [
                'id' => 'trust-features',
                'type' => 'feature_grid',
                'props' => [
                    'title' => 'Why shop with us',
                    'body' => (string) data_get($storefront, 'about.body', ''),
                    'items' => array_slice(is_array($storefront['value_props'] ?? null) ? $storefront['value_props'] : [], 0, 3),
                ],
            ],
        ];

        if (in_array($templateId, ['fashion_lookbook', 'beauty'], true)) {
            $blocks[] = [
                'id' => 'category-showcase',
                'type' => 'category_showcase',
                'props' => $this->defaultHomeBlockProps($storefront, 'category_showcase'),
            ];
        }

        $blocks[] = [
            'id' => 'featured-products',
            'type' => 'product_grid',
            'props' => ['title' => $productTitle, 'limit' => $productLimit],
        ];
        $blocks[] = [
            'id' => 'home-faq',
            'type' => 'faq',
            'props' => [
                'title' => (string) data_get($storefront, 'pages.faq.title', 'Frequently asked questions'),
                'items' => is_array(data_get($storefront, 'pages.faq.items')) ? data_get($storefront, 'pages.faq.items') : [],
            ],
        ];

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function buildCosmeticsHomeBlocks(array $storefront): array
    {
        $stats = is_array($storefront['home_stats'] ?? null) && ($storefront['home_stats'] ?? []) !== []
            ? $storefront['home_stats']
            : $this->defaultHomeStats($storefront);

        $valueProps = is_array($storefront['value_props'] ?? null) && ($storefront['value_props'] ?? []) !== []
            ? $storefront['value_props']
            : [
                ['title' => '100% organic', 'body' => 'Botanical ingredients chosen for gentle daily care.'],
                ['title' => 'Clinical feel', 'body' => 'Simple formulas that support comfort, glow, and consistency.'],
                ['title' => 'Herbal products', 'body' => 'Clean textures made to layer easily in any routine.'],
            ];

        return [
            [
                'id' => 'hero-main',
                'type' => 'hero',
                'props' => [
                    'eyebrow' => 'Discover the Nature with',
                    'headline' => (string) data_get($storefront, 'hero.headline', ''),
                    'subheadline' => (string) data_get($storefront, 'hero.subheadline', ''),
                    'cta_label' => (string) data_get($storefront, 'hero.cta_label', 'Shop now'),
                    'cta_href' => '/products',
                    'image_url' => data_get($storefront, 'media.hero_image_url'),
                ],
            ],
            ['id' => 'home-stats', 'type' => 'stats_row', 'props' => ['items' => $stats]],
            [
                'id' => 'about-spotlight',
                'type' => 'rich_text',
                'props' => [
                    'title' => (string) data_get($storefront, 'about.title', ''),
                    'body' => (string) data_get($storefront, 'about.body', ''),
                    'image_url' => null,
                    'badges' => collect(array_slice($valueProps, 0, 3))
                        ->map(fn (array $item): array => [
                            'value' => (string) ($item['title'] ?? ''),
                            'label' => (string) ($item['body'] ?? ''),
                        ])
                        ->values()
                        ->all(),
                ],
            ],
            [
                'id' => 'serum-promo',
                'type' => 'cta_banner',
                'props' => [
                    'title' => 'Our Best Serums',
                    'body' => 'Lightweight botanical actives made to layer cleanly after cleansing and before daily moisture.',
                    'bullets' => [
                        'Designed for bright, hydrated-looking skin.',
                        'Calm textures for morning and evening routines.',
                        'Simple steps customers can understand quickly.',
                    ],
                    'cta_label' => 'Explore',
                    'cta_href' => '/products',
                    'image_url' => null,
                    'layout' => 'text_left',
                ],
            ],
            [
                'id' => 'trust-features',
                'type' => 'feature_grid',
                'props' => [
                    'title' => 'Why Choose Us',
                    'body' => 'A calm product story, premium formulas, and trust blocks that match the clean cosmetics reference.',
                    'items' => array_slice($valueProps, 0, 3),
                ],
            ],
            [
                'id' => 'featured-products',
                'type' => 'product_grid',
                'props' => ['title' => 'Shop the line', 'limit' => 4],
            ],
            [
                'id' => 'home-faq',
                'type' => 'faq',
                'props' => [
                    'title' => (string) data_get($storefront, 'pages.faq.title', 'Frequently Ask Questions'),
                    'items' => is_array(data_get($storefront, 'pages.faq.items')) ? data_get($storefront, 'pages.faq.items') : [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $blocks
     */
    private function syncLegacyFieldsFromHomeBlocks(array &$storefront, array $blocks): void
    {
        $heroBlock = collect($blocks)->firstWhere('type', 'hero');
        if (is_array($heroBlock)) {
            $props = is_array($heroBlock['props'] ?? null) ? $heroBlock['props'] : [];
            $storefront['hero'] = [
                'headline' => (string) ($props['headline'] ?? data_get($storefront, 'hero.headline', '')),
                'subheadline' => (string) ($props['subheadline'] ?? data_get($storefront, 'hero.subheadline', '')),
                'cta_label' => (string) ($props['cta_label'] ?? data_get($storefront, 'hero.cta_label', 'Shop now')),
            ];
            if (! empty($props['image_url'])) {
                $storefront['media'] = array_merge(
                    is_array($storefront['media'] ?? null) ? $storefront['media'] : [],
                    ['hero_image_url' => $props['image_url']],
                );
            }
        }

        $statsBlock = collect($blocks)->firstWhere('type', 'stats_row');
        if (is_array($statsBlock)) {
            $items = data_get($statsBlock, 'props.items');
            if (is_array($items)) {
                $storefront['home_stats'] = $items;
            }
        }

        $richTextBlock = collect($blocks)->firstWhere('type', 'rich_text');
        if (is_array($richTextBlock)) {
            $props = is_array($richTextBlock['props'] ?? null) ? $richTextBlock['props'] : [];
            if (! empty($props['title'])) {
                $storefront['about']['title'] = (string) $props['title'];
            }
            if (! empty($props['body'])) {
                $storefront['about']['body'] = (string) $props['body'];
            }
            $badges = is_array($props['badges'] ?? null) ? $props['badges'] : [];
            if ($badges !== []) {
                $storefront['value_props'] = collect($badges)
                    ->map(fn (array $badge): array => [
                        'title' => (string) ($badge['value'] ?? ''),
                        'body' => (string) ($badge['label'] ?? ''),
                    ])
                    ->values()
                    ->all();
            }
        }

        $featureBlock = collect($blocks)->firstWhere('type', 'feature_grid');
        if (is_array($featureBlock)) {
            $items = data_get($featureBlock, 'props.items');
            if (is_array($items) && $items !== []) {
                $storefront['value_props'] = $items;
            }
        }

        $faqBlock = collect($blocks)->firstWhere('type', 'faq');
        if (is_array($faqBlock)) {
            $props = is_array($faqBlock['props'] ?? null) ? $faqBlock['props'] : [];
            $pages = is_array($storefront['pages'] ?? null) ? $storefront['pages'] : [];
            $existingFaqBlocks = data_get($pages, 'faq.blocks');
            $pages['faq'] = [
                'title' => (string) ($props['title'] ?? data_get($storefront, 'pages.faq.title', 'Frequently asked questions')),
                'source' => 'merchant',
                'items' => is_array($props['items'] ?? null) ? $props['items'] : (data_get($storefront, 'pages.faq.items') ?? []),
            ];
            if (is_array($existingFaqBlocks) && $existingFaqBlocks !== []) {
                $pages['faq']['blocks'] = $existingFaqBlocks;
            }
            $pages['home'] = ['blocks' => $blocks];
            $storefront['pages'] = $this->ensureDefaultPages($storefront, $pages);
        }
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function ensureDefaultPages(array $storefront, array $pages): array
    {
        $pages['about'] ??= [
            'title' => (string) data_get($storefront, 'about.title', ''),
            'body' => (string) data_get($storefront, 'about.body', ''),
            'source' => 'ai_generated',
        ];
        $pages['contact'] ??= [
            'title' => 'Contact us',
            'body' => '',
            'email' => null,
            'phone' => null,
            'source' => 'ai_generated',
        ];
        $pages['faq'] ??= [
            'title' => 'Frequently asked questions',
            'source' => 'ai_generated',
            'items' => [],
        ];
        $pages['privacy_policy'] ??= [
            'title' => 'Privacy policy',
            'body' => '',
            'source' => 'platform_default',
        ];

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function findHomeBlockIndex(array $blocks, string $blockId): int
    {
        foreach ($blocks as $index => $block) {
            if (($block['id'] ?? null) === $blockId) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * @param  list<string>  $currentOrder
     * @return list<string>
     */
    private function buildOrderWithFaqBeforeProducts(array $currentOrder): array
    {
        $without = array_values(array_filter(
            $currentOrder,
            fn (string $id): bool => ! in_array($id, ['home-faq', 'featured-products'], true),
        ));
        $anchorIndex = array_search('trust-features', $without, true);
        $insertAt = $anchorIndex === false ? count($without) : $anchorIndex + 1;

        return [
            ...array_slice($without, 0, $insertAt),
            'home-faq',
            'featured-products',
            ...array_slice($without, $insertAt),
        ];
    }

    /**
     * @param  list<string>  $currentOrder
     * @return list<string>
     */
    private function buildOrderWithProductsBeforeFaq(array $currentOrder): array
    {
        $without = array_values(array_filter(
            $currentOrder,
            fn (string $id): bool => ! in_array($id, ['home-faq', 'featured-products'], true),
        ));
        $anchorIndex = array_search('trust-features', $without, true);
        $insertAt = $anchorIndex === false ? count($without) : $anchorIndex + 1;

        return [
            ...array_slice($without, 0, $insertAt),
            'featured-products',
            'home-faq',
            ...array_slice($without, $insertAt),
        ];
    }

    private function canRemoveHomeBlock(string $blockId): bool
    {
        return $blockId !== 'hero-main';
    }

    private function isAddableHomeBlockType(string $type): bool
    {
        return in_array($type, [
            'stats_row',
            'rich_text',
            'cta_banner',
            'feature_grid',
            'product_grid',
            'category_showcase',
            'faq',
        ], true);
    }

    /**
     * @param  list<string>  $existingIds
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function createHomeBlock(array $storefront, string $type, array $existingIds, array $props = []): array
    {
        return [
            'id' => $this->generateHomeBlockId($type, $existingIds),
            'type' => $type,
            'props' => array_merge($this->defaultHomeBlockProps($storefront, $type), $props),
        ];
    }

    /**
     * @param  list<string>  $existingIds
     */
    private function generateHomeBlockId(string $type, array $existingIds): string
    {
        $base = match ($type) {
            'stats_row' => 'home-stats',
            'rich_text' => 'about-spotlight',
            'cta_banner' => 'promo-banner',
            'feature_grid' => 'feature-grid',
            'product_grid' => 'featured-products',
            'category_showcase' => 'category-showcase',
            'faq' => 'home-faq',
            default => str_replace('_', '-', $type),
        };

        if (! in_array($base, $existingIds, true)) {
            return $base;
        }

        $counter = 2;
        while (in_array("{$base}-{$counter}", $existingIds, true)) {
            $counter++;
        }

        return "{$base}-{$counter}";
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    private function defaultHomeBlockProps(array $storefront, string $type): array
    {
        $valueProps = is_array($storefront['value_props'] ?? null) && ($storefront['value_props'] ?? []) !== []
            ? $storefront['value_props']
            : [
                ['title' => '100% organic', 'body' => 'Botanical ingredients chosen for gentle daily care.'],
                ['title' => 'Clinical feel', 'body' => 'Simple formulas that support comfort, glow, and consistency.'],
                ['title' => 'Herbal products', 'body' => 'Clean textures made to layer easily in any routine.'],
            ];

        return match ($type) {
            'stats_row' => [
                'items' => is_array($storefront['home_stats'] ?? null) && ($storefront['home_stats'] ?? []) !== []
                    ? $storefront['home_stats']
                    : $this->defaultHomeStats($storefront),
            ],
            'rich_text' => [
                'title' => (string) data_get($storefront, 'about.title', ''),
                'body' => (string) data_get($storefront, 'about.body', ''),
                'image_url' => null,
                'badges' => collect(array_slice($valueProps, 0, 3))
                    ->map(fn (array $item): array => [
                        'value' => (string) ($item['title'] ?? ''),
                        'label' => (string) ($item['body'] ?? ''),
                    ])
                    ->values()
                    ->all(),
            ],
            'cta_banner' => [
                'title' => 'Limited-time offer',
                'body' => 'Discover a calm add-on for your daily routine with lightweight textures and botanical actives.',
                'bullets' => [
                    'Easy to layer morning or evening.',
                    'Designed for visible glow and comfort.',
                    'A simple step customers understand quickly.',
                ],
                'cta_label' => 'Shop now',
                'cta_href' => '/products',
                'image_url' => null,
                'layout' => 'text_left',
            ],
            'feature_grid' => [
                'title' => 'Why customers choose us',
                'body' => 'Thoughtful formulas, calm textures, and trust blocks that match your brand story.',
                'items' => array_slice($valueProps, 0, 3),
            ],
            'product_grid' => [
                'title' => 'Shop the line',
                'limit' => 4,
            ],
            'category_showcase' => $this->defaultCategoryShowcaseProps($storefront),
            'faq' => [
                'title' => (string) data_get($storefront, 'pages.faq.title', 'Frequently asked questions'),
                'items' => array_slice(is_array(data_get($storefront, 'pages.faq.items')) ? data_get($storefront, 'pages.faq.items') : [], 0, 4),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function categoryShowcaseBlockProps(array $storefront): array
    {
        return $this->defaultCategoryShowcaseProps($storefront);
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    private function defaultCategoryShowcaseProps(array $storefront): array
    {
        $templateId = (string) data_get($storefront, 'template.id', 'classic');

        if ($templateId === 'beauty') {
            return [
                'title' => 'Choose your style',
                'layout' => 'style_tiles',
                'items' => [
                    ['label' => 'Wefted hair & closures', 'image_url' => 'https://images.unsplash.com/photo-1499952127939-9bbf5af6c51c?auto=format&fit=crop&w=900&q=88'],
                    ['label' => 'Kinky curl', 'image_url' => 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=900&q=88'],
                    ['label' => 'Blowout volume', 'image_url' => 'https://images.unsplash.com/photo-1531123897727-8f129e1688ce?auto=format&fit=crop&w=900&q=88'],
                    ['label' => 'Sleek ponytails', 'image_url' => 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=900&q=88'],
                ],
            ];
        }

        if (in_array($templateId, ['minimalistic', 'classic'], true)) {
            return [
                'title' => 'Shop by category',
                'layout' => 'compact_grid',
                'items' => [
                    ['label' => 'Hoodies', 'image_url' => 'https://images.unsplash.com/photo-1620799140408-edc6dcb6d633?auto=format&fit=crop&w=900&q=85'],
                    ['label' => 'Sweatshirts', 'image_url' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=900&q=85'],
                    ['label' => 'T-Shirts', 'image_url' => 'https://images.unsplash.com/photo-1503341504253-dff4815485f1?auto=format&fit=crop&w=900&q=85'],
                ],
            ];
        }

        return [
            'title' => 'Shop the Essentials',
            'eyebrow' => 'Minimal. Comfortable. Timeless.',
            'layout' => 'editorial_grid',
            'items' => [
                ['label' => 'Hoodies', 'image_url' => 'https://images.unsplash.com/photo-1620799140408-edc6dcb6d633?auto=format&fit=crop&w=900&q=85'],
                ['label' => 'Sweatshirts', 'image_url' => 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=900&q=85'],
                ['label' => 'T-Shirts', 'image_url' => 'https://images.unsplash.com/photo-1503341504253-dff4815485f1?auto=format&fit=crop&w=900&q=85'],
                ['label' => 'Everyday Basics', 'image_url' => 'https://images.unsplash.com/photo-1487222477894-8943e31ef7b2?auto=format&fit=crop&w=900&q=85'],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function insertHomeBlock(array &$blocks, array $block, ?string $after = null, ?string $before = null): void
    {
        if (is_string($before)) {
            $index = $this->findHomeBlockIndex($blocks, $before);
            if ($index >= 0) {
                array_splice($blocks, $index, 0, [$block]);

                return;
            }
        }

        if (is_string($after)) {
            $index = $this->findHomeBlockIndex($blocks, $after);
            if ($index >= 0) {
                array_splice($blocks, $index + 1, 0, [$block]);

                return;
            }
        }

        $blocks[] = $block;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array{after?: string, before?: string}
     */
    private function resolvePlacementFromInstruction(string $instruction, array $blocks): array
    {
        $lower = strtolower($instruction);

        if (preg_match('/\b(above|before)\b.*\bfaq\b/u', $lower) === 1) {
            return ['before' => 'home-faq'];
        }

        if (preg_match('/\b(below|after|under)\b.*\bfaq\b/u', $lower) === 1) {
            return ['after' => 'home-faq'];
        }

        if (preg_match('/\b(above|before)\b.*\bproduct/u', $lower) === 1) {
            return ['before' => 'featured-products'];
        }

        if (preg_match('/\b(below|after|under)\b.*\bproduct/u', $lower) === 1) {
            return ['after' => 'featured-products'];
        }

        if (preg_match('/\b(above|before)\b.*\btrust\b/u', $lower) === 1) {
            return ['before' => 'trust-features'];
        }

        if (preg_match('/\b(below|after|under)\b.*\btrust\b/u', $lower) === 1) {
            return ['after' => 'trust-features'];
        }

        if (preg_match('/\b(above|before)\b.*\bhero\b/u', $lower) === 1) {
            return ['after' => 'hero-main'];
        }

        if (preg_match('/\b(below|after|under)\b.*\bhero\b/u', $lower) === 1) {
            return ['after' => 'hero-main'];
        }

        return ['after' => 'trust-features'];
    }

    private function resolveBlockTypeFromInstruction(string $instruction): ?string
    {
        $lower = strtolower($instruction);

        if (preg_match('/\b(promo|banner|cta)\b/u', $lower) === 1) {
            return 'cta_banner';
        }

        if (preg_match('/\b(testimonial|trust|feature|highlight)\b/u', $lower) === 1) {
            return 'feature_grid';
        }

        if (preg_match('/\b(stats|statistics)\b/u', $lower) === 1) {
            return 'stats_row';
        }

        if (preg_match('/\b(products?|shop)\b.*\b(section|grid|area)\b/u', $lower) === 1
            || preg_match('/\b(product grid|shop section)\b/u', $lower) === 1) {
            return 'product_grid';
        }

        if (preg_match('/\b(categor(y|ies)|essentials|shop by|style tiles?|choose your style|shop the essentials)\b/u', $lower) === 1) {
            return 'category_showcase';
        }

        if (preg_match('/\bfaq\b|\bquestions\b/u', $lower) === 1) {
            return 'faq';
        }

        if (preg_match('/\b(about|spotlight|story)\b/u', $lower) === 1) {
            return 'rich_text';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    private function resolveRemoveBlockId(string $instruction, array $blocks): ?string
    {
        $lower = strtolower($instruction);

        if (preg_match('/\b(stats|statistics)\b/u', $lower) === 1) {
            return 'home-stats';
        }

        if (preg_match('/\b(products?|shop)\b/u', $lower) === 1 && preg_match('/\b(section|grid|area|line)\b/u', $lower) === 1) {
            $block = collect($blocks)->firstWhere('type', 'product_grid');

            return is_array($block) ? (string) ($block['id'] ?? 'featured-products') : 'featured-products';
        }

        if (preg_match('/\b(categor(y|ies)|essentials|style tiles?)\b/u', $lower) === 1) {
            $block = collect($blocks)->firstWhere('type', 'category_showcase');

            return is_array($block) ? (string) ($block['id'] ?? 'category-showcase') : 'category-showcase';
        }

        if (preg_match('/\bfaq\b|\bquestions\b/u', $lower) === 1) {
            return 'home-faq';
        }

        if (preg_match('/\b(trust|feature|highlight|testimonial)\b/u', $lower) === 1) {
            return 'trust-features';
        }

        if (preg_match('/\b(about|spotlight)\b/u', $lower) === 1) {
            return 'about-spotlight';
        }

        if (preg_match('/\b(promo|banner|serum)\b/u', $lower) === 1) {
            $cta = collect($blocks)->firstWhere('type', 'cta_banner');

            return is_array($cta) ? (string) ($cta['id'] ?? 'serum-promo') : 'serum-promo';
        }

        return null;
    }

    private function blockTypeLabel(string $type): string
    {
        return match ($type) {
            'hero' => 'homepage hero',
            'stats_row' => 'stats section',
            'rich_text' => 'about spotlight',
            'cta_banner' => 'promo banner',
            'feature_grid' => 'trust highlights',
            'product_grid' => 'product section',
            'category_showcase' => 'category showcase',
            'faq' => 'FAQ section',
            default => 'homepage section',
        };
    }

    private function blockLabel(string $blockId): string
    {
        return match ($blockId) {
            'hero-main' => 'homepage hero',
            'home-stats' => 'homepage stats',
            'about-spotlight' => 'about spotlight',
            'serum-promo', 'promo-banner' => 'promo banner',
            'trust-features' => 'trust highlights',
            'featured-products' => 'product section',
            'home-faq' => 'homepage FAQ',
            default => 'homepage section',
        };
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    public function resolvePageBlocks(array $storefront, string $page): array
    {
        return match ($page) {
            'about' => $this->migrateAboutBlocks($storefront),
            'contact' => $this->migrateContactBlocks($storefront),
            'faq' => $this->migrateFaqBlocks($storefront),
            default => $this->resolveHomeBlocks($storefront),
        };
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $blocks
     */
    public function persistPageBlocks(array &$storefront, string $page, array $blocks): void
    {
        $pages = is_array($storefront['pages'] ?? null) ? $storefront['pages'] : [];

        if ($page === 'home') {
            $pages['home'] = ['blocks' => $blocks];
            $storefront['pages'] = $this->ensureDefaultPages($storefront, $pages);
            $this->syncLegacyFieldsFromHomeBlocks($storefront, $blocks);

            return;
        }

        $pages[$page] = array_merge(is_array($pages[$page] ?? null) ? $pages[$page] : [], ['blocks' => $blocks]);
        $storefront['pages'] = $this->ensureDefaultPages($storefront, $pages);

        if ($page === 'about') {
            $richText = collect($blocks)->firstWhere('type', 'rich_text');
            if (is_array($richText)) {
                $props = is_array($richText['props'] ?? null) ? $richText['props'] : [];
                if (! empty($props['title'])) {
                    $storefront['about']['title'] = (string) $props['title'];
                    $storefront['pages']['about']['title'] = (string) $props['title'];
                }
                if (! empty($props['body'])) {
                    $storefront['about']['body'] = (string) $props['body'];
                    $storefront['pages']['about']['body'] = (string) $props['body'];
                }
            }
        }

        if ($page === 'contact') {
            $intro = collect($blocks)->firstWhere('type', 'rich_text');
            if (is_array($intro)) {
                $props = is_array($intro['props'] ?? null) ? $intro['props'] : [];
                if (! empty($props['title'])) {
                    $storefront['pages']['contact']['title'] = (string) $props['title'];
                }
                if (! empty($props['body'])) {
                    $storefront['pages']['contact']['body'] = (string) $props['body'];
                }
            }
        }

        if ($page === 'faq') {
            $faq = collect($blocks)->firstWhere('type', 'faq');
            if (is_array($faq)) {
                $props = is_array($faq['props'] ?? null) ? $faq['props'] : [];
                $storefront['pages']['faq']['title'] = (string) ($props['title'] ?? data_get($storefront, 'pages.faq.title', 'Frequently asked questions'));
                $storefront['pages']['faq']['items'] = is_array($props['items'] ?? null) ? $props['items'] : [];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function migrateAboutBlocks(array $storefront): array
    {
        $existing = data_get($storefront, 'pages.about.blocks');
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        $about = is_array($storefront['pages']['about'] ?? null)
            ? $storefront['pages']['about']
            : (is_array($storefront['about'] ?? null) ? $storefront['about'] : []);
        $valueProps = is_array($storefront['value_props'] ?? null) ? $storefront['value_props'] : [];

        return [
            [
                'id' => 'about-main',
                'type' => 'rich_text',
                'props' => [
                    'title' => (string) ($about['title'] ?? data_get($storefront, 'about.title', '')),
                    'body' => (string) ($about['body'] ?? data_get($storefront, 'about.body', '')),
                    'image_url' => data_get($storefront, 'media.about_image_url'),
                    'badges' => collect(array_slice($valueProps, 0, 3))
                        ->map(fn (array $item): array => [
                            'value' => (string) ($item['title'] ?? ''),
                            'label' => (string) ($item['body'] ?? ''),
                        ])
                        ->values()
                        ->all(),
                ],
            ],
            [
                'id' => 'about-features',
                'type' => 'feature_grid',
                'props' => [
                    'title' => 'Why choose us',
                    'body' => 'Thoughtful formulas and trust blocks that match your brand story.',
                    'items' => array_slice($valueProps, 0, 3),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function migrateContactBlocks(array $storefront): array
    {
        $existing = data_get($storefront, 'pages.contact.blocks');
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        $contact = is_array($storefront['pages']['contact'] ?? null) ? $storefront['pages']['contact'] : [];

        return [
            [
                'id' => 'contact-intro',
                'type' => 'rich_text',
                'props' => [
                    'title' => (string) ($contact['title'] ?? 'Contact us'),
                    'body' => (string) ($contact['body'] ?? 'Have a question about an order or product? Reach out and our team will get back to you shortly.'),
                ],
            ],
            [
                'id' => 'contact-form',
                'type' => 'contact_form',
                'props' => $this->defaultContactFormProps($storefront),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @return list<array<string, mixed>>
     */
    private function migrateFaqBlocks(array $storefront): array
    {
        $existing = data_get($storefront, 'pages.faq.blocks');
        if (is_array($existing) && $existing !== []) {
            return $existing;
        }

        return [
            [
                'id' => 'faq-main',
                'type' => 'faq',
                'props' => [
                    'title' => (string) data_get($storefront, 'pages.faq.title', 'Frequently asked questions'),
                    'items' => is_array(data_get($storefront, 'pages.faq.items')) ? data_get($storefront, 'pages.faq.items') : [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $storefront
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function defaultContactFormProps(array $storefront, array $fields = []): array
    {
        if ($fields === []) {
            $fields = [
                ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
            ];
        }

        return [
            'title' => 'Get in touch',
            'intro' => (string) data_get($storefront, 'pages.contact.body', 'Questions about an order or product?'),
            'fields' => $fields,
            'submit_label' => 'Send message',
            'success_message' => "Thanks — we'll reply soon.",
        ];
    }

    /**
     * Honest default trust/stat copy — never invent fake client counts or ratings.
     *
     * @param  array<string, mixed>  $storefront
     * @return list<array{value: string, label: string}>
     */
    private function defaultHomeStats(array $storefront): array
    {
        $businessName = trim((string) (
            data_get($storefront, 'about.title')
            ?: data_get($storefront, 'hero.headline')
            ?: data_get($storefront, 'seo.title')
            ?: 'our store'
        ));
        $businessName = preg_replace('/^(About|Discover the nature with)\s+/i', '', $businessName) ?: 'our store';
        $businessName = trim((string) preg_replace('/\s*\|\s*.*$/', '', $businessName));

        return [
            ['value' => "Crafted for {$businessName} customers", 'label' => 'calm routines, clean formulas'],
            ['value' => 'Everyday glow', 'label' => 'simple steps that layer easily'],
            ['value' => 'Gentle care', 'label' => 'formulas chosen for comfort'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveContactFormFieldsFromInstruction(string $instruction): array
    {
        $lower = strtolower($instruction);
        $fields = [];

        if (preg_match('/\bname\b/u', $lower) === 1) {
            $fields[] = ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true];
        }

        if (preg_match('/\bemail\b/u', $lower) === 1) {
            $fields[] = ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true];
        }

        if (preg_match('/\border number\b/u', $lower) === 1) {
            $fields[] = ['name' => 'order_number', 'label' => 'Order number', 'type' => 'text', 'required' => true];
        }

        if (preg_match('/\bphone\b/u', $lower) === 1) {
            $fields[] = ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => false];
        }

        if (preg_match('/\bmessage\b/u', $lower) === 1) {
            $fields[] = ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true];
        }

        if ($fields !== []) {
            return $fields;
        }

        return [
            ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ];
    }
}
