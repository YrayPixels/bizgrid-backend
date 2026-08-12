<?php

namespace App\Services;

use App\Models\ShopperIntentEvent;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShopperIntentLogService
{
    /**
     * @param  list<mixed>  $chips
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $interpretation
     * @param  array<string, mixed>|null  $recommendation
     */
    public function record(
        Store $store,
        string $message,
        array $chips,
        array $intent,
        ?array $interpretation,
        ?array $recommendation,
        ?string $sessionId = null,
    ): void {
        if (trim($message) === '' && $chips === []) {
            return;
        }

        try {
            $productIds = [];
            $productNames = [];
            if (is_array($recommendation['items'] ?? null)) {
                foreach ($recommendation['items'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (is_string($item['product_id'] ?? null)) {
                        $productIds[] = $item['product_id'];
                    }
                    $name = is_array($item['product'] ?? null)
                        ? (string) ($item['product']['name'] ?? '')
                        : '';
                    if ($name !== '') {
                        $productNames[] = $name;
                    }
                }
            }

            ShopperIntentEvent::create([
                'store_id' => $store->id,
                'merchant_id' => $store->merchant_id,
                'session_id' => $sessionId ? Str::limit($sessionId, 64, '') : null,
                'message' => Str::limit(trim($message), 2000, ''),
                'chips' => $chips !== [] ? $chips : null,
                'action' => is_string($intent['action'] ?? null) ? $intent['action'] : null,
                'product_query' => is_string($intent['product_query'] ?? null)
                    ? Str::limit(trim($intent['product_query']), 500, '')
                    : null,
                'categories' => is_array($intent['categories'] ?? null) ? $intent['categories'] : null,
                'attributes' => is_array($intent['attributes'] ?? null) ? $intent['attributes'] : null,
                'budget_max' => isset($intent['budget_max']) && is_numeric($intent['budget_max'])
                    ? (float) $intent['budget_max']
                    : null,
                'use_case' => is_string($intent['use_case'] ?? null) ? $intent['use_case'] : null,
                'occasion' => is_string($intent['occasion'] ?? null) ? $intent['occasion'] : null,
                'interpretation_summary' => is_string($interpretation['task_summary'] ?? null)
                    ? Str::limit(trim($interpretation['task_summary']), 1000, '')
                    : null,
                'had_recommendation' => $recommendation !== null && ! empty($recommendation['items']),
                'within_budget' => is_bool($recommendation['within_budget'] ?? null)
                    ? $recommendation['within_budget']
                    : null,
                'recommended_product_ids' => $productIds !== [] ? $productIds : null,
                'recommended_product_names' => $productNames !== [] ? $productNames : null,
                'needs_clarification' => (bool) ($intent['needs_clarification'] ?? false),
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log shopper intent event', [
                'store_id' => $store->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
