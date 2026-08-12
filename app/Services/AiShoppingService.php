<?php

namespace App\Services;

use App\Agents\ShoppingPlannerAgent;
use App\Models\Store;
use App\Services\AgentExecutionLogService;
use App\Support\ShoppingQueryHeuristics;
use Illuminate\Support\Str;

class AiShoppingService
{
    /** @var list<array{agent: string, phase: string, title: string, detail?: string}> */
    private array $thinking = [];

    public function __construct(
        private readonly ShoppingPlannerAgent $planner,
        private readonly LookBuilderService $looks,
        private readonly ProductRecommendationService $recommendations,
        private readonly ProductStyleEnrichmentService $enrichment,
        private readonly StoreShoppingContextService $shoppingContext,
        private readonly AgentExecutionLogService $agentLogs,
        private readonly ShoppingSearchService $shoppingSearch,
        private readonly ShopperIntentLogService $intentLog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(Store $store): array
    {
        return $this->shoppingContext->forStore($store);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function shop(Store $store, array $input): array
    {
        $this->thinking = [];
        $message = trim((string) ($input['message'] ?? ''));
        $chips = is_array($input['chips'] ?? null) ? $input['chips'] : [];
        $previousIntent = is_array($input['intent'] ?? null) ? $input['intent'] : null;
        $previousLook = is_array($input['look'] ?? null) ? $input['look'] : null;
        $sessionId = is_string($input['session_id'] ?? null) ? trim($input['session_id']) : null;
        $shopper = $this->shoppingContext->forStore($store);

        return $this->agentLogs->using([
            'store_id' => $store->id,
            'merchant_id' => $store->merchant_id,
            'source' => 'ai_shopper',
        ], function () use ($store, $message, $chips, $previousIntent, $previousLook, $shopper, $sessionId) {
            $this->think('ShoppingPlanner', 'start', 'Understanding your request', $message !== '' ? $message : 'Quick picks');

            [$intent, $interpretation, $plan] = $this->resolveIntent($store, $shopper, $message, $chips, $previousIntent);

            if (is_array($interpretation)) {
                $this->think(
                    'ShoppingPlanner',
                    'analyze',
                    'What you want',
                    (string) ($interpretation['task_summary'] ?? ''),
                );
            }

            $action = is_string($intent['action'] ?? null) ? $intent['action'] : 'search_products';
            $this->think('ShoppingPlanner', 'plan', 'Next step', (string) ($plan['intent_summary'] ?? $action));

            $recommendation = null;
            $shouldRecommend = $this->shouldExecuteRecommendation($intent, $message, $action);

            if ($shouldRecommend) {
                $this->think('Catalog', 'search', 'Searching the catalog', $this->searchDetail($action, $intent));
                $recommendation = $this->buildRecommendation($store, $shopper, $intent, $previousLook, $action, $message);
                $this->think(
                    'Catalog',
                    'complete',
                    $recommendation ? 'Found matches' : 'No exact match',
                    $recommendation
                        ? count($recommendation['items'] ?? []).' option(s)'
                        : 'Will explain what is available instead.',
                );
            }

            $reply = $this->composeReply($shopper, $intent, $recommendation, $message, $action);

            $this->intentLog->record(
                $store,
                $message,
                $chips,
                $intent,
                $interpretation,
                $recommendation,
                $sessionId,
            );

            return [
                'reply' => $reply,
                'intent' => $intent,
                'interpretation' => $interpretation,
                'plan' => $plan,
                'thinking' => $this->thinking,
                'shopper' => $shopper,
                'look' => $recommendation,
                'recommendation' => $recommendation,
                'suggestions' => $this->suggestions($shopper, $intent, $recommendation, $action),
                'catalog_enriched' => true,
            ];
        });
    }

    /**
     * @return array{updated: int}
     */
    public function enrichCatalog(Store $store, bool $force = false, int $limit = 60): array
    {
        $updated = $this->enrichment->enrichStore($store, null, $limit, $force);

        return ['updated' => $updated];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  list<mixed>  $chips
     * @param  array<string, mixed>|null  $previousIntent
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $shopper
     * @param  list<mixed>  $chips
     * @param  array<string, mixed>|null  $previousIntent
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     */
    private function resolveIntent(Store $store, array $shopper, string $message, array $chips, ?array $previousIntent): array
    {
        $chipIntent = $this->intentFromChips($chips);
        $planResult = null;

        if ($message !== '' || $chips !== []) {
            $planResult = $this->planner->execute([
                'message' => $message !== '' ? $message : $this->chipsToMessage($chips),
                'chips' => $chips,
                'previous_intent' => $previousIntent,
                'store_currency' => 'NGN',
                'store_context' => $shopper,
            ]);
        }

        $merged = $this->defaultIntent($shopper);
        if (is_array($previousIntent)) {
            $merged = $this->mergeIntent($merged, $previousIntent);
        }
        $merged = $this->mergeIntent($merged, $chipIntent);

        $interpretation = is_array($planResult['interpretation'] ?? null) ? $planResult['interpretation'] : null;
        $plan = is_array($planResult['plan'] ?? null) ? $planResult['plan'] : null;

        if (is_array($planResult['intent'] ?? null)) {
            $merged = $this->mergeIntent($merged, $planResult['intent']);
            if (is_string($planResult['intent']['reply'] ?? null) && trim($planResult['intent']['reply']) !== '') {
                $merged['reply'] = trim($planResult['intent']['reply']);
            }
        }

        $merged['action'] = is_string($plan['action'] ?? null) ? $plan['action'] : 'search_products';

        if (($merged['budget_max'] ?? null) === null && $message !== '') {
            $merged['budget_max'] = $this->parseBudgetFromMessage($message);
        }

        if (empty($merged['attributes']) && str_contains(Str::lower($message), 'wireless')) {
            $merged['attributes'] = array_values(array_unique([
                ...($merged['attributes'] ?? []),
                'wireless',
            ]));
        }

        if (ShoppingQueryHeuristics::isCatalogOverviewQuestion($message)) {
            $merged['action'] = 'catalog_overview';
            $merged['product_query'] = null;
            $merged['needs_clarification'] = false;
            if (trim((string) ($merged['reply'] ?? '')) === '') {
                $categoryNames = array_values(array_filter(array_map(
                    fn ($category) => is_array($category) ? (string) ($category['name'] ?? '') : '',
                    $shopper['categories'] ?? [],
                )));
                $merged['reply'] = ShoppingQueryHeuristics::catalogOverviewReply($shopper, $categoryNames);
            }
        } else {
            $merged['product_query'] = $this->normalizeProductQuery(
                is_string($merged['product_query'] ?? null) ? trim($merged['product_query']) : '',
                $message,
            );
        }

        $merged['needs_clarification'] = (bool) ($merged['needs_clarification'] ?? false);
        if ($this->hasExplicitBrowseIntent($merged, $message) || in_array($merged['action'], ['search_products', 'catalog_overview', 'build_look'], true)) {
            $merged['needs_clarification'] = false;
        }
        if ($this->shouldAskClarification($shopper, $merged, $message, $chips)) {
            $merged['needs_clarification'] = true;
            $merged['action'] = 'clarify';
            $merged['reply'] = $shopper['welcome_message'];
        }

        return [$merged, $interpretation, $plan];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @return array<string, mixed>|null
     */
    private function buildRecommendation(Store $store, array $shopper, array $intent, ?array $previousLook, string $action, string $message = ''): ?array
    {
        if ($action === 'catalog_overview') {
            return $this->recommendations->catalogOverview($store, $intent);
        }

        if ($action === 'build_look' || $this->shouldBuildLook($shopper, $intent, $previousLook)) {
            return $this->looks->build($store, $intent, $previousLook);
        }

        if (! in_array($action, ['search_products'], true)) {
            return null;
        }

        return $this->shoppingSearch->searchAndPick(
            $store,
            $message,
            $intent,
            $shopper,
            fn (string $agent, string $phase, string $title, string $detail = '') => $this->think($agent, $phase, $title, $detail),
        );
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function shouldExecuteRecommendation(array $intent, string $message, string $action): bool
    {
        if (in_array($action, ['greeting', 'clarify'], true)) {
            return false;
        }

        if ($action === 'catalog_overview' || $action === 'build_look' || $action === 'search_products') {
            return true;
        }

        if ($intent['needs_clarification'] ?? false) {
            return $this->hasExplicitBrowseIntent($intent, $message);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function searchDetail(string $action, array $intent): string
    {
        return match ($action) {
            'catalog_overview' => 'Picking highlights across categories',
            'build_look' => 'Building a complete look',
            default => trim((string) ($intent['product_query'] ?? '')) ?: 'Matching products to your request',
        };
    }

    private function think(string $agent, string $phase, string $title, string $detail = ''): void
    {
        $entry = [
            'agent' => $agent,
            'phase' => $phase,
            'title' => $title,
            'detail' => $detail,
        ];
        $this->thinking[] = $entry;
        $this->agentLogs->recordPhase($agent, $phase, $title, $detail);
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     */
    private function shouldBuildLook(array $shopper, array $intent, ?array $previousLook): bool
    {
        if (! ($shopper['supports_looks'] ?? false)) {
            return false;
        }

        if ($this->intentWantsProductSearch($intent)) {
            return false;
        }

        if (filled($intent['occasion'] ?? null) || ! empty($intent['styles'])) {
            return true;
        }

        $fashionRevisions = [
            'change_bag', 'change_shoes', 'change_dress', 'change_accessories',
            'more_elegant', 'more_casual',
        ];
        if (in_array($intent['revision'] ?? null, $fashionRevisions, true)) {
            return true;
        }

        if (is_array($previousLook) && ($previousLook['type'] ?? '') === 'look') {
            return in_array($intent['revision'] ?? null, ['cheaper', 'more_expensive', ...$fashionRevisions], true);
        }

        $query = Str::lower(trim((string) ($intent['product_query'] ?? '')));
        foreach (['outfit', 'complete look', 'what should i wear', 'style me', 'put together a look'] as $phrase) {
            if (str_contains($query, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function intentWantsProductSearch(array $intent): bool
    {
        if (in_array($intent['action'] ?? null, ['catalog_overview', 'greeting', 'clarify'], true)) {
            return false;
        }

        if (ShoppingQueryHeuristics::isCatalogOverviewQuestion((string) ($intent['product_query'] ?? ''))) {
            return false;
        }

        if (filled($intent['occasion'] ?? null) || ! empty($intent['styles'])) {
            return false;
        }

        $query = Str::lower(trim((string) ($intent['product_query'] ?? '')));
        $productSignals = [
            'headphone', 'earbud', 'earphone', 'airpod', 'laptop', 'macbook', 'notebook',
            'camera', 'dslr', 'mirrorless', 'phone', 'iphone', 'samsung', 'tablet', 'ipad',
            'speaker', 'soundbar', 'ps5', 'xbox', 'nintendo', 'controller', 'pad', 'console',
            'charger', 'cable', 'monitor', 'keyboard', 'mouse', 'watch', 'smartwatch',
            'serum', 'moisturizer', 'cleanser', 'lipstick', 'makeup', 'skincare', 'perfume',
            'boubou', 'gown', 'dress', 'suit', 'shirt', 'shoe', 'bag',
        ];

        foreach ($productSignals as $signal) {
            if ($query !== '' && str_contains($query, $signal)) {
                foreach (['outfit', 'complete look', 'style me', 'what to wear'] as $phrase) {
                    if (str_contains($query, $phrase)) {
                        return false;
                    }
                }

                return true;
            }
        }

        foreach ($this->tags($intent['categories'] ?? []) as $category) {
            if (in_array($category, ['laptop', 'camera', 'phone', 'audio', 'headphone', 'electronics', 'tablet', 'gaming'], true)) {
                return true;
            }
        }

        if (! empty($intent['attributes']) || filled($intent['use_case'] ?? null)) {
            return true;
        }

        return $query !== '' && ! str_contains($query, 'look') && ! str_contains($query, 'outfit');
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function hasExplicitBrowseIntent(array $intent, string $message): bool
    {
        if (! $this->intentWantsProductSearch($intent)) {
            return false;
        }

        $messageLower = Str::lower(trim($message));
        $query = Str::lower(trim((string) ($intent['product_query'] ?? '')));

        foreach ([
            'show me', 'show the', 'show all', 'show my', 'list', 'browse', 'see the', 'see all',
            'what laptops', 'what phones', 'what cameras', 'what do you have', 'in the store', 'in this store',
        ] as $phrase) {
            if (($messageLower !== '' && str_contains($messageLower, $phrase))
                || ($query !== '' && str_contains($query, $phrase))) {
                return true;
            }
        }

        if (! empty($intent['categories'])) {
            return $query !== '' || $messageLower !== '';
        }

        return false;
    }

    private function parseBudgetFromMessage(string $message): ?float
    {
        $value = Str::lower($message);

        if (preg_match('/under\s*[₦]?\s*(\d+(?:\.\d+)?)\s*k/i', $value, $m)) {
            return (float) $m[1] * 1000;
        }
        if (preg_match('/under\s*[₦]?\s*([\d,]+)/i', $value, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/[₦]\s*([\d,]+)/i', $value, $m)) {
            return (float) str_replace(',', '', $m[1]);
        }
        if (preg_match('/(\d+)\s*k\b/i', $value, $m)) {
            return (float) $m[1] * 1000;
        }

        return null;
    }

    private function normalizeProductQuery(string $fromAi, string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return $fromAi;
        }

        if ($fromAi === '' || strlen($fromAi) < 3) {
            return $message;
        }

        // Prefer the shopper's exact words when the model returns a vague paraphrase.
        $aiLower = Str::lower($fromAi);
        $msgLower = Str::lower($message);
        $productNouns = [
            'laptop', 'headphone', 'camera', 'phone', 'tablet', 'watch', 'console', 'monitor',
            'keyboard', 'mouse', 'dress', 'gown', 'suit', 'shirt', 'shoe', 'bag',
        ];

        foreach ($productNouns as $noun) {
            if (str_contains($msgLower, $noun) && ! str_contains($aiLower, $noun)) {
                return $message;
            }
        }

        return $fromAi;
    }

    /**
     * @return list<string>
     */
    private function tags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_string($item) ? Str::lower(trim($item)) : '',
            $value,
        )));
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @return array<string, mixed>
     */
    private function defaultIntent(array $shopper): array
    {
        return [
            'reply' => $shopper['welcome_message'],
            'action' => 'clarify',
            'occasion' => null,
            'styles' => [],
            'budget_max' => null,
            'currency' => 'NGN',
            'gender' => null,
            'categories' => [],
            'use_case' => null,
            'product_query' => null,
            'attributes' => [],
            'revision' => null,
            'needs_clarification' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  list<mixed>  $chips
     */
    private function shouldAskClarification(array $shopper, array $intent, string $message, array $chips): bool
    {
        if ($message !== '' || $chips !== []) {
            return false;
        }

        if (($intent['needs_clarification'] ?? false) === true) {
            return true;
        }

        if ($shopper['mode'] === StoreShoppingContextService::MODE_FASHION) {
            return $intent['occasion'] === null
                && ($intent['styles'] ?? []) === []
                && $intent['budget_max'] === null;
        }

        return $intent['product_query'] === null
            && ($intent['categories'] ?? []) === []
            && $intent['use_case'] === null
            && $intent['budget_max'] === null;
    }

    /**
     * @param  list<mixed>  $chips
     * @return array<string, mixed>
     */
    private function intentFromChips(array $chips): array
    {
        $intent = [];
        foreach ($chips as $chip) {
            if (! is_string($chip) && ! is_array($chip)) {
                continue;
            }
            if (is_array($chip)) {
                $type = (string) ($chip['type'] ?? '');
                $value = (string) ($chip['value'] ?? '');
            } else {
                [$type, $value] = array_pad(explode(':', $chip, 2), 2, '');
            }

            $type = Str::lower(trim($type));
            $value = Str::lower(trim($value));
            if ($value === '') {
                continue;
            }

            match ($type) {
                'occasion' => $intent['occasion'] = str_replace(' ', '_', $value),
                'style' => $intent['styles'] = array_values(array_unique([
                    ...($intent['styles'] ?? []),
                    str_replace(' ', '_', $value),
                ])),
                'category' => $intent['categories'] = array_values(array_unique([
                    ...($intent['categories'] ?? []),
                    str_replace(' ', '_', $value),
                ])),
                'use_case' => $intent['use_case'] = str_replace(' ', '_', $value),
                'budget' => $intent['budget_max'] = $this->parseBudget($value),
                'gender' => $intent['gender'] = in_array($value, ['female', 'male', 'unisex'], true) ? $value : null,
                'revision' => $intent['revision'] = $value,
                default => null,
            };
        }

        return $intent;
    }

    /**
     * @param  list<mixed>  $chips
     */
    private function chipsToMessage(array $chips): string
    {
        $parts = [];
        foreach ($chips as $chip) {
            if (is_string($chip)) {
                $parts[] = $chip;
            } elseif (is_array($chip)) {
                $parts[] = trim(($chip['type'] ?? '').' '.($chip['value'] ?? ''));
            }
        }

        return trim(implode(', ', array_filter($parts)));
    }

    private function parseBudget(string $value): ?float
    {
        $digits = preg_replace('/[^\d.]/', '', $value);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (preg_match('/(\d+)\s*[-–]\s*(\d+)\s*k/i', $value, $m)) {
            return (float) $m[2] * 1000;
        }
        if (preg_match('/<\s*(\d+)\s*k/i', $value, $m)) {
            return (float) $m[1] * 1000;
        }
        if (preg_match('/(\d+)\s*k\+/i', $value, $m)) {
            return (float) $m[1] * 1000 * 2;
        }
        if (preg_match('/(\d+)\s*k/i', $value, $m)) {
            return (float) $m[1] * 1000;
        }

        return (float) $digits;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeIntent(array $base, array $overlay): array
    {
        foreach (['occasion', 'currency', 'gender', 'revision', 'reply', 'use_case', 'product_query'] as $key) {
            if (array_key_exists($key, $overlay) && $overlay[$key] !== null && $overlay[$key] !== '') {
                $base[$key] = $overlay[$key];
            }
        }

        if (array_key_exists('budget_max', $overlay) && $overlay['budget_max'] !== null) {
            $base['budget_max'] = is_numeric($overlay['budget_max']) ? (float) $overlay['budget_max'] : null;
        }

        if (array_key_exists('needs_clarification', $overlay)) {
            $base['needs_clarification'] = (bool) $overlay['needs_clarification'];
        }

        foreach (['styles', 'categories', 'attributes'] as $listKey) {
            if (isset($overlay[$listKey]) && is_array($overlay[$listKey]) && $overlay[$listKey] !== []) {
                $base[$listKey] = array_values(array_unique(array_filter(
                    array_map(fn ($v) => is_string($v) ? Str::lower(trim($v)) : '', $overlay[$listKey]),
                )));
            }
        }

        if (
            is_string($base['product_query'] ?? null)
            && trim((string) $base['product_query']) === ''
            && is_string($overlay['message'] ?? null)
        ) {
            $base['product_query'] = trim($overlay['message']);
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $recommendation
     */
    private function composeReply(array $shopper, array $intent, ?array $recommendation, string $message, string $action): string
    {
        if (is_string($intent['reply'] ?? null) && trim($intent['reply']) !== '') {
            $base = trim($intent['reply']);
        } else {
            $base = 'Here’s what I’d recommend.';
        }

        if ($recommendation === null) {
            if ($intent['needs_clarification'] ?? false) {
                return $base;
            }

            if (in_array($action, ['catalog_overview', 'greeting', 'clarify'], true)) {
                return $base;
            }

            $query = trim((string) ($intent['product_query'] ?? ''));
            if ($query !== '' && $this->intentWantsProductSearch($intent)) {
                $clean = $this->cleanProductLabel($query);
                $budget = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
                    ? (float) $intent['budget_max']
                    : null;

                if ($budget !== null) {
                    return "I found {$clean} at {$shopper['store_name']}, but nothing under ₦".number_format($budget, 0)." right now. Say “raise my budget” or “show alternatives” and I’ll adjust.";
                }

                return "I couldn’t find any {$clean} in {$shopper['store_name']}’s catalog right now. Try browsing categories or ask what this store sells.";
            }

            return $base;
        }

        $count = count($recommendation['items'] ?? []);
        $total = number_format((float) ($recommendation['total_price'] ?? 0), 0);
        $currency = $recommendation['currency'] ?? 'NGN';
        $isLook = ($recommendation['type'] ?? '') === 'look';
        $isOverview = (bool) ($recommendation['overview'] ?? false);

        if ($isLook) {
            $suffix = " I put together a {$count}-piece look for {$currency} {$total}.";
            if (($recommendation['within_budget'] ?? true) === false) {
                $suffix .= ' It’s a little over budget — say “make it cheaper” and I’ll adjust.';
            } elseif ($shopper['supports_try_on']) {
                $suffix .= ' Want to see it on you, or tweak the bag/shoes?';
            } else {
                $suffix .= ' Want to change anything?';
            }
        } else {
            $suffix = $isOverview
                ? " Here are {$count} highlights from {$shopper['store_name']} starting at {$currency} {$total}."
                : " I found {$count} option".($count === 1 ? '' : 's').' from this store';
            if (! $isOverview) {
                if ($count > 1) {
                    $suffix .= " starting at {$currency} {$total}";
                } else {
                    $suffix .= " at {$currency} {$total}";
                }
            }
            $suffix .= '.';
            if (($recommendation['within_budget'] ?? true) === false) {
                $budget = isset($intent['budget_max']) && is_numeric($intent['budget_max'])
                    ? number_format((float) $intent['budget_max'], 0)
                    : null;
                if ($budget !== null) {
                    $suffix .= " These are above your ₦{$budget} budget — say “cheaper options” if you want me to keep looking.";
                } else {
                    $suffix .= ' These are slightly over budget — say “cheaper options”.';
                }
            } elseif (! $isOverview) {
                $suffix .= ' Say “show alternatives” if you want more choices.';
            }
        }

        if (str_contains(Str::lower($base), 'recommend') || str_contains(Str::lower($base), 'found')) {
            return $base.$suffix;
        }

        return $base.$suffix;
    }

    private function cleanProductLabel(string $query): string
    {
        $clean = preg_replace('/under\s+[₦\d,.k\s]+/iu', '', $query) ?? $query;
        $clean = preg_replace('/\b(show me|show the|show all|in the store|in this store)\b/iu', '', $clean) ?? $clean;

        return trim($clean) ?: trim($query);
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $recommendation
     * @return list<string>
     */
    private function suggestions(array $shopper, array $intent, ?array $recommendation, string $action): array
    {
        if ($action === 'catalog_overview') {
            return array_slice($shopper['default_suggestions'], 0, 3);
        }

        if ($recommendation === null) {
            return $shopper['default_suggestions'];
        }

        if (($recommendation['type'] ?? '') === 'look') {
            $suggestions = ['Make it cheaper', 'More elegant', 'Change the bag'];
            if ($shopper['supports_try_on']) {
                $suggestions[] = 'See it on me';
            }

            return $suggestions;
        }

        return [
            'Cheaper options',
            'Show alternatives',
            'Something better for work',
        ];
    }
}
