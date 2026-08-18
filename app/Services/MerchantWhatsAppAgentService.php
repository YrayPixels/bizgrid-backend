<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\MerchantWhatsAppAgent;
use App\Agents\VisionAgent;
use App\Models\Store;
use App\Models\StoreDiscount;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Models\WhatsAppMerchantMessage;
use App\Models\WhatsAppMerchantSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MerchantWhatsAppAgentService
{
    private const MAX_TOOL_ROUNDS = 4;

    private const LLM_HISTORY = 16;

    public const PERK_SUGGESTIONS = [
        'Free delivery in Lagos',
        'Same-day delivery',
        '30-day returns',
        '1-year warranty',
        'Authentic / original',
    ];

    public function __construct(
        private readonly MerchantWhatsAppAgent $agent,
        private readonly StoreProductService $products,
        private readonly OrderLifecycleService $orders,
        private readonly MediaStorageService $media,
        private readonly WhatsAppService $whatsapp,
        private readonly ApiCacheService $cache,
        private readonly StorefrontPublishService $publish,
        private readonly StoreDiscountService $discounts,
        private readonly StoreCategoryService $categories,
        private readonly StoreCustomerService $customers,
        private readonly AiChatClient $aiChat,
        private readonly PlatformAiConfigService $aiConfig,
        private readonly VisionAgent $vision,
        private readonly AbandonedRecoveryService $abandoned,
    ) {}

    public function available(): bool
    {
        return $this->agent->available();
    }

    /**
     * @return array{action: string, email: ?string, name: ?string, store_name: ?string, reply: string}|null
     */
    public function interpretOnboarding(WhatsAppMerchantSession $session, string $text, string $step): ?array
    {
        if (! $this->agent->available()) {
            return null;
        }

        $history = WhatsAppMerchantMessage::query()
            ->where('whatsapp_merchant_session_id', $session->id)
            ->orderByDesc('id')
            ->limit(self::LLM_HISTORY)
            ->get()
            ->reverse()
            ->values();

        if ($history->isNotEmpty() && $history->last()->direction === WhatsAppMerchantMessage::DIRECTION_INBOUND) {
            $history->pop();
        }

        $messages = [
            [
                'role' => 'system',
                'content' => $this->agent->onboardingSystemPrompt()."\n\n## Current step\n".json_encode([
                    'step' => $step,
                    'whatsapp_profile_name' => $session->context['profile_name'] ?? null,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
        ];

        foreach ($history as $message) {
            $body = trim((string) ($message->body ?? ''));
            if ($body === '') {
                $body = '['.$message->message_type.']';
            }
            $messages[] = [
                'role' => $message->direction === WhatsAppMerchantMessage::DIRECTION_INBOUND ? 'user' : 'assistant',
                'content' => $body,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $text !== '' ? $text : '[empty message]',
        ];

        return $this->agent->interpretOnboarding($messages);
    }

    /**
     * @return array{reply: string, tools_used: list<string>, link_email: ?string}
     */
    public function reply(
        WhatsAppMerchantSession $session,
        User $user,
        Store $store,
        string $text,
        string $type,
        ?string $mediaId,
    ): array {
        $toolsUsed = [];
        $linkEmail = null;
        $messages = $this->llmMessages($session, $user, $store, $text, $type, $mediaId);
        $tools = $this->agent->tools();

        for ($round = 1; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $result = $this->agent->complete($messages, $tools);
            if (! is_array($result)) {
                break;
            }

            $toolCalls = is_array($result['tool_calls'] ?? null) ? $result['tool_calls'] : [];
            if ($toolCalls === []) {
                $reply = is_string($result['content'] ?? null) ? trim($result['content']) : '';
                if ($reply !== '') {
                    $this->rememberTools($session, $toolsUsed);

                    return ['reply' => $reply, 'tools_used' => $toolsUsed, 'link_email' => $linkEmail];
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
                $executed = $this->executeTool($session, $user, $store, $name, $arguments, $type, $mediaId);
                if ($name === 'link_existing_account' && ($executed['ok'] ?? false) === true && is_string($executed['email'] ?? null) && $executed['email'] !== '') {
                    $linkEmail = $executed['email'];
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => json_encode($executed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }

            if ($linkEmail !== null) {
                $this->rememberTools($session, $toolsUsed);

                return ['reply' => '', 'tools_used' => $toolsUsed, 'link_email' => $linkEmail];
            }
        }

        $nudge = $this->agent->complete(array_merge($messages, [[
            'role' => 'user',
            'content' => 'Reply to the merchant now on WhatsApp using only the tool results above. Do not mention tools.',
        ]]), []);

        $reply = is_array($nudge) && is_string($nudge['content'] ?? null)
            ? trim($nudge['content'])
            : '';

        if ($reply === '' && $toolsUsed !== []) {
            $reply = 'Done. Tell me if you want to add another product, check orders, or open your dashboard.';
        }

        $this->rememberTools($session, $toolsUsed);

        return ['reply' => $reply, 'tools_used' => $toolsUsed, 'link_email' => $linkEmail];
    }

    /**
     * @param  list<string>  $toolsUsed
     */
    private function rememberTools(WhatsAppMerchantSession $session, array $toolsUsed): void
    {
        $session->mergeContext(['last_tools' => array_values(array_unique($toolsUsed))]);
        $session->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmMessages(
        WhatsAppMerchantSession $session,
        User $user,
        Store $store,
        string $text,
        string $type,
        ?string $mediaId,
    ): array {
        $records = WhatsAppMerchantMessage::query()
            ->where('whatsapp_merchant_session_id', $session->id)
            ->orderByDesc('id')
            ->limit(self::LLM_HISTORY)
            ->get()
            ->reverse()
            ->values();

        if ($records->isNotEmpty() && $records->last()->direction === WhatsAppMerchantMessage::DIRECTION_INBOUND) {
            $records->pop();
        }

        $history = $records
            ->map(function (WhatsAppMerchantMessage $message): array {
                $body = trim((string) ($message->body ?? ''));
                if ($body === '') {
                    $body = '['.$message->message_type.']';
                }

                return [
                    'role' => $message->direction === WhatsAppMerchantMessage::DIRECTION_INBOUND ? 'user' : 'assistant',
                    'content' => $body,
                ];
            })
            ->all();

        $store = $store->fresh() ?? $store;
        $draft = is_array($session->context['product_draft'] ?? null) ? $session->context['product_draft'] : [];
        $storeBlob = [
            'store_name' => $store->name,
            'store_url' => $this->storefrontUrl($store),
            'store_status' => (string) ($store->status ?? 'draft'),
            'is_published' => $this->publish->isPublished($store),
            'product_count' => (int) ($store->products_count ?? 0),
            'merchant_name' => $user->name,
            'pending_product_draft' => $draft === [] ? null : $draft,
            'focused_product' => is_array($session->context['last_product'] ?? null)
                ? $session->context['last_product']
                : null,
            'focused_order' => is_array($session->context['last_order'] ?? null)
                ? $session->context['last_order']
                : null,
            'this_turn' => [
                'type' => $type,
                'has_photo' => $type === 'image' && filled($mediaId),
            ],
        ];

        $turn = $text;
        if ($type === 'image' && $mediaId) {
            $turn = trim('Merchant sent a product photo.'.($text !== '' ? ' Caption: '.$text : ''));
        } elseif (str_starts_with(strtolower($text), 'perk:')) {
            $perk = trim(substr($text, 5));
            $turn = 'Merchant tapped a perk suggestion: '.$perk.'. Call set_product_perks with perks: ["'.$perk.'"] on the focused product (mode add).';
        } elseif (in_array(strtolower($text), ['write description', 'rewrite copy'], true)) {
            $turn = 'Merchant tapped Write description. Call generate_product_description for the focused product.';
        } elseif (in_array(strtolower($text), ['add perks', 'edit perks'], true)) {
            $turn = 'Merchant tapped Add perks. Call set_product_perks with an empty perks list so they can pick suggestions.';
        } elseif (in_array(strtolower($text), ['hide product'], true)) {
            $turn = 'Merchant tapped Hide product. Call update_product with status archived on the focused product.';
        } elseif (in_array(strtolower($text), ['set stock'], true)) {
            $turn = 'Merchant tapped Set stock. Ask how many units, then call update_product with stock_quantity.';
        } elseif (in_array(strtolower($text), ['change price'], true)) {
            $turn = 'Merchant tapped Change price. Ask for the new NGN price, then call update_product.';
        } elseif (in_array(strtolower($text), ['change photo', 'add photo'], true)) {
            $turn = 'Merchant tapped Change photo. Ask them to send a product picture, then call update_product.';
        } elseif (in_array(strtolower($text), ['ship order', 'ship'], true) || preg_match('/^ship\s+\d+$/i', $text) === 1) {
            $turn = 'Merchant tapped Ship. Call update_order_status with status shipped on the focused order (or the list number).';
        } elseif (in_array(strtolower($text), ['call customer'], true)) {
            $turn = 'Merchant tapped Call customer. Call contact_customer for the focused order.';
        } elseif (in_array(strtolower($text), ['mark paid'], true)) {
            $turn = 'Merchant tapped Mark paid. Call mark_order_paid for the focused order.';
        } elseif (in_array(strtolower($text), ['cancel order'], true) || preg_match('/^cancel\s+\d+$/i', $text) === 1) {
            $turn = 'Merchant tapped Cancel order. Call update_order_status with status cancelled on the focused order.';
        } elseif (preg_match('/^order\s+(\d+)$/i', $text) === 1) {
            $turn = 'Merchant tapped an order in the list. Call get_order with target set to the number.';
        } elseif (preg_match('/^remind\s+(\d+)$/i', $text) === 1) {
            $turn = 'Merchant tapped an abandoned-cart reminder. Call send_abandoned_reminder with that list number.';
        } elseif (str_starts_with(strtolower($text), 'restock')) {
            $turn = 'Merchant tapped Restock. Ask how many units if missing, then call update_product with stock_quantity on the focused or named product.';
        } elseif (in_array(strtolower($text), ['put on sale'], true)) {
            $turn = 'Merchant tapped Put on sale. Ask for a sale price or percent off if missing, then call put_on_sale on the focused product.';
        } elseif (in_array(strtolower($text), ['yes add it', 'yes, add it'], true)) {
            $turn = 'Merchant confirmed the photo suggestion. Call add_product using pending_product_draft.suggestion name and price.';
        } elseif (in_array(strtolower($text), ['change name'], true)) {
            $turn = 'Merchant wants a different name than the photo suggestion. Ask for the name and price, then call add_product.';
        } elseif (in_array(strtolower($text), ['copy link', 'copy store link'], true)) {
            $turn = 'Merchant tapped Copy link. Reply with only the store_url so they can copy it. Do not call tools.';
        } elseif (in_array(strtolower($text), ['share to status'], true)) {
            $turn = 'Merchant tapped Share to status. Tell them to tap and hold the store card, choose Forward, then Status. Do not call tools.';
        } elseif (in_array(strtolower($text), ['abandoned carts', 'abandoned'], true)) {
            $turn = 'Merchant tapped Abandoned carts. Call list_abandoned_carts.';
        } elseif (in_array(strtolower($text), ['brief', 'daily brief'], true)) {
            $turn = 'Merchant tapped Daily brief. Call daily_brief.';
        } else {
            $lastInbound = WhatsAppMerchantMessage::query()
                ->where('whatsapp_merchant_session_id', $session->id)
                ->where('direction', WhatsAppMerchantMessage::DIRECTION_INBOUND)
                ->latest('id')
                ->first();
            if ($lastInbound && ($lastInbound->metadata['source'] ?? null) === 'voice_note' && $text !== '') {
                $turn = 'Transcribed voice note: '.$text;
            }
        }

        $history[] = [
            'role' => 'user',
            'content' => $turn !== '' ? $turn : '[empty message]',
        ];

        return [
            [
                'role' => 'system',
                'content' => $this->agent->systemPrompt()."\n\n## This merchant\n".json_encode(
                    $storeBlob,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ],
            ...array_slice($history, -self::LLM_HISTORY),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeTool(
        WhatsAppMerchantSession $session,
        User $user,
        Store $store,
        string $name,
        array $arguments,
        string $type,
        ?string $mediaId,
    ): array {
        try {
            return match ($name) {
                'add_product' => $this->toolAddProduct($session, $store, $arguments, $type, $mediaId),
                'update_product' => $this->toolUpdateProduct($session, $store, $arguments, $type, $mediaId),
                'get_product' => $this->toolGetProduct($session, $store, $arguments),
                'generate_product_description' => $this->toolGenerateProductDescription($session, $store, $arguments),
                'set_product_perks' => $this->toolSetProductPerks($session, $store, $arguments),
                'list_products' => $this->toolListProducts($store, $arguments),
                'list_orders' => $this->toolListOrders($session, $store),
                'get_order' => $this->toolGetOrder($session, $store, $arguments),
                'update_order_status' => $this->toolUpdateOrderStatus($session, $store, $arguments),
                'mark_order_paid' => $this->toolMarkOrderPaid($session, $store, $arguments),
                'contact_customer' => $this->toolContactCustomer($session, $store, $arguments),
                'publish_store' => $this->publishStorefront($store),
                'get_storefront_link' => $this->toolShareStore($session, $store),
                'open_dashboard' => [
                    'ok' => true,
                    'url' => $this->dashboardUrl($user),
                    'expires_minutes' => 15,
                ],
                'link_existing_account' => $this->toolLinkExistingAccount($arguments),
                'show_help' => [
                    'ok' => true,
                    'menu' => [
                        'add or update a product (photo, name, price, description, perks, stock, sale)',
                        'orders: ship, call customer, mark paid, cancel',
                        'abandoned carts and reminders',
                        'discounts: create, pause, end',
                        'daily brief, payouts, store stats',
                        'share the store link or open the full dashboard',
                    ],
                ],
                'get_store_summary' => $this->toolGetStoreSummary($store),
                'update_store_profile' => $this->toolUpdateStoreProfile($store, $arguments),
                'list_customers' => $this->toolListCustomers($store, $arguments),
                'list_discounts' => $this->toolListDiscounts($store),
                'create_discount' => $this->toolCreateDiscount($store, $arguments),
                'update_discount' => $this->toolUpdateDiscount($store, $arguments),
                'put_on_sale' => $this->toolPutOnSale($session, $store, $arguments),
                'list_categories' => $this->toolListCategories($store),
                'create_category' => $this->toolCreateCategory($store, $arguments),
                'list_abandoned_carts' => $this->toolListAbandonedCarts($session, $store),
                'send_abandoned_reminder' => $this->toolSendAbandonedReminder($session, $store, $arguments),
                'get_payouts' => $this->toolGetPayouts($store),
                'daily_brief' => $this->buildDailyBrief($store),
                default => ['ok' => false, 'error' => 'Unknown tool.'],
            };
        } catch (\Throwable $e) {
            Log::warning('Merchant WhatsApp tool failed.', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolAddProduct(
        WhatsAppMerchantSession $session,
        Store $store,
        array $arguments,
        string $type,
        ?string $mediaId,
    ): array {
        $draft = is_array($session->context['product_draft'] ?? null) ? $session->context['product_draft'] : [];

        if ($type === 'image' && $mediaId) {
            $stored = $this->storeProductImageWithBytes($store, $mediaId);
            $draft['image_url'] = $stored['url'];
            if (empty($draft['name']) && ! isset($arguments['name'])) {
                $identified = $this->identifyProductPhoto($store, $stored['contents'], $stored['mime']);
                if ($identified !== null) {
                    $draft['suggestion'] = $identified;
                    if (empty($draft['name']) && filled($identified['name'] ?? null)) {
                        // Keep as suggestion only — merchant must confirm before create.
                    }
                }
            }
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name !== '') {
            $draft['name'] = mb_substr($name, 0, 120);
        }

        if (array_key_exists('price', $arguments) && $arguments['price'] !== null && is_numeric($arguments['price'])) {
            $draft['price'] = round((float) $arguments['price'], 2);
        }

        $description = trim((string) ($arguments['description'] ?? ''));
        if ($description !== '') {
            $draft['description'] = mb_substr($description, 0, 2000);
        }

        if (array_key_exists('stock_quantity', $arguments) && $arguments['stock_quantity'] !== null && is_numeric($arguments['stock_quantity'])) {
            $draft['stock_quantity'] = max(0, (int) $arguments['stock_quantity']);
        }

        $category = trim((string) ($arguments['category'] ?? ''));
        if ($category !== '') {
            $draft['category'] = mb_substr($category, 0, 80);
        }

        $perks = $this->parsePerks($arguments['perks'] ?? null);
        if ($perks !== null && $perks !== []) {
            $draft['perks'] = $perks;
        }

        $session->mergeContext(['product_draft' => $draft]);
        $session->state = WhatsAppMerchantSession::STATE_IDLE;
        $session->save();

        $missing = [];
        if (empty($draft['name'])) {
            $missing[] = 'name';
        }
        if (! array_key_exists('price', $draft) || $draft['price'] === null) {
            $missing[] = 'price';
        }

        if ($missing !== []) {
            return [
                'ok' => true,
                'created' => false,
                'draft' => $draft,
                'missing' => $missing,
                'has_photo' => filled($draft['image_url'] ?? null),
                'suggestion' => $draft['suggestion'] ?? null,
                'ask' => isset($draft['suggestion']['name'])
                    ? 'This looks like '.$draft['suggestion']['name']
                        .(isset($draft['suggestion']['price']) ? ' around NGN '.number_format((float) $draft['suggestion']['price'], 0) : '')
                        .'. Confirm the name and price to add it.'
                    : null,
            ];
        }

        $product = $this->products->createForStore($store, [
            'name' => $draft['name'],
            'price' => $draft['price'],
            'description' => $draft['description'] ?? null,
            'currency' => 'NGN',
            'image_url' => $draft['image_url'] ?? null,
            'status' => 'active',
            'stock_quantity' => $draft['stock_quantity'] ?? null,
            'category' => $draft['category'] ?? null,
            'perks' => $draft['perks'] ?? null,
        ]);

        $this->cache->forgetStore($store);

        $context = $session->context ?? [];
        unset($context['product_draft']);
        $session->context = $context;
        $session->save();

        $price = number_format((float) $product->price, 0);
        $publishResult = $this->publishStorefront($store->fresh() ?? $store);
        $preview = $this->rememberProduct($session, $store, $product);

        return [
            'ok' => true,
            'created' => true,
            'product' => array_merge($preview, [
                'price_label' => 'NGN '.$price,
            ]),
            'next_steps' => $this->productNextStepHints($product),
            'store' => $publishResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolUpdateProduct(
        WhatsAppMerchantSession $session,
        Store $store,
        array $arguments,
        string $type,
        ?string $mediaId,
    ): array {
        $product = $this->resolveFocusedProduct($session, $store, $arguments);
        if (! $product) {
            return ['ok' => false, 'error' => 'Tell me which product to update.'];
        }

        $patch = [];
        if (array_key_exists('name', $arguments)) {
            $newName = trim((string) $arguments['name']);
            if ($newName !== '' && strcasecmp($newName, (string) $product->name) !== 0) {
                $patch['name'] = mb_substr($newName, 0, 120);
            }
        }

        if (array_key_exists('price', $arguments) && $arguments['price'] !== null && is_numeric($arguments['price'])) {
            $patch['price'] = round((float) $arguments['price'], 2);
        }

        if (array_key_exists('sale_price', $arguments)) {
            $patch['sale_price'] = $arguments['sale_price'] !== null && is_numeric($arguments['sale_price'])
                ? round((float) $arguments['sale_price'], 2)
                : null;
        }

        $description = trim((string) ($arguments['description'] ?? ''));
        if ($description !== '') {
            $patch['description'] = mb_substr($description, 0, 2000);
        }

        if (array_key_exists('stock_quantity', $arguments) && $arguments['stock_quantity'] !== null && is_numeric($arguments['stock_quantity'])) {
            $patch['stock_quantity'] = max(0, (int) $arguments['stock_quantity']);
        }

        $status = strtolower(trim((string) ($arguments['status'] ?? '')));
        if (in_array($status, ['active', 'draft', 'archived'], true)) {
            $patch['status'] = $status;
        }

        $category = trim((string) ($arguments['category'] ?? ''));
        if ($category !== '') {
            $patch['category'] = mb_substr($category, 0, 80);
        }

        $sku = trim((string) ($arguments['sku'] ?? ''));
        if ($sku !== '') {
            $patch['sku'] = mb_substr($sku, 0, 80);
        }

        if ($type === 'image' && $mediaId) {
            $patch['image_url'] = $this->storeProductImage($store, $mediaId);
        }

        if ($patch === []) {
            return [
                'ok' => false,
                'error' => 'Nothing to update. Send a photo or new name/price/description/stock.',
                'product' => $this->productPreview($store, $product),
            ];
        }

        $product = $this->products->updateProduct($product, $patch);
        $this->cache->forgetStore($store);
        $publishResult = $this->publishStorefront($store->fresh() ?? $store);
        $preview = $this->rememberProduct($session, $store, $product);

        return [
            'ok' => true,
            'updated' => true,
            'product' => $preview,
            'changed' => array_keys($patch),
            'next_steps' => $this->productNextStepHints($product),
            'store' => $publishResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolGetProduct(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $product = $this->resolveFocusedProduct($session, $store, $arguments);
        if (! $product) {
            return ['ok' => false, 'error' => 'Product not found. Call list_products.'];
        }

        $preview = $this->rememberProduct($session, $store, $product);

        return [
            'ok' => true,
            'product' => array_merge($preview, [
                'description' => (string) ($product->description ?? ''),
                'perks' => is_array($product->perks) ? $product->perks : [],
                'stock_quantity' => $product->stock_quantity,
                'category' => $product->category,
                'sku' => $product->sku,
            ]),
            'next_steps' => $this->productNextStepHints($product),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolGenerateProductDescription(
        WhatsAppMerchantSession $session,
        Store $store,
        array $arguments,
    ): array {
        $product = $this->resolveFocusedProduct($session, $store, $arguments);
        if (! $product) {
            return ['ok' => false, 'error' => 'Which product should I describe? Tell me the name.'];
        }

        $instruction = trim((string) ($arguments['instruction'] ?? ''));
        $description = $this->draftProductDescription($store, $product, $instruction);
        $product = $this->products->updateProduct($product, ['description' => $description]);
        $this->cache->forgetStore($store);
        $this->publishStorefront($store->fresh() ?? $store);
        $preview = $this->rememberProduct($session, $store, $product);

        return [
            'ok' => true,
            'updated' => true,
            'product' => array_merge($preview, ['description' => $description]),
            'next_steps' => $this->productNextStepHints($product),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolSetProductPerks(
        WhatsAppMerchantSession $session,
        Store $store,
        array $arguments,
    ): array {
        $product = $this->resolveFocusedProduct($session, $store, $arguments);
        if (! $product) {
            return ['ok' => false, 'error' => 'Which product should get perks? Tell me the name.'];
        }

        $incoming = $this->parsePerks($arguments['perks'] ?? null) ?? [];
        if ($incoming === []) {
            $session->mergeContext(['awaiting_perks' => true]);
            $this->rememberProduct($session, $store, $product);

            return [
                'ok' => true,
                'updated' => false,
                'product' => $this->productPreview($store, $product),
                'suggested_perks' => self::PERK_SUGGESTIONS,
                'hint' => 'Ask which perks to turn on, then call set_product_perks with those strings.',
            ];
        }

        $mode = strtolower(trim((string) ($arguments['mode'] ?? 'add')));
        $existing = is_array($product->perks) ? $product->perks : [];
        $perks = $mode === 'replace'
            ? $incoming
            : array_values(array_unique([...$existing, ...$incoming]));
        $perks = array_slice($perks, 0, 12);

        $product = $this->products->updateProduct($product, ['perks' => $perks]);
        $this->cache->forgetStore($store);
        $this->publishStorefront($store->fresh() ?? $store);
        $session->mergeContext(['awaiting_perks' => false]);
        $preview = $this->rememberProduct($session, $store, $product);

        return [
            'ok' => true,
            'updated' => true,
            'product' => array_merge($preview, ['perks' => $perks]),
            'next_steps' => $this->productNextStepHints($product),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolListProducts(Store $store, array $arguments): array
    {
        $search = strtolower(trim((string) ($arguments['search'] ?? '')));
        $status = strtolower(trim((string) ($arguments['status'] ?? 'all')));
        $items = collect($this->products->listForStore($store, false))
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn (array $row): bool => str_contains(strtolower((string) ($row['name'] ?? '')), $search),
            ))
            ->when(in_array($status, ['active', 'draft', 'archived'], true), fn ($rows) => $rows->filter(
                fn (array $row): bool => ($row['status'] ?? 'active') === $status,
            ))
            ->take(12)
            ->map(fn (array $row): array => [
                'name' => $row['name'] ?? '',
                'price' => $row['price'] ?? null,
                'status' => $row['status'] ?? null,
                'slug' => $row['slug'] ?? null,
                'has_image' => filled($row['image_url'] ?? null),
                'has_description' => trim((string) ($row['description'] ?? '')) !== '',
                'perks' => is_array($row['perks'] ?? null) ? $row['perks'] : [],
                'stock_quantity' => $row['stock_quantity'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'ok' => true,
            'count' => count($items),
            'products' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListOrders(WhatsAppMerchantSession $session, Store $store): array
    {
        $orders = StoreOrder::query()
            ->where('store_id', $store->id)
            ->latest('placed_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $index = [];
        $items = [];
        foreach ($orders->values() as $i => $order) {
            $n = $i + 1;
            $index[(string) $n] = $order->id;
            $items[] = [
                'index' => $n,
                'order_number' => $order->order_number,
                'customer' => $order->customer_name ?: 'Customer',
                'phone' => $order->customer_phone,
                'total' => (float) $order->total_amount,
                'status' => (string) $order->status,
                'payment_status' => (string) $order->payment_status,
            ];
        }

        $session->mergeContext(['order_index' => $index]);
        $session->save();

        return [
            'ok' => true,
            'count' => count($items),
            'orders' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolUpdateOrderStatus(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $target = trim((string) ($arguments['target'] ?? ''));
        $status = strtolower(trim((string) ($arguments['status'] ?? 'shipped')));
        $order = $this->resolveFocusedOrder($session, $store, $target);
        if (! $order) {
            return ['ok' => false, 'error' => 'Order not found. Call list_orders first.'];
        }

        if (! in_array($status, ['shipped', 'processing', 'delivered', 'cancelled'], true)) {
            $status = 'shipped';
        }

        $order = $this->orders->updateStatus($order, ['status' => $status]);
        $this->cache->forgetStore($store);
        $preview = $this->rememberOrder($session, $store, $order);

        return [
            'ok' => true,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'order' => $preview,
        ];
    }

    private function resolveOrder(WhatsAppMerchantSession $session, Store $store, string $target): ?StoreOrder
    {
        $target = trim($target);
        $index = $session->context['order_index'] ?? [];
        if (isset($index[$target])) {
            return StoreOrder::query()
                ->where('store_id', $store->id)
                ->find($index[$target]);
        }

        return StoreOrder::query()
            ->where('store_id', $store->id)
            ->where(function ($query) use ($target): void {
                $query->where('order_number', $target)
                    ->orWhere('order_number', 'like', '%'.$target.'%');
                if (ctype_digit($target)) {
                    $query->orWhere('id', (int) $target);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolGetOrder(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $order = $this->resolveFocusedOrder($session, $store, trim((string) ($arguments['target'] ?? '')));
        if (! $order) {
            return ['ok' => false, 'error' => 'Order not found. Call list_orders first.'];
        }

        return [
            'ok' => true,
            'order' => $this->rememberOrder($session, $store, $order),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolMarkOrderPaid(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $order = $this->resolveFocusedOrder($session, $store, trim((string) ($arguments['target'] ?? '')));
        if (! $order) {
            return ['ok' => false, 'error' => 'Order not found. Call list_orders first.'];
        }

        $order = $this->orders->markPaid($order, 'bank_transfer', false);
        $this->cache->forgetStore($store);

        return [
            'ok' => true,
            'order_number' => $order->order_number,
            'payment_status' => $order->payment_status,
            'settlement_status' => $order->settlement_status,
            'order' => $this->rememberOrder($session, $store, $order),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolContactCustomer(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $order = $this->resolveFocusedOrder($session, $store, trim((string) ($arguments['target'] ?? '')));
        if (! $order) {
            return ['ok' => false, 'error' => 'Order not found. Call list_orders first.'];
        }

        $phone = trim((string) ($order->customer_phone ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'error' => 'This order has no customer phone number.'];
        }

        $this->rememberOrder($session, $store, $order);
        $session->mergeContext([
            'last_contact' => [
                'name' => $order->customer_name ?: 'Customer',
                'phone' => $phone,
            ],
            'show_contact' => true,
            'show_order_card' => false,
        ]);
        $session->save();

        return [
            'ok' => true,
            'customer' => $order->customer_name ?: 'Customer',
            'phone' => $phone,
            'hint' => 'A contact card will be sent so they can tap Call.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolPutOnSale(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $product = $this->resolveFocusedProduct($session, $store, $arguments);
        if (! $product) {
            return ['ok' => false, 'error' => 'Which product should go on sale?'];
        }

        if (($arguments['clear'] ?? false) === true) {
            $product = $this->products->updateProduct($product, ['sale_price' => null]);
            $this->cache->forgetStore($store);
            $this->publishStorefront($store->fresh() ?? $store);

            return ['ok' => true, 'cleared' => true, 'product' => $this->rememberProduct($session, $store, $product)];
        }

        $regular = (float) $product->price;
        $sale = null;
        if (is_numeric($arguments['sale_price'] ?? null)) {
            $sale = round((float) $arguments['sale_price'], 2);
        } elseif (is_numeric($arguments['percent'] ?? null)) {
            $percent = max(1, min(90, (float) $arguments['percent']));
            $sale = round($regular * (1 - ($percent / 100)), 2);
        }

        if ($sale === null || $sale <= 0 || $sale >= $regular) {
            return ['ok' => false, 'error' => 'Give a sale price below NGN '.number_format($regular, 0).' or a percent off.'];
        }

        $product = $this->products->updateProduct($product, ['sale_price' => $sale]);
        $this->cache->forgetStore($store);
        $this->publishStorefront($store->fresh() ?? $store);

        return [
            'ok' => true,
            'product' => array_merge($this->rememberProduct($session, $store, $product), [
                'regular_price_label' => 'NGN '.number_format($regular, 0),
                'sale_price_label' => 'NGN '.number_format($sale, 0),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolUpdateDiscount(Store $store, array $arguments): array
    {
        $search = strtolower(trim((string) ($arguments['search'] ?? '')));
        $status = strtolower(trim((string) ($arguments['status'] ?? '')));
        if ($search === '' || ! in_array($status, ['active', 'draft', 'archived'], true)) {
            return ['ok' => false, 'error' => 'Tell me the discount name and whether to pause, resume, or end it.'];
        }

        $discount = StoreDiscount::query()
            ->where('store_id', $store->id)
            ->orderByDesc('updated_at')
            ->get()
            ->first(function (StoreDiscount $row) use ($search): bool {
                $name = strtolower((string) $row->name);

                return $name === $search || str_contains($name, $search);
            });

        if (! $discount) {
            return ['ok' => false, 'error' => 'Discount not found. Call list_discounts.'];
        }

        $discount = $this->discounts->updateDiscount($discount, ['status' => $status]);
        $this->cache->forgetStore($store);

        return [
            'ok' => true,
            'discount' => $this->discounts->format($discount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolShareStore(WhatsAppMerchantSession $session, Store $store): array
    {
        $preview = $this->storePreview($store);
        $session->mergeContext([
            'last_store_card' => $preview,
            'show_store_card' => true,
        ]);
        $session->save();

        return ['ok' => true, ...$preview];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListAbandonedCarts(WhatsAppMerchantSession $session, Store $store): array
    {
        $listed = $this->abandoned->listAbandoned($store, 8, 1);
        $index = [];
        $items = [];
        foreach (array_values($listed['items']) as $i => $row) {
            $n = $i + 1;
            $index[(string) $n] = [
                'source_type' => $row['source_type'] ?? 'cart',
                'source_id' => (int) ($row['source_id'] ?? 0),
                'customer' => $row['customer_name'] ?? 'Customer',
            ];
            $items[] = [
                'index' => $n,
                'customer' => $row['customer_name'] ?: ($row['customer_phone'] ?: 'Customer'),
                'total_label' => 'NGN '.number_format((float) ($row['total_amount'] ?? 0), 0),
                'kind' => $row['kind'] ?? $row['source_type'] ?? 'cart',
            ];
        }

        $session->mergeContext(['abandoned_index' => $index]);
        $session->save();

        return [
            'ok' => true,
            'count' => count($items),
            'summary' => $listed['summary'],
            'carts' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolSendAbandonedReminder(WhatsAppMerchantSession $session, Store $store, array $arguments): array
    {
        $target = trim((string) ($arguments['target'] ?? ''));
        $index = $session->context['abandoned_index'] ?? [];
        $entry = is_array($index[$target] ?? null) ? $index[$target] : null;

        if ($entry === null && $target !== '') {
            $needle = strtolower($target);
            foreach ($index as $row) {
                if (is_array($row) && str_contains(strtolower((string) ($row['customer'] ?? '')), $needle)) {
                    $entry = $row;
                    break;
                }
            }
        }

        if (! is_array($entry) || (int) ($entry['source_id'] ?? 0) < 1) {
            return ['ok' => false, 'error' => 'Call list_abandoned_carts first, then pick a number.'];
        }

        $sourceType = (string) $entry['source_type'];
        $sourceId = (int) $entry['source_id'];
        $draft = $this->abandoned->draftRecoveryMessage($store, $sourceType, $sourceId, 'whatsapp');
        $result = $this->abandoned->sendRecoveryMessage(
            $store,
            $sourceType,
            $sourceId,
            'whatsapp',
            $draft['message'],
            $draft['subject'],
        );

        return [
            'ok' => true,
            'customer' => $entry['customer'] ?? 'Customer',
            'mode' => $result['mode'] ?? null,
            'whatsapp_url' => $result['whatsapp_url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolGetPayouts(Store $store): array
    {
        $pending = (float) StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->where('settlement_status', 'pending_settlement')
            ->sum('total_amount');
        $paidToday = (float) StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->whereDate('paid_at', now()->toDateString())
            ->sum('total_amount');

        return [
            'ok' => true,
            'pending_label' => 'NGN '.number_format($pending, 0),
            'received_today_label' => 'NGN '.number_format($paidToday, 0),
            'payout_account' => $store->payout_bank_name
                ? trim($store->payout_bank_name.' '.substr((string) $store->payout_account_number, -4))
                : null,
            'hint' => $pending > 0
                ? 'Payout pending. Paystack usually settles to the linked bank in 1–2 working days.'
                : 'Nothing waiting to settle.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDailyBrief(Store $store): array
    {
        $store = $store->fresh() ?? $store;
        $yesterdaySales = (float) StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->whereDate('paid_at', now()->subDay()->toDateString())
            ->sum('total_amount');
        $openOrders = (int) StoreOrder::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['pending', 'processing'])
            ->where('payment_status', 'paid')
            ->count();
        $lowStock = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->whereNotNull('stock_quantity')
            ->where('stock_quantity', '>', 0)
            ->where('stock_quantity', '<=', StoreProductService::LOW_STOCK_THRESHOLD)
            ->orderBy('stock_quantity')
            ->limit(3)
            ->get(['name', 'stock_quantity'])
            ->map(fn (StoreProduct $product): array => [
                'name' => $product->name,
                'stock' => (int) $product->stock_quantity,
            ])
            ->all();
        $abandoned = $this->abandoned->listAbandoned($store, 3, 1);
        $pendingPayout = (float) StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('payment_status', 'paid')
            ->where('settlement_status', 'pending_settlement')
            ->sum('total_amount');

        $suggestion = 'Add a product or share your store link.';
        if ($openOrders > 0) {
            $suggestion = 'Ship your open orders.';
        } elseif ($lowStock !== []) {
            $suggestion = 'Restock '.$lowStock[0]['name'].'.';
        } elseif ((int) ($abandoned['summary']['total'] ?? 0) > 0) {
            $suggestion = 'Send a reminder to someone who left checkout.';
        }

        return [
            'ok' => true,
            'yesterday_sales_label' => 'NGN '.number_format($yesterdaySales, 0),
            'open_orders' => $openOrders,
            'low_stock' => $lowStock,
            'abandoned_count' => (int) ($abandoned['summary']['total'] ?? 0),
            'pending_payout_label' => 'NGN '.number_format($pendingPayout, 0),
            'suggested_action' => $suggestion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderPreview(Store $store, StoreOrder $order): array
    {
        $items = is_array($order->items) ? $order->items : [];
        $lines = [];
        $image = null;
        foreach (array_slice($items, 0, 5) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $qty = (int) ($item['quantity'] ?? 1);
            $lines[] = $qty.'× '.((string) ($item['name'] ?? 'Item'));
            if ($image === null && filled($item['image_url'] ?? null)) {
                $raw = (string) $item['image_url'];
                $image = $this->media->browserUrl($raw) ?: $raw;
            }
        }

        $payment = (string) $order->payment_status;
        $payout = match ((string) ($order->settlement_status ?? '')) {
            'pending_settlement' => 'Payout pending',
            'settled' => 'Payout landed',
            default => $payment === 'paid' ? 'Paid' : 'Unpaid',
        };

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer_name ?: 'Customer',
            'phone' => $order->customer_phone,
            'address' => $order->delivery_address,
            'items' => $lines,
            'total_label' => 'NGN '.number_format((float) $order->total_amount, 0),
            'status' => (string) $order->status,
            'payment_status' => $payment,
            'settlement_status' => $order->settlement_status,
            'payout_label' => $payout,
            'image_url' => $image,
            'url' => $this->storefrontUrl($store),
            'store_name' => $store->name,
            'can_ship' => $payment === 'paid' && ! in_array((string) $order->status, ['shipped', 'delivered', 'cancelled'], true),
            'can_pay' => in_array($payment, ['pending', 'awaiting_payment'], true),
            'can_call' => filled($order->customer_phone),
            'can_cancel' => (string) $order->status !== 'cancelled',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storePreview(Store $store): array
    {
        $store = $store->fresh() ?? $store;
        $image = $this->media->browserUrl($store->logo_url) ?: $store->logo_url;
        if (! filled($image)) {
            $published = is_array($store->published_json) ? $store->published_json : [];
            $image = $published['media']['hero_image_url'] ?? $published['hero']['image_url'] ?? null;
        }
        if (! filled($image)) {
            $image = StoreProduct::query()
                ->where('store_id', $store->id)
                ->whereNotNull('image_url')
                ->orderByDesc('updated_at')
                ->value('image_url');
        }

        return [
            'store_name' => $store->name,
            'url' => $this->storefrontUrl($store),
            'image_url' => filled($image) ? $this->media->browserUrl((string) $image) ?: $image : null,
            'is_published' => $this->publish->isPublished($store),
        ];
    }

    private function storeProductImage(Store $store, string $mediaId): string
    {
        return $this->storeProductImageWithBytes($store, $mediaId)['url'];
    }

    /**
     * @return array{url: string, contents: string, mime: string}
     */
    private function storeProductImageWithBytes(Store $store, string $mediaId): array
    {
        $file = $this->whatsapp->downloadMedia($mediaId);
        $mime = $file['mime'] !== '' ? $file['mime'] : 'image/jpeg';
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $url = $this->media->store(
            'storehause/uploads/'.$store->id.'/'.Str::uuid().'.'.$extension,
            $file['contents'],
            $mime,
        );

        return [
            'url' => $url,
            'contents' => $file['contents'],
            'mime' => $mime,
        ];
    }

    /**
     * @return array{name: string, price: float|null, description: string, category: string|null}|null
     */
    private function identifyProductPhoto(Store $store, string $contents, string $mime): ?array
    {
        $store->loadMissing('merchant');
        $result = $this->vision->analyzeProductBytes($contents, $mime, [
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? '',
            'description' => $store->description ?? '',
        ]);

        if (! is_array($result) || isset($result['error']) || empty($result['name'])) {
            return null;
        }

        return [
            'name' => (string) $result['name'],
            'price' => isset($result['price']) && is_numeric($result['price']) ? (float) $result['price'] : null,
            'description' => (string) ($result['description'] ?? ''),
            'category' => $result['category'] ?? null,
        ];
    }

    private function resolveFocusedOrder(WhatsAppMerchantSession $session, Store $store, string $target): ?StoreOrder
    {
        if ($target !== '') {
            $found = $this->resolveOrder($session, $store, $target);
            if ($found) {
                return $found;
            }
        }

        $focusedId = $session->context['last_order']['id'] ?? null;
        if ($focusedId) {
            return StoreOrder::query()->where('store_id', $store->id)->find($focusedId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rememberOrder(WhatsAppMerchantSession $session, Store $store, StoreOrder $order): array
    {
        $preview = $this->orderPreview($store, $order);
        $session->mergeContext([
            'last_order' => $preview,
            'show_order_card' => true,
        ]);
        $session->save();

        return $preview;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolGetStoreSummary(Store $store): array
    {
        $store = $store->fresh() ?? $store;
        $pending = StoreOrder::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        return [
            'ok' => true,
            'store_name' => $store->name,
            'url' => $this->storefrontUrl($store),
            'is_published' => $this->publish->isPublished($store),
            'product_count' => (int) ($store->products_count ?? 0),
            'order_count' => (int) ($store->orders_count ?? 0),
            'open_orders' => $pending,
            'gross_revenue_label' => 'NGN '.number_format((float) ($store->gross_revenue ?? 0), 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolUpdateStoreProfile(Store $store, array $arguments): array
    {
        $changed = [];
        if (array_key_exists('name', $arguments)) {
            $name = mb_substr(trim((string) $arguments['name']), 0, 120);
            if ($name !== '') {
                $store->name = $name;
                $changed[] = 'name';
            }
        }
        if (array_key_exists('description', $arguments) && $arguments['description'] !== null) {
            $store->description = mb_substr(trim((string) $arguments['description']), 0, 1000);
            $changed[] = 'description';
        }
        $perks = $this->parsePerks($arguments['store_perks'] ?? null);
        if ($perks !== null) {
            $store->store_perks = array_slice($perks, 0, 12);
            $changed[] = 'store_perks';
        }
        if (array_key_exists('contact_phone', $arguments) && $arguments['contact_phone'] !== null) {
            $store->contact_phone = mb_substr(trim((string) $arguments['contact_phone']), 0, 40);
            $changed[] = 'contact_phone';
        }
        if (array_key_exists('fulfilment_promise', $arguments) && $arguments['fulfilment_promise'] !== null) {
            $store->fulfilment_promise = mb_substr(trim((string) $arguments['fulfilment_promise']), 0, 255);
            $changed[] = 'fulfilment_promise';
        }
        foreach (['allow_local_delivery', 'allow_pickup'] as $flag) {
            if (array_key_exists($flag, $arguments) && is_bool($arguments[$flag])) {
                $store->{$flag} = $arguments[$flag];
                $changed[] = $flag;
            }
        }

        if ($changed === []) {
            return ['ok' => false, 'error' => 'Nothing to update on the store profile.'];
        }

        $store->save();
        $this->cache->forgetStore($store);
        $this->publishStorefront($store->fresh() ?? $store);

        return [
            'ok' => true,
            'updated' => $changed,
            'store_name' => $store->name,
            'url' => $this->storefrontUrl($store),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolListCustomers(Store $store, array $arguments): array
    {
        $search = trim((string) ($arguments['search'] ?? ''));
        $listed = $this->customers->listForStore($store, $search !== '' ? $search : null, 1, 8);
        $items = array_map(fn (array $row): array => [
            'name' => $row['name'] ?? 'Customer',
            'phone' => $row['phone'] ?? null,
            'orders' => $row['orders_count'] ?? 0,
            'spent_label' => 'NGN '.number_format((float) ($row['total_spent'] ?? 0), 0),
        ], $listed['data']);

        return [
            'ok' => true,
            'count' => count($items),
            'customers' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListDiscounts(Store $store): array
    {
        $items = collect($this->discounts->listForStore($store))
            ->take(10)
            ->map(fn (array $row): array => [
                'name' => $row['name'] ?? '',
                'type' => $row['type'] ?? null,
                'value' => $row['discount_type'] === 'percent'
                    ? ((float) ($row['discount_value'] ?? 0)).'%'
                    : 'NGN '.number_format((float) ($row['discount_value'] ?? 0), 0),
                'status' => $row['status'] ?? null,
            ])
            ->values()
            ->all();

        return [
            'ok' => true,
            'count' => count($items),
            'discounts' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCreateDiscount(Store $store, array $arguments): array
    {
        $name = mb_substr(trim((string) ($arguments['name'] ?? '')), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Give the discount a name.'];
        }

        $percent = $arguments['percent'] ?? null;
        $amount = $arguments['amount'] ?? null;
        if (is_numeric($percent)) {
            $discountType = 'percent';
            $value = max(1, min(90, (float) $percent));
        } elseif (is_numeric($amount)) {
            $discountType = 'fixed';
            $value = max(1, (float) $amount);
        } else {
            return ['ok' => false, 'error' => 'Tell me the percent off or the NGN amount.'];
        }

        $type = strtolower(trim((string) ($arguments['type'] ?? 'cart_threshold')));
        if (! in_array($type, ['product', 'cart_threshold', 'seasonal'], true)) {
            $type = 'cart_threshold';
        }

        $payload = [
            'name' => $name,
            'type' => $type,
            'discount_type' => $discountType,
            'discount_value' => $value,
            'min_subtotal' => is_numeric($arguments['min_subtotal'] ?? null)
                ? (float) $arguments['min_subtotal']
                : null,
            'status' => 'active',
        ];
        $endsAt = $this->parseDiscountEnd($arguments['ends_at'] ?? null);
        if ($endsAt !== null) {
            $payload['ends_at'] = $endsAt;
        }

        $discount = $this->discounts->createForStore($store, $payload);
        $this->cache->forgetStore($store);

        return [
            'ok' => true,
            'discount' => $this->discounts->format($discount),
        ];
    }

    private function parseDiscountEnd(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $raw = strtolower(trim($value));
        if (in_array($raw, ['this weekend', 'weekend', 'sunday'], true)) {
            return now('Africa/Lagos')->endOfWeek(Carbon::SUNDAY);
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toolListCategories(Store $store): array
    {
        $items = collect($this->categories->listForStore($store))
            ->take(20)
            ->map(fn (array $row): array => [
                'name' => $row['name'] ?? '',
                'products' => $row['products_count'] ?? 0,
            ])
            ->values()
            ->all();

        return [
            'ok' => true,
            'count' => count($items),
            'categories' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCreateCategory(Store $store, array $arguments): array
    {
        $name = mb_substr(trim((string) ($arguments['name'] ?? '')), 0, 80);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Give the category a name.'];
        }

        $category = $this->categories->createForStore($store, ['name' => $name]);
        $this->cache->forgetStore($store);

        return [
            'ok' => true,
            'category' => [
                'name' => $category->name,
                'slug' => $category->slug,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productPreview(Store $store, StoreProduct $product): array
    {
        $formatted = $this->products->format($product);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'price_label' => 'NGN '.number_format((float) $product->price, 0),
            'url' => $this->storefrontUrl($store).'/products/'.$product->slug,
            'image_url' => $formatted['image_url'] ?? $product->image_url,
            'has_image' => filled($product->image_url),
            'has_description' => trim((string) $product->description) !== '',
            'has_perks' => is_array($product->perks) && $product->perks !== [],
            'status' => $product->status,
            'store_name' => $store->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rememberProduct(WhatsAppMerchantSession $session, Store $store, StoreProduct $product): array
    {
        $preview = $this->productPreview($store, $product);
        $session->mergeContext([
            'last_product' => $preview,
            'show_product_card' => true,
        ]);
        $session->save();

        return $preview;
    }

    /**
     * @return list<string>
     */
    private function productNextStepHints(StoreProduct $product): array
    {
        $hints = [];
        if (trim((string) $product->description) === '') {
            $hints[] = 'write a description';
        }
        if (! is_array($product->perks) || $product->perks === []) {
            $hints[] = 'add perks (delivery, warranty, returns)';
        }
        if (! filled($product->image_url)) {
            $hints[] = 'add a photo';
        }
        if ($product->stock_quantity === null) {
            $hints[] = 'set stock';
        }
        $hints[] = 'add another product';

        return $hints;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveFocusedProduct(
        WhatsAppMerchantSession $session,
        Store $store,
        array $arguments,
    ): ?StoreProduct {
        $search = trim((string) ($arguments['search'] ?? ''));
        if ($search !== '') {
            $found = $this->findProduct($store, $search);
            if ($found) {
                return $found;
            }
        }

        $focused = is_array($session->context['last_product'] ?? null) ? $session->context['last_product'] : [];
        $id = (string) ($focused['id'] ?? '');
        if ($id !== '') {
            $byId = StoreProduct::query()->where('store_id', $store->id)->find($id);
            if ($byId) {
                return $byId;
            }
        }

        $name = trim((string) ($focused['name'] ?? ''));

        return $name !== '' ? $this->findProduct($store, $name) : null;
    }

    /**
     * @return list<string>|null
     */
    private function parsePerks(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return null;
        }

        $perks = [];
        foreach ($value as $item) {
            $perk = mb_substr(trim((string) $item), 0, 160);
            $perk = preg_replace('/^perk:\s*/i', '', $perk) ?? $perk;
            if ($perk === '') {
                continue;
            }
            $perks[] = $perk;
        }

        return array_values(array_unique($perks));
    }

    private function draftProductDescription(Store $store, StoreProduct $product, string $instruction): string
    {
        $existing = trim((string) $product->description);
        $fallback = $existing !== ''
            ? $existing
            : $product->name.' — NGN '.number_format((float) $product->price, 0)
                .'. Available now at '.($store->name ?: 'our store').'. Shop from the product link or message us to order.';

        if (! $this->aiChat->available()) {
            return $fallback;
        }

        try {
            $provider = $this->aiConfig->provider();
            $response = $this->aiChat->chatCompletions([
                'model' => $this->aiConfig->chatModel($provider),
                'temperature' => 0.55,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Write a short selling description for a Nigerian WhatsApp store. 2-4 sentences. No hashtags. No invented specs. Currency is NGN. Return JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'store' => $store->name,
                            'product' => $product->name,
                            'price' => (float) $product->price,
                            'category' => $product->category,
                            'existing_description' => $existing !== '' ? $existing : null,
                            'instruction' => $instruction !== '' ? $instruction : null,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'product_description',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'description' => ['type' => 'string'],
                            ],
                            'required' => ['description'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ], $provider);

            $decoded = json_decode((string) $response->json('choices.0.message.content'), true);
            $text = is_array($decoded) ? trim((string) ($decoded['description'] ?? '')) : '';

            return $text !== '' ? mb_substr($text, 0, 2000) : $fallback;
        } catch (\Throwable $e) {
            Log::warning('WhatsApp product description draft failed.', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    private function findProduct(Store $store, string $search): ?StoreProduct
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }

        $needle = strtolower($search);
        $slug = Str::slug($search);

        return StoreProduct::query()
            ->where('store_id', $store->id)
            ->orderByDesc('updated_at')
            ->get()
            ->first(function (StoreProduct $product) use ($needle, $slug): bool {
                $name = strtolower((string) $product->name);

                return $name === $needle
                    || str_contains($name, $needle)
                    || str_contains($needle, $name)
                    || ($slug !== '' && $product->slug === $slug);
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function publishStorefront(Store $store): array
    {
        $store = $store->fresh(['merchant']) ?? $store;

        try {
            $draft = $this->publish->resolveDraft($store);
            if (! is_array($draft) || $draft === []) {
                $name = $store->name ?: 'Store';
                $description = $store->description ?: $name.' on Bizgrid.';
                $draft = [
                    'template' => [
                        'id' => $store->storefront_template_id ?: StorefrontTemplate::DEFAULT_ID,
                        'source' => 'whatsapp',
                    ],
                    'data_plugs' => [
                        'home_products_source' => 'merchant_products',
                    ],
                    'hero' => [
                        'headline' => "Shop {$name} online",
                        'subheadline' => $description,
                        'cta_label' => 'Shop now',
                    ],
                    'about' => [
                        'title' => "About {$name}",
                        'body' => $description,
                    ],
                    'seo' => [
                        'title' => $name.' | Online Store',
                        'description' => $description,
                    ],
                ];
            }

            $draft = $this->products->mergeIntoStorefront($draft, $store, true);
            $this->publish->persistDraft($store, $draft);
            $published = $this->publish->publish($store->fresh(['merchant']) ?? $store);
            $this->cache->forgetStore($published);

            return [
                'ok' => true,
                'published' => true,
                'url' => $this->storefrontUrl($published),
                'status' => (string) $published->status,
            ];
        } catch (\Throwable $e) {
            Log::warning('WhatsApp storefront publish failed.', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'published' => false,
                'error' => $e->getMessage(),
                'url' => $this->storefrontUrl($store),
                'status' => (string) ($store->status ?? 'draft'),
            ];
        }
    }

    private function storefrontUrl(Store $store): string
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        return 'https://'.$store->slug.'.'.$platformDomain;
    }

    private function dashboardUrl(User $user): string
    {
        $tokenResult = $user->createToken('storehause');
        $tokenResult->accessToken->expires_at = now()->addDays(1);
        $tokenResult->accessToken->save();

        $code = Str::random(64);
        Cache::put("auth:exchange:{$code}", [
            'token' => $tokenResult->plainTextToken,
            'user_id' => $user->id,
            'type' => 'merchant',
        ], now()->addMinutes(15));

        return rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/').'/login?auth_code='.urlencode($code);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolLinkExistingAccount(array $arguments): array
    {
        $email = strtolower(trim((string) ($arguments['email'] ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Need a valid email to link this WhatsApp.'];
        }

        $exists = User::query()->whereRaw('lower(email) = ?', [$email])->exists();
        if (! $exists) {
            return ['ok' => false, 'error' => 'No Bizgrid account uses that email.', 'email' => $email];
        }

        return [
            'ok' => true,
            'email' => $email,
            'next' => 'send_link_code',
        ];
    }
}
