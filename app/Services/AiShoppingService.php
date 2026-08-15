<?php

namespace App\Services;

use App\Agents\ShoppingShopperAgent;
use App\Models\ShopperSession;
use App\Models\Store;
use Illuminate\Support\Str;

class AiShoppingService
{
    private const MAX_TOOL_ROUNDS = 4;

    private const LLM_HISTORY = 16;

    /** @var list<array{agent: string, phase: string, title: string, detail?: string}> */
    private array $thinking = [];

    public function __construct(
        private readonly ShoppingShopperAgent $shopperAgent,
        private readonly LookBuilderService $looks,
        private readonly ProductRecommendationService $recommendations,
        private readonly ProductStyleEnrichmentService $enrichment,
        private readonly StoreShoppingContextService $shoppingContext,
        private readonly AgentExecutionLogService $agentLogs,
        private readonly StoreCatalogSearchService $catalogSearch,
        private readonly ShopperIntentLogService $intentLog,
        private readonly ShopperSessionService $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function context(Store $store): array
    {
        return $this->shoppingContext->forStore($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionSnapshot(Store $store, ?string $sessionId): array
    {
        $shopper = $this->shoppingContext->forStore($store);
        $session = $this->sessions->findOrCreate($store, $sessionId, $shopper);

        return [
            'shopper' => $shopper,
            'session_id' => $session->client_key,
            'messages' => $session->transcript(),
            'recommendation' => $session->last_recommendation,
            'look' => $session->last_recommendation,
            'suggestions' => is_array($session->suggestions) && $session->suggestions !== []
                ? $session->suggestions
                : ($shopper['default_suggestions'] ?? []),
        ];
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
        $sessionId = is_string($input['session_id'] ?? null) ? trim($input['session_id']) : null;
        $shopper = $this->shoppingContext->forStore($store);

        return $this->agentLogs->using([
            'store_id' => $store->id,
            'merchant_id' => $store->merchant_id,
            'source' => 'ai_shopper',
        ], function () use ($store, $message, $chips, $sessionId, $shopper, $input) {
            $session = $this->sessions->findOrCreate($store, $sessionId, $shopper);
            $userText = $message !== '' ? $message : $this->chipsToMessage($chips);
            $this->sessions->append($session, 'user', $userText);

            $intent = $this->mergeIntent(
                $this->defaultIntent($shopper),
                is_array($session->last_intent) ? $session->last_intent : [],
            );
            $intent = $this->mergeIntent($intent, $this->intentFromChips($chips));
            if (is_array($input['intent'] ?? null) && $session->last_intent === null) {
                $intent = $this->mergeIntent($intent, $input['intent']);
            }
            if (($intent['budget_max'] ?? null) === null && $message !== '') {
                $intent['budget_max'] = $this->parseBudgetFromMessage($message);
            }

            $previousLook = is_array($session->last_recommendation)
                ? $session->last_recommendation
                : (is_array($input['look'] ?? null) ? $input['look'] : null);

            $this->think('ShoppingShopper', 'start', 'Understanding your request', $userText);

            $turn = $this->runShopperTurn($store, $shopper, $session, $userText, $intent, $previousLook);
            $reply = $turn['reply'];
            $recommendation = $turn['recommendation'];
            $intent = $turn['intent'];
            $action = $turn['action'];

            $suggestions = $this->suggestions($shopper, $intent, $recommendation, $action);
            $this->sessions->append($session, 'assistant', $reply);
            $this->sessions->persistTurn($session, $recommendation, $intent, $suggestions);

            $this->intentLog->record(
                $store,
                $userText,
                $chips,
                $intent,
                ['task_summary' => $turn['task_summary']],
                $recommendation,
                $session->client_key,
            );

            return [
                'reply' => $reply,
                'intent' => $intent,
                'interpretation' => [
                    'task_summary' => $turn['task_summary'],
                    'steps' => $turn['tools_used'],
                    'constraints' => [],
                ],
                'plan' => [
                    'action' => $action,
                    'intent_summary' => $turn['task_summary'],
                    'plan_steps' => [],
                ],
                'thinking' => $this->thinking,
                'shopper' => $shopper,
                'look' => $recommendation,
                'recommendation' => $recommendation,
                'suggestions' => $suggestions,
                'session_id' => $session->client_key,
                'messages' => $session->transcript(),
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
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @return array{
     *   reply: string,
     *   recommendation: array<string, mixed>|null,
     *   intent: array<string, mixed>,
     *   action: string,
     *   task_summary: string,
     *   tools_used: list<string>
     * }
     */
    private function runShopperTurn(
        Store $store,
        array $shopper,
        ShopperSession $session,
        string $userText,
        array $intent,
        ?array $previousLook,
    ): array {
        $recommendation = $previousLook;
        $action = 'search_products';
        $toolsUsed = [];
        $lastSearchIds = [];

        if (! $this->shopperAgent->available()) {
            $this->think('ShoppingShopper', 'fallback', 'Catalog search', 'Assistant unavailable, searching directly');
            $fallback = $this->fallbackSearch($store, $shopper, $userText, $intent);
            $intent['action'] = $fallback['recommendation'] ? 'search_products' : 'clarify';
            $intent['product_query'] = $userText;
            $intent['reply'] = $fallback['reply'];

            return [
                'reply' => $fallback['reply'],
                'recommendation' => $fallback['recommendation'] ?? $previousLook,
                'intent' => $intent,
                'action' => $intent['action'],
                'task_summary' => 'Catalog fallback without the assistant model.',
                'tools_used' => ['search_catalog'],
            ];
        }

        $messages = $this->llmMessages($shopper, $session, $previousLook);
        $tools = $this->shopperAgent->tools($shopper);

        for ($round = 1; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $result = $this->shopperAgent->complete($messages, $tools);
            if (! is_array($result)) {
                break;
            }

            $toolCalls = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
            if ($toolCalls === []) {
                $reply = is_string($result['content'] ?? null) ? trim($result['content']) : '';
                if ($reply !== '') {
                    $intent['reply'] = $reply;
                    $intent['action'] = $action;

                    return [
                        'reply' => $reply,
                        'recommendation' => $recommendation,
                        'intent' => $intent,
                        'action' => $action,
                        'task_summary' => $this->taskSummary($toolsUsed, $userText),
                        'tools_used' => $toolsUsed,
                    ];
                }
                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => is_string($result['content'] ?? null) ? $result['content'] : null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $toolCall) {
                if (! is_array($toolCall)) {
                    continue;
                }
                $callId = is_string($toolCall['id'] ?? null) ? $toolCall['id'] : (string) Str::uuid();
                $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
                $name = is_string($function['name'] ?? null) ? $function['name'] : '';
                $argumentsRaw = $function['arguments'] ?? '{}';
                $arguments = json_decode(is_string($argumentsRaw) ? $argumentsRaw : '{}', true);
                if (! is_array($arguments)) {
                    $arguments = [];
                }

                $toolsUsed[] = $name;
                $this->think('ShoppingShopper', 'tool', $this->toolTitle($name), $this->toolDetail($name, $arguments));

                $executed = $this->executeTool(
                    $store,
                    $shopper,
                    $name,
                    $arguments,
                    $intent,
                    $previousLook,
                    $lastSearchIds,
                );
                $intent = $executed['intent'];
                $action = $executed['action'];
                if (in_array($name, ['search_catalog', 'show_products', 'build_look', 'catalog_overview'], true)) {
                    $recommendation = $executed['recommendation'];
                    $previousLook = $recommendation;
                }
                if ($executed['search_ids'] !== []) {
                    $lastSearchIds = $executed['search_ids'];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => json_encode($executed['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $nudge = $this->shopperAgent->complete(array_merge($messages, [[
            'role' => 'user',
            'content' => 'Reply to the shopper now in natural language using only the catalog results above. Do not mention tools.',
        ]]), []);

        $reply = is_array($nudge) && is_string($nudge['content'] ?? null)
            ? trim($nudge['content'])
            : '';

        if ($reply === '') {
            $reply = $this->replyFromRecommendation($shopper, $recommendation, $userText);
        }

        $intent['reply'] = $reply;
        $intent['action'] = $action;

        return [
            'reply' => $reply,
            'recommendation' => $recommendation,
            'intent' => $intent,
            'action' => $action,
            'task_summary' => $this->taskSummary($toolsUsed, $userText),
            'tools_used' => $toolsUsed,
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>|null  $previousLook
     * @return list<array<string, mixed>>
     */
    private function llmMessages(array $shopper, ShopperSession $session, ?array $previousLook): array
    {
        $history = array_slice($session->transcript(), -self::LLM_HISTORY);
        $storeBlob = [
            'store_name' => $shopper['store_name'] ?? '',
            'mode' => $shopper['mode'] ?? 'general',
            'supports_looks' => (bool) ($shopper['supports_looks'] ?? false),
            'supports_try_on' => (bool) ($shopper['supports_try_on'] ?? false),
            'categories' => $shopper['categories'] ?? [],
            'currency' => 'NGN',
            'current_recommendation' => $this->compactRecommendation($previousLook),
        ];

        return [
            [
                'role' => 'system',
                'content' => $this->shopperAgent->systemPrompt()."\n\n## This store\n".json_encode(
                    $storeBlob,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ],
            ...$history,
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @param  list<string>  $lastSearchIds
     * @return array{
     *   result: array<string, mixed>,
     *   recommendation: array<string, mixed>|null,
     *   intent: array<string, mixed>,
     *   action: string,
     *   search_ids: list<string>
     * }
     */
    private function executeTool(
        Store $store,
        array $shopper,
        string $name,
        array $arguments,
        array $intent,
        ?array $previousLook,
        array $lastSearchIds,
    ): array {
        return match ($name) {
            'search_catalog' => $this->toolSearchCatalog($store, $arguments, $intent),
            'show_products' => $this->toolShowProducts($store, $arguments, $intent, $lastSearchIds),
            'build_look' => $this->toolBuildLook($store, $shopper, $arguments, $intent, $previousLook),
            'catalog_overview' => $this->toolCatalogOverview($store, $shopper, $intent),
            default => [
                'result' => ['ok' => false, 'error' => 'Unknown tool.'],
                'recommendation' => null,
                'intent' => $intent,
                'action' => $intent['action'] ?? 'search_products',
                'search_ids' => [],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function toolSearchCatalog(Store $store, array $arguments, array $intent): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        $budgetMax = isset($arguments['budget_max']) && is_numeric($arguments['budget_max'])
            ? (float) $arguments['budget_max']
            : ($intent['budget_max'] ?? null);
        $attributes = is_array($arguments['attributes'] ?? null) ? $arguments['attributes'] : [];

        $intent['product_query'] = $query !== '' ? $query : $intent['product_query'];
        $intent['budget_max'] = $budgetMax;
        $intent['attributes'] = $attributes !== [] ? $this->tags($attributes) : ($intent['attributes'] ?? []);
        $intent['action'] = 'search_products';
        $intent['needs_clarification'] = false;

        $searchParams = [
            'query' => $query !== '' ? $query : (string) ($intent['product_query'] ?? ''),
            'budget_max' => $budgetMax,
            'attributes' => $intent['attributes'],
            'limit' => 12,
        ];

        $results = $this->catalogSearch->search($store, $searchParams);
        $relaxedBudget = false;
        if ($results === [] && $budgetMax !== null) {
            $relaxed = $searchParams;
            $relaxed['budget_max'] = null;
            $results = $this->catalogSearch->search($store, $relaxed);
            $relaxedBudget = $results !== [];
        }

        $ids = array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? (string) ($row['id'] ?? '') : '',
            $results,
        )));

        $shown = $this->recommendations->fromProductIds(
            $store,
            array_slice($ids, 0, 3),
            $intent,
            $budgetMax === null || ! $relaxedBudget,
            $query,
        );
        if ($shown && $relaxedBudget) {
            $shown['within_budget'] = false;
        }

        $this->think(
            'Catalog',
            'complete',
            $results === [] ? 'No exact match' : 'Found matches',
            $results === [] ? 'No catalog hits for this query.' : count($results).' product(s)',
        );

        return [
            'result' => [
                'ok' => $results !== [],
                'query' => $searchParams['query'],
                'relaxed_budget' => $relaxedBudget,
                'count' => count($results),
                'products' => array_map(fn (array $row) => [
                    'id' => $row['id'] ?? null,
                    'name' => $row['name'] ?? null,
                    'price' => $row['price'] ?? null,
                    'currency' => $row['currency'] ?? 'NGN',
                    'category' => $row['category'] ?? null,
                    'within_budget' => $row['within_budget'] ?? true,
                ], $results),
                'shown_on_card' => $shown !== null,
            ],
            'recommendation' => $shown,
            'intent' => $intent,
            'action' => 'search_products',
            'search_ids' => $ids,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $intent
     * @param  list<string>  $lastSearchIds
     * @return array<string, mixed>
     */
    private function toolShowProducts(Store $store, array $arguments, array $intent, array $lastSearchIds): array
    {
        $requested = is_array($arguments['product_ids'] ?? null) ? $arguments['product_ids'] : [];
        $ids = array_values(array_filter(array_map(
            fn ($id) => is_string($id) ? $id : '',
            $requested,
        )));

        if ($lastSearchIds !== []) {
            $allowed = array_flip($lastSearchIds);
            $ids = array_values(array_filter($ids, fn (string $id) => isset($allowed[$id])));
        }

        $ids = array_slice($ids, 0, 3);
        $title = is_string($arguments['title'] ?? null) ? trim($arguments['title']) : '';
        $shown = $this->recommendations->fromProductIds(
            $store,
            $ids,
            $intent,
            true,
            $title !== '' ? $title : (string) ($intent['product_query'] ?? ''),
        );

        return [
            'result' => [
                'ok' => $shown !== null,
                'shown_count' => $shown ? count($shown['items'] ?? []) : 0,
                'products' => ($this->compactRecommendation($shown) ?? [])['items'] ?? [],
            ],
            'recommendation' => $shown,
            'intent' => $intent,
            'action' => 'search_products',
            'search_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $previousLook
     * @return array<string, mixed>
     */
    private function toolBuildLook(
        Store $store,
        array $shopper,
        array $arguments,
        array $intent,
        ?array $previousLook,
    ): array {
        if (! ($shopper['supports_looks'] ?? false)) {
            return [
                'result' => ['ok' => false, 'error' => 'Looks are not available for this store.'],
                'recommendation' => null,
                'intent' => $intent,
                'action' => 'search_products',
                'search_ids' => [],
            ];
        }

        foreach (['occasion', 'gender', 'revision'] as $key) {
            if (array_key_exists($key, $arguments) && $arguments[$key] !== null && $arguments[$key] !== '') {
                $intent[$key] = is_string($arguments[$key])
                    ? Str::lower(str_replace(' ', '_', trim($arguments[$key])))
                    : $arguments[$key];
            }
        }
        if (isset($arguments['styles']) && is_array($arguments['styles'])) {
            $intent['styles'] = $this->tags($arguments['styles']);
        }
        if (array_key_exists('budget_max', $arguments) && $arguments['budget_max'] !== null) {
            $intent['budget_max'] = is_numeric($arguments['budget_max']) ? (float) $arguments['budget_max'] : null;
        }
        $intent['action'] = 'build_look';
        $intent['needs_clarification'] = false;

        $look = $this->looks->build($store, $intent, $previousLook);
        $this->think(
            'Catalog',
            'complete',
            $look ? 'Look ready' : 'Could not build a look',
            $look ? count($look['items'] ?? []).' piece(s)' : 'No matching pieces',
        );

        return [
            'result' => [
                'ok' => $look !== null,
                'look' => $this->compactRecommendation($look),
            ],
            'recommendation' => $look,
            'intent' => $intent,
            'action' => 'build_look',
            'search_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function toolCatalogOverview(Store $store, array $shopper, array $intent): array
    {
        $intent['action'] = 'catalog_overview';
        $intent['product_query'] = null;
        $intent['needs_clarification'] = false;

        $overview = $this->recommendations->catalogOverview($store, $intent);
        $categoryNames = array_values(array_filter(array_map(
            fn ($category) => is_array($category) ? (string) ($category['name'] ?? '') : '',
            $shopper['categories'] ?? [],
        )));

        return [
            'result' => [
                'ok' => $overview !== null,
                'categories' => $categoryNames,
                'highlights' => ($this->compactRecommendation($overview) ?? [])['items'] ?? [],
            ],
            'recommendation' => $overview,
            'intent' => $intent,
            'action' => 'catalog_overview',
            'search_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @return array{reply: string, recommendation: array<string, mixed>|null}
     */
    private function fallbackSearch(Store $store, array $shopper, string $userText, array $intent): array
    {
        $query = trim((string) ($intent['product_query'] ?? '')) ?: $userText;
        $results = $this->catalogSearch->search($store, [
            'query' => $query,
            'budget_max' => $intent['budget_max'] ?? null,
            'attributes' => $intent['attributes'] ?? [],
            'limit' => 12,
        ]);
        $ids = array_slice(array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? (string) ($row['id'] ?? '') : '',
            $results,
        ))), 0, 3);
        $recommendation = $this->recommendations->fromProductIds($store, $ids, $intent, true, $query);

        return [
            'reply' => $this->replyFromRecommendation($shopper, $recommendation, $userText),
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Last-resort copy when the model returns no text. Built from real catalog rows only.
     *
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>|null  $recommendation
     */
    private function replyFromRecommendation(array $shopper, ?array $recommendation, string $userText): string
    {
        if (! is_array($recommendation) || empty($recommendation['items'])) {
            $storeName = (string) ($shopper['store_name'] ?? 'this store');

            return "I couldn’t find a match in {$storeName} for that. Tell me a product type, budget, or occasion and I’ll look again.";
        }

        $names = [];
        foreach ($recommendation['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = is_array($item['product'] ?? null) ? (string) ($item['product']['name'] ?? '') : '';
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return 'Here are the closest pieces I found in the catalog.';
        }

        $listed = $this->joinNames($names);
        $isLook = ($recommendation['type'] ?? '') === 'look';
        $total = number_format((float) ($recommendation['total_price'] ?? 0), 0);
        $currency = $recommendation['currency'] ?? 'NGN';

        if ($isLook) {
            return "I put this together from the catalog: {$listed}. The look comes to {$currency} {$total}.";
        }

        if (count($names) === 1) {
            return "From what we have, I’d go with {$listed} at {$currency} {$total}.";
        }

        return "Closest matches I found: {$listed}. They start at {$currency} {$total}.";
    }

    /**
     * @param  list<string>  $names
     */
    private function joinNames(array $names): string
    {
        $names = array_values($names);
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) === 2) {
            return $names[0].' and '.$names[1];
        }

        $last = array_pop($names);

        return implode(', ', $names).', and '.$last;
    }

    /**
     * @param  array<string, mixed>|null  $recommendation
     * @return array<string, mixed>|null
     */
    private function compactRecommendation(?array $recommendation): ?array
    {
        if (! is_array($recommendation) || empty($recommendation['items'])) {
            return null;
        }

        $items = [];
        foreach ($recommendation['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $product = is_array($item['product'] ?? null) ? $item['product'] : [];
            $items[] = [
                'role' => $item['role'] ?? null,
                'id' => $item['product_id'] ?? null,
                'name' => $product['name'] ?? null,
                'price' => $product['effective_price'] ?? $product['sale_price'] ?? $product['price'] ?? null,
            ];
        }

        return [
            'type' => $recommendation['type'] ?? 'products',
            'name' => $recommendation['name'] ?? null,
            'total_price' => $recommendation['total_price'] ?? null,
            'currency' => $recommendation['currency'] ?? 'NGN',
            'within_budget' => $recommendation['within_budget'] ?? true,
            'items' => $items,
        ];
    }

    /**
     * @param  list<string>  $toolsUsed
     */
    private function taskSummary(array $toolsUsed, string $userText): string
    {
        if ($toolsUsed === []) {
            return Str::limit($userText, 120, '');
        }

        return 'Used '.implode(', ', array_unique($toolsUsed));
    }

    private function toolTitle(string $name): string
    {
        return match ($name) {
            'search_catalog' => 'Searching the catalog',
            'show_products' => 'Choosing what to show',
            'build_look' => 'Building a look',
            'catalog_overview' => 'Reviewing what this store sells',
            default => $name,
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function toolDetail(string $name, array $arguments): string
    {
        return match ($name) {
            'search_catalog' => (string) ($arguments['query'] ?? ''),
            'show_products' => implode(', ', array_filter(array_map(
                fn ($id) => is_string($id) ? $id : '',
                is_array($arguments['product_ids'] ?? null) ? $arguments['product_ids'] : [],
            ))),
            'build_look' => trim(implode(' · ', array_filter([
                is_string($arguments['occasion'] ?? null) ? $arguments['occasion'] : null,
                is_string($arguments['revision'] ?? null) ? $arguments['revision'] : null,
            ]))),
            default => '',
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
                $label = trim((string) ($chip['label'] ?? ''));
                $parts[] = $label !== '' ? $label : trim(($chip['type'] ?? '').' '.($chip['value'] ?? ''));
            }
        }

        return trim(implode(', ', array_filter($parts)));
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
        foreach (['occasion', 'currency', 'gender', 'revision', 'reply', 'use_case', 'product_query', 'action'] as $key) {
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

        return $base;
    }

    /**
     * @param  array<string, mixed>  $shopper
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $recommendation
     * @return list<string>
     */
    private function suggestions(array $shopper, array $intent, ?array $recommendation, string $action): array
    {
        if ($action === 'catalog_overview' || $recommendation === null) {
            return array_slice($shopper['default_suggestions'] ?? [], 0, 3);
        }

        if (($recommendation['type'] ?? '') === 'look') {
            $suggestions = ['Make it cheaper', 'More elegant', 'Change the bag'];
            if ($shopper['supports_try_on'] ?? false) {
                $suggestions[] = 'Try this look on me';
            }

            return $suggestions;
        }

        $suggestions = [
            'Cheaper options',
            'Show alternatives',
            'Something better for work',
        ];
        if ($shopper['supports_try_on'] ?? false) {
            $suggestions[] = 'Try this look on me';
        }

        return $suggestions;
    }
}
