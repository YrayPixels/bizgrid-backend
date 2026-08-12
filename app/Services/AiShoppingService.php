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
        private readonly ProductStyleEnrichmentService $enrichment,
    ) {}

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

        $intent = $this->resolveIntent($store, $message, $chips, $previousIntent);
        $look = null;

        if (! ($intent['needs_clarification'] ?? false)) {
            $look = $this->looks->build($store, $intent, $previousLook);
        }

        $reply = $this->composeReply($intent, $look, $message);

        return [
            'reply' => $reply,
            'intent' => $intent,
            'look' => $look,
            'suggestions' => $this->suggestions($intent, $look),
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
     * @param  list<mixed>  $chips
     * @param  array<string, mixed>|null  $previousIntent
     * @return array<string, mixed>
     */
    private function resolveIntent(Store $store, string $message, array $chips, ?array $previousIntent): array
    {
        $chipIntent = $this->intentFromChips($chips);
        $aiIntent = null;

        if ($message !== '' || $chips !== []) {
            $aiIntent = $this->intentAgent->execute([
                'message' => $message !== '' ? $message : $this->chipsToMessage($chips),
                'chips' => $chips,
                'previous_intent' => $previousIntent,
                'store_currency' => 'NGN',
            ]);
        }

        $merged = $this->defaultIntent();
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

        $merged['needs_clarification'] = (bool) ($merged['needs_clarification'] ?? false);
        if (
            $merged['occasion'] === null
            && ($merged['styles'] ?? []) === []
            && $merged['budget_max'] === null
            && $message === ''
            && $chips === []
        ) {
            $merged['needs_clarification'] = true;
            $merged['reply'] = 'What are you dressing for? Share the occasion, vibe, or budget and I’ll build a look.';
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultIntent(): array
    {
        return [
            'reply' => 'Tell me what you’re shopping for and I’ll put a look together.',
            'occasion' => null,
            'styles' => [],
            'budget_max' => null,
            'currency' => 'NGN',
            'gender' => null,
            'categories' => [],
            'revision' => null,
            'needs_clarification' => false,
        ];
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

        // chips like "50-100k" → use upper bound
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
        foreach (['occasion', 'currency', 'gender', 'revision', 'reply'] as $key) {
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

        foreach (['styles', 'categories'] as $listKey) {
            if (isset($overlay[$listKey]) && is_array($overlay[$listKey]) && $overlay[$listKey] !== []) {
                $base[$listKey] = array_values(array_unique(array_filter(
                    array_map(fn ($v) => is_string($v) ? Str::lower(trim($v)) : '', $overlay[$listKey]),
                )));
            }
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $look
     */
    private function composeReply(array $intent, ?array $look, string $message): string
    {
        if (is_string($intent['reply'] ?? null) && trim($intent['reply']) !== '') {
            $base = trim($intent['reply']);
        } else {
            $base = 'Here’s what I’d recommend.';
        }

        if ($look === null) {
            return $base;
        }

        $count = count($look['items'] ?? []);
        $total = number_format((float) ($look['total_price'] ?? 0), 0);
        $currency = $look['currency'] ?? 'NGN';
        $suffix = " I put together a {$count}-piece look for {$currency} {$total}.";

        if (($look['within_budget'] ?? true) === false) {
            $suffix .= ' It’s a little over budget — say “make it cheaper” and I’ll adjust.';
        } else {
            $suffix .= ' Want to see it on you, or tweak the bag/shoes?';
        }

        // Avoid duplicating if the model already talked about the look.
        if (str_contains(Str::lower($base), 'look') || str_contains(Str::lower($base), 'recommend')) {
            return $base.$suffix;
        }

        return $base.$suffix;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $look
     * @return list<string>
     */
    private function suggestions(array $intent, ?array $look): array
    {
        if ($look === null) {
            return [
                'Wedding under ₦150k',
                'Elegant office look',
                'Something bold for a party',
            ];
        }

        return [
            'Make it cheaper',
            'More elegant',
            'Change the bag',
            'See it on me',
        ];
    }
}
