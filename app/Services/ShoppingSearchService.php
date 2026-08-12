<?php

namespace App\Services;

use App\Agents\ShoppingProductPickerAgent;
use App\Models\Store;
use App\Models\StoreProduct;

class ShoppingSearchService
{
    public function __construct(
        private readonly StoreCatalogSearchService $catalogSearch,
        private readonly ShoppingProductPickerAgent $picker,
        private readonly ProductRecommendationService $recommendations,
    ) {}

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $shopper
     * @param  callable(string, string, string, string): void  $think
     * @return array<string, mixed>|null
     */
    public function searchAndPick(
        Store $store,
        string $message,
        array $intent,
        array $shopper,
        callable $think,
    ): ?array {
        $query = trim((string) ($intent['product_query'] ?? ''));
        if ($query === '') {
            $query = trim($message);
        }

        $searchParams = [
            'query' => $query,
            'budget_max' => $intent['budget_max'] ?? null,
            'attributes' => $intent['attributes'] ?? [],
            'limit' => 20,
        ];

        $think('search_catalog', 'start', 'Searching catalog', $query);

        $results = $this->catalogSearch->search($store, $searchParams);
        $relaxedBudget = false;

        if ($results === [] && ($searchParams['budget_max'] ?? null) !== null) {
            $relaxedParams = $searchParams;
            $relaxedParams['budget_max'] = null;
            $results = $this->catalogSearch->search($store, $relaxedParams);
            $relaxedBudget = $results !== [];
        }

        $think(
            'search_catalog',
            'complete',
            count($results).' product(s) found',
            $results !== [] ? implode(', ', array_slice(array_column($results, 'name'), 0, 5)) : 'No text matches',
        );

        if ($results === []) {
            return null;
        }

        $think('ShoppingPicker', 'start', 'Choosing best matches', 'Reviewing search results');

        $pick = $this->picker->execute([
            'message' => $message,
            'intent' => $intent,
            'search_results' => $results,
            'store_context' => $shopper,
        ]);

        if (! is_array($pick) || empty($pick['selected_product_ids'])) {
            return null;
        }

        $think(
            'ShoppingPicker',
            'complete',
            'Picks ready',
            (string) ($pick['reasoning'] ?? ''),
        );

        $withinBudget = (bool) ($pick['within_budget'] ?? true);
        if ($relaxedBudget) {
            $withinBudget = false;
        }

        if (is_string($pick['reply'] ?? null) && trim($pick['reply']) !== '') {
            $intent['reply'] = trim($pick['reply']);
        }

        return $this->recommendations->fromProductIds(
            $store,
            $pick['selected_product_ids'],
            $intent,
            $withinBudget,
            $query,
        );
    }
}
