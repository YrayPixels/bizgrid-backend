<?php

namespace App\Services;

use App\Agents\ShoppingIntentAgent;
use App\Models\Store;
use Illuminate\Support\Str;

class AiShoppingService
{
    public function __construct(
        private readonly ShoppingIntentAgent $intentAgent,
        private readonly LookBuilderService $looks,
        private readonly ProductRecommendationService $recommendations,
        private readonly ProductStyleEnrichmentService $enrichment,
        private readonly StoreShoppingContextService $shoppingContext,
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
        $message = trim((string) ($input['message'] ?? ''));
        $chips = is_array($input['chips'] ?? null) ? $input['chips'] : [];
        $previousIntent = is_array($input['intent'] ?? null) ? $input['intent'] : null;
        $previousLook = is_array($input['look'] ?? null) ? $input['look'] : null;
        $shopper = $this->shoppingContext->forStore($store);

        $intent = $this->resolveIntent($store, $shopper, $message, $chips, $previousIntent);
        $recommendation = null;

        if (! ($intent['needs_clarification'] ?? false)) {
            $recommendation = $this->buildRecommendation($store, $shopper, $intent, $previousLook);
        }

        $reply = $this->composeReply($shopper, $intent, $recommendation, $message);

        return [
            'reply' => $reply,
            'intent' => $intent,
            'shopper' => $shopper,
            'look' => $recommendation,
            'recommendation' => $recommendation,
            'suggestions' => $this->suggestions($shopper, $intent, $recommendation),
            'catalog_enriched' => true,
        ];
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
    private function resolveIntent(Store $store, array $shopper, string $message, array $chips, ?array $previousIntent): array
    {
        $chipIntent = $this->intentFromChips($chips);
        $aiIntent = null;

        if ($message !== '' || $chips !== []) {
            $aiIntent = $this->intentAgent->execute([
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
        if (is_array($aiIntent)) {
            $merged = $this->mergeIntent($merged, $aiIntent);
            if (is_string($aiIntent['reply'] ?? null) && trim($aiIntent['reply']) !== '') {
                $merged['reply'] = trim($aiIntent['reply']);
            }
        }

        if (($merged['budget_max'] ?? null) === null && $message !== '') {
            $merged['budget_max'] = $this->parseBudgetFromMessage($message);
        }

        if (
            is_string($merged['product_query'] ?? null)
            && trim((string) $merged['product_query']) === ''
            && $message !== ''
        ) {
            $merged['product_query'] = $message;
        }

        $merged['needs_clarification'] = (bool) ($merged['needs_clarification'] ?? false);
        if ($this->shouldAskClarification($shopper, $merged, $message, $chips)) {
            $merged['needs_clarification'] = true;
            $merged['reply'] = $shopper['welcome_message'];
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @return array<string, mixed>|null
     */
    private function buildRecommendation(Store $store, array $shopper, array $intent, ?array $previousLook): ?array
    {
        if ($this->shouldBuildLook($shopper, $intent, $previousLook)) {
            return $this->looks->build($store, $intent, $previousLook);
        }

        return $this->recommendations->recommend($store, $intent, $previousLook);
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
    private function composeReply(array $shopper, array $intent, ?array $recommendation, string $message): string
    {
        if (is_string($intent['reply'] ?? null) && trim($intent['reply']) !== '') {
            $base = trim($intent['reply']);
        } else {
            $base = 'Here’s what I’d recommend.';
        }

        if ($recommendation === null) {
            $query = trim((string) ($intent['product_query'] ?? ''));
            if ($query !== '' && $this->intentWantsProductSearch($intent)) {
                return "I couldn’t find anything matching “{$query}” in {$shopper['store_name']}’s catalog. Try asking what this store sells, or browse by category.";
            }

            return $base;
        }

        $count = count($recommendation['items'] ?? []);
        $total = number_format((float) ($recommendation['total_price'] ?? 0), 0);
        $currency = $recommendation['currency'] ?? 'NGN';
        $isLook = ($recommendation['type'] ?? '') === 'look';

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
            $suffix = " I found {$count} option".($count === 1 ? '' : 's').' from this store';
            if ($count > 1) {
                $suffix .= " starting at {$currency} {$total}";
            } else {
                $suffix .= " at {$currency} {$total}";
            }
            $suffix .= '.';
            if (($recommendation['within_budget'] ?? true) === false) {
                $suffix .= ' These are slightly over budget — say “cheaper options”.';
            } else {
                $suffix .= ' Say “show alternatives” if you want more choices.';
            }
        }

        if (str_contains(Str::lower($base), 'recommend') || str_contains(Str::lower($base), 'found')) {
            return $base.$suffix;
        }

        return $base.$suffix;
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $recommendation
     * @return list<string>
     */
    private function suggestions(array $shopper, array $intent, ?array $recommendation): array
    {
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
