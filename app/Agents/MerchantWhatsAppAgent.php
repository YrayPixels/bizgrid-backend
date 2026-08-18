<?php

declare(strict_types=1);

namespace App\Agents;

use App\Services\PromptService;

class MerchantWhatsAppAgent extends BaseAgent
{
    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'merchant-whatsapp-agent';
    }

    public function temperature(): float
    {
        return 0.35;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string'],
            ],
            'required' => ['reply'],
            'additionalProperties' => false,
        ];
    }

    protected function llmProvider(): string
    {
        if ($this->aiConfig()->available('openai')) {
            return 'openai';
        }

        return parent::llmProvider();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'add_product',
                    'description' => 'Add a product to the merchant catalog, or continue a half-finished add (name then price, or a photo this turn).',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name, e.g. Lip gloss. Null if you still need to ask.',
                            ],
                            'price' => [
                                'type' => ['number', 'null'],
                                'description' => 'Price in NGN as a number, e.g. 4500. Null if unknown.',
                            ],
                            'description' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional short description.',
                            ],
                            'stock_quantity' => [
                                'type' => ['number', 'null'],
                                'description' => 'Optional stock count.',
                            ],
                            'category' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional category name.',
                            ],
                            'perks' => [
                                'type' => ['array', 'null'],
                                'items' => ['type' => 'string'],
                                'description' => 'Optional highlight perks, e.g. Free delivery in Lagos.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_product',
                    'description' => 'Update an existing product (photo, name, price, description, stock, status, sale price, category). Use when they say update/change/edit/hide an item — never add_product for updates. If they mean the last product they added, search can be omitted.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name or partial name to find, e.g. Gucci Cap. Optional when focused_product is set.',
                            ],
                            'name' => [
                                'type' => ['string', 'null'],
                                'description' => 'New product name if they want to rename it.',
                            ],
                            'price' => [
                                'type' => ['number', 'null'],
                                'description' => 'New price in NGN if changing price.',
                            ],
                            'sale_price' => [
                                'type' => ['number', 'null'],
                                'description' => 'Optional sale price in NGN. Null to clear.',
                            ],
                            'description' => [
                                'type' => ['string', 'null'],
                                'description' => 'New description if they typed one themselves.',
                            ],
                            'stock_quantity' => [
                                'type' => ['number', 'null'],
                                'description' => 'How many units are in stock.',
                            ],
                            'status' => [
                                'type' => ['string', 'null'],
                                'description' => 'active, draft, or archived. Use archived to hide a product.',
                            ],
                            'category' => [
                                'type' => ['string', 'null'],
                                'description' => 'Category name to assign.',
                            ],
                            'sku' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional SKU.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_product',
                    'description' => 'Show one product: photo status, price, description, perks, stock, and storefront link.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name. Optional when focused_product is set.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'generate_product_description',
                    'description' => 'Write or rewrite a selling description for a product. Use when they tap Write description / Rewrite copy, or ask you to write copy. Do not invent facts; keep it short.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name. Optional when focused_product is set.',
                            ],
                            'instruction' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional tone or emphasis, e.g. luxury, mention free delivery.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'set_product_perks',
                    'description' => 'Set highlight perks on a product (warranty, delivery, returns). If they tap Add perks without naming any, call with an empty perks list to show suggestions. Messages starting with perk: mean add that perk.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name. Optional when focused_product is set.',
                            ],
                            'perks' => [
                                'type' => ['array', 'null'],
                                'items' => ['type' => 'string'],
                                'description' => 'Perk labels to apply. Empty or omitted returns suggestions.',
                            ],
                            'mode' => [
                                'type' => ['string', 'null'],
                                'description' => 'add (default, merge) or replace.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'publish_store',
                    'description' => 'Publish the storefront so customers can visit it live. Requires at least one product. Call when they want the store live or is_published is false.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason they want to publish.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_products',
                    'description' => 'List products currently in this store catalog.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional name filter.',
                            ],
                            'status' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional filter: all, active, draft, or archived.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_orders',
                    'description' => 'List the latest store orders with status and totals.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need the order list.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_order_status',
                    'description' => 'Update an order status. Prefer shipped. Target may be a list number from list_orders (1) or an order number.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => [
                                'type' => 'string',
                                'description' => 'List index (1) or order number.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'description' => 'shipped, processing, delivered, or cancelled.',
                            ],
                        ],
                        'required' => ['target'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_storefront_link',
                    'description' => 'Return the live storefront URL.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need the link.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'open_dashboard',
                    'description' => 'Create a 15-minute magic link to the merchant dashboard.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need the dashboard link.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'show_help',
                    'description' => 'Show what the merchant can do in this chat when they ask for the menu or help.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason they need help.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_store_summary',
                    'description' => 'Store snapshot: sales, order count, products, whether the storefront is live. Use for how is my store doing / sales / stats.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need the summary.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_store_profile',
                    'description' => 'Update store name, about text, store-wide perks, contact phone, or delivery options.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => ['string', 'null'],
                                'description' => 'New store name.',
                            ],
                            'description' => [
                                'type' => ['string', 'null'],
                                'description' => 'About / store description.',
                            ],
                            'store_perks' => [
                                'type' => ['array', 'null'],
                                'items' => ['type' => 'string'],
                                'description' => 'Store-wide highlights shown on every product unless overridden.',
                            ],
                            'contact_phone' => [
                                'type' => ['string', 'null'],
                                'description' => 'Public contact phone.',
                            ],
                            'fulfilment_promise' => [
                                'type' => ['string', 'null'],
                                'description' => 'e.g. Same-day delivery in Lagos.',
                            ],
                            'allow_local_delivery' => [
                                'type' => ['boolean', 'null'],
                            ],
                            'allow_pickup' => [
                                'type' => ['boolean', 'null'],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_customers',
                    'description' => 'List recent customers with spend and order counts.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Optional name, email, or phone filter.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_discounts',
                    'description' => 'List store discounts and sale rules.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need discounts.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_discount',
                    'description' => 'Create a store discount. Percent off the cart, a fixed NGN amount, or a product sale.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Discount name, e.g. Weekend 10% off.',
                            ],
                            'percent' => [
                                'type' => ['number', 'null'],
                                'description' => 'Percent off, e.g. 10 for 10%.',
                            ],
                            'amount' => [
                                'type' => ['number', 'null'],
                                'description' => 'Fixed NGN amount off, if not using percent.',
                            ],
                            'min_subtotal' => [
                                'type' => ['number', 'null'],
                                'description' => 'Optional minimum order total in NGN.',
                            ],
                            'ends_at' => [
                                'type' => ['string', 'null'],
                                'description' => 'When it ends. ISO datetime or "this weekend" (Sunday 23:59 Africa/Lagos).',
                            ],
                            'type' => [
                                'type' => ['string', 'null'],
                                'description' => 'cart_threshold (default), product, or seasonal.',
                            ],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_categories',
                    'description' => 'List product categories in this store.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need categories.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_category',
                    'description' => 'Create a product category.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Category name, e.g. Caps.',
                            ],
                        ],
                        'required' => ['name'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_order',
                    'description' => 'Show one order as a card: items, customer, phone, address, payment. Use when they tap an order or ask about a specific order.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => [
                                'type' => ['string', 'null'],
                                'description' => 'List index (1), order number, or omit for focused_order.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'mark_order_paid',
                    'description' => 'Mark an unpaid order as paid (bank transfer received). Do not use if it is already paid.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => [
                                'type' => ['string', 'null'],
                                'description' => 'List index or order number. Optional when focused_order is set.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'contact_customer',
                    'description' => 'Send the customer as a WhatsApp contact so the merchant can call or chat them. Use when they tap Call customer.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => [
                                'type' => ['string', 'null'],
                                'description' => 'List index or order number. Optional when focused_order is set.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'put_on_sale',
                    'description' => 'Put a product on sale with a sale price or percent off. Use for "put the cap on sale" or "20% off lip gloss".',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => ['string', 'null'],
                                'description' => 'Product name. Optional when focused_product is set.',
                            ],
                            'sale_price' => [
                                'type' => ['number', 'null'],
                                'description' => 'New sale price in NGN.',
                            ],
                            'percent' => [
                                'type' => ['number', 'null'],
                                'description' => 'Percent off the regular price, e.g. 10.',
                            ],
                            'clear' => [
                                'type' => ['boolean', 'null'],
                                'description' => 'True to remove the sale and go back to regular price.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'update_discount',
                    'description' => 'Pause, resume, or end a store discount by name.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'search' => [
                                'type' => 'string',
                                'description' => 'Discount name or partial name.',
                            ],
                            'status' => [
                                'type' => 'string',
                                'description' => 'active (resume), draft (pause), or archived (end).',
                            ],
                        ],
                        'required' => ['search', 'status'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_abandoned_carts',
                    'description' => 'People who left checkout or a cart. Use for abandoned carts / reminders.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need abandoned carts.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'send_abandoned_reminder',
                    'description' => 'Send a checkout reminder to one abandoned cart. Prefer WhatsApp, then email. Target may be a list number from list_abandoned_carts.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'target' => [
                                'type' => 'string',
                                'description' => 'List index (1) or customer name.',
                            ],
                        ],
                        'required' => ['target'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_payouts',
                    'description' => 'Pending vs received payout snapshot for this store.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason you need payouts.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'daily_brief',
                    'description' => 'Morning snapshot: yesterday sales, open orders, low stock, abandoned carts, one next action.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Short reason they want the brief.',
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{content: ?string, tool_calls: list<array<string, mixed>>}|null
     */
    public function complete(array $messages, array $tools): ?array
    {
        return $this->chatWithTools($messages, $tools);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $messages = is_array($context['messages'] ?? null) ? $context['messages'] : [];
        if ($messages === []) {
            return null;
        }

        return $this->complete($messages, $this->tools());
    }
}
