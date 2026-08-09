<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds a shared demo merchant for one-click /demo access (judges / organizers).
 *
 * Idempotent: re-running refreshes storefront content, products, and sample orders
 * for the demo account without duplicating the user/merchant/store.
 */
class DemoMerchantSeeder extends Seeder
{
    public const STORE_SLUG = 'glow-rituals-demo';

    public function run(): void
    {
        $email = strtolower((string) config('storehause.demo_email', 'demo@bizgrid.shop'));
        $name = (string) config('storehause.demo_name', 'Demo Merchant');
        $password = (string) config('storehause.demo_password', '');

        if ($password === '') {
            // Stable local default when unset — change via STOREHAUSE_DEMO_PASSWORD in shared/staging.
            $password = 'DemoBizgrid2026!';
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->is_admin = false;
        $user->admin_role = null;
        $user->save();

        $merchant = Merchant::query()->firstOrNew(['owner_user_id' => $user->id]);
        $merchant->fill([
            'business_name' => 'Glow Rituals',
            'slug' => 'glow-rituals-demo',
            'industry' => 'beauty_and_skincare',
            'status' => 'active',
            'subscription_plan' => 'growth',
            'subscription_status' => 'active',
            'activated_at' => $merchant->activated_at ?? now(),
            'suspended_at' => null,
            'suspension_reason' => null,
            'sms_included_remaining' => 100,
            'sms_purchased_balance' => 50,
            'whatsapp_included_remaining' => 50,
            'whatsapp_purchased_balance' => 25,
            'ai_purchased_credits' => 200,
            'ai_credits_used_today' => 0,
            'ai_credits_date' => now()->toDateString(),
            'tags' => ['demo'],
        ]);
        $merchant->save();

        $platformDomain = (string) config('storehause.platform_domain', 'bizgrid.shop');
        $storefront = $this->demoStorefront();

        $store = Store::query()->firstOrNew([
            'merchant_id' => $merchant->id,
            'slug' => self::STORE_SLUG,
        ]);
        $store->fill([
            'name' => 'Glow Rituals',
            'status' => 'published',
            'primary_domain' => self::STORE_SLUG.'.'.$platformDomain,
            'description' => 'Clean, calm skincare essentials for everyday routines.',
            'brand_color' => '#82934C',
            'contact_email' => $email,
            'contact_phone' => '+2348000000000',
            'business_location' => 'nigeria',
            'weekly_orders' => '51-100',
            'payment_currencies' => ['NGN'],
            'staff_count' => '1-3',
            'physical_store_count' => '1',
            'allow_local_delivery' => true,
            'allow_pickup' => true,
            'default_delivery_fee' => 1500,
            'storefront_template_id' => 'cosmetics',
            'draft_json' => $storefront,
            'published_json' => $storefront,
            'storefront_content' => $storefront,
            'published_at' => now(),
            'products_count' => 0,
            'orders_count' => 0,
            'gross_revenue' => 0,
        ]);
        $store->save();

        $this->syncProducts($store);
        $this->syncSampleOrders($store);

        $this->command?->info("Demo merchant ready: {$email} (store /s/".self::STORE_SLUG.')');
    }

    /**
     * @return array<string, mixed>
     */
    private function demoStorefront(): array
    {
        return [
            'hero' => [
                'eyebrow' => 'Clean skincare, Lagos-made',
                'headline' => 'Glow Rituals',
                'subheadline' => 'Thoughtful skincare essentials with a calm, premium feel — built for everyday routines.',
                'cta_label' => 'Shop the collection',
            ],
            'about' => [
                'title' => 'About Glow Rituals',
                'body' => 'We craft lightweight serums and moisturizers for busy people who want healthy skin without complicated routines. This is a demo storefront so organizers can explore Bizgrid end-to-end.',
            ],
            'value_props' => [
                ['title' => 'Clean formulas', 'body' => 'Straightforward ingredients with transparent labeling.'],
                ['title' => 'Fast local delivery', 'body' => 'Demo shipping zones across major cities.'],
                ['title' => 'Easy checkout', 'body' => 'Paystack-ready cart and order tracking.'],
            ],
            'faq' => [
                ['question' => 'Is this a real shop?', 'answer' => 'No — this is a shared demo merchant account for product evaluation. Please treat data as ephemeral.'],
                ['question' => 'Can I edit the storefront?', 'answer' => 'Yes. Open the merchant dashboard, then try Website / Builder. Changes may be reset when the demo is re-seeded.'],
                ['question' => 'How do I leave the demo?', 'answer' => 'Sign out from the merchant menu, or clear the session and return to the homepage.'],
            ],
            'seo' => [
                'title' => 'Glow Rituals — Demo Storefront',
                'description' => 'Sample Bizgrid storefront for organizers and judges.',
            ],
            'pages' => [
                'about' => [
                    'title' => 'Our story',
                    'body' => 'Glow Rituals started as a sample brand for Bizgrid demos. Explore products, cart, and merchant tools without creating an account.',
                ],
                'faq' => [
                    'title' => 'Frequently asked questions',
                ],
            ],
        ];
    }

    private function syncProducts(Store $store): void
    {
        StoreProduct::query()->where('store_id', $store->id)->delete();

        $products = [
            [
                'slug' => 'daily-glow-serum',
                'name' => 'Daily Glow Serum',
                'description' => 'Lightweight vitamin serum for morning routines.',
                'price' => 12500,
                'sale_price' => 9900,
                'category' => 'Serums',
                'stock_quantity' => 42,
                'sort_order' => 1,
            ],
            [
                'slug' => 'calm-cloud-moisturizer',
                'name' => 'Calm Cloud Moisturizer',
                'description' => 'Soft gel-cream moisturizer for dry to combination skin.',
                'price' => 15000,
                'sale_price' => null,
                'category' => 'Moisturizers',
                'stock_quantity' => 28,
                'sort_order' => 2,
            ],
            [
                'slug' => 'soft-reset-cleanser',
                'name' => 'Soft Reset Cleanser',
                'description' => 'Gentle gel cleanser that removes SPF without stripping.',
                'price' => 8500,
                'sale_price' => null,
                'category' => 'Cleansers',
                'stock_quantity' => 55,
                'sort_order' => 3,
            ],
            [
                'slug' => 'evening-repair-oil',
                'name' => 'Evening Repair Oil',
                'description' => 'Nourishing face oil for overnight recovery.',
                'price' => 18000,
                'sale_price' => 15500,
                'category' => 'Oils',
                'stock_quantity' => 19,
                'sort_order' => 4,
            ],
        ];

        foreach ($products as $product) {
            StoreProduct::create([
                'id' => (string) Str::uuid(),
                'store_id' => $store->id,
                'slug' => $product['slug'],
                'name' => $product['name'],
                'description' => $product['description'],
                'price' => $product['price'],
                'sale_price' => $product['sale_price'],
                'currency' => 'NGN',
                'category' => $product['category'],
                'stock_quantity' => $product['stock_quantity'],
                'status' => 'active',
                'sort_order' => $product['sort_order'],
            ]);
        }

        $store->update([
            'products_count' => count($products),
        ]);
    }

    private function syncSampleOrders(Store $store): void
    {
        StoreOrder::query()->where('store_id', $store->id)->delete();

        $samples = [
            [
                'order_number' => 'DEMO-1001',
                'customer_name' => 'Ada Okonkwo',
                'customer_email' => 'ada.demo@example.com',
                'status' => 'fulfilled',
                'payment_status' => 'paid',
                'total' => 9900,
                'item_name' => 'Daily Glow Serum',
                'hours_ago' => 26,
            ],
            [
                'order_number' => 'DEMO-1002',
                'customer_name' => 'Chidi Okoro',
                'customer_email' => 'chidi.demo@example.com',
                'status' => 'processing',
                'payment_status' => 'paid',
                'total' => 23500,
                'item_name' => 'Calm Cloud Moisturizer',
                'hours_ago' => 8,
            ],
            [
                'order_number' => 'DEMO-1003',
                'customer_name' => 'Fatima Bello',
                'customer_email' => 'fatima.demo@example.com',
                'status' => 'pending',
                'payment_status' => 'awaiting_payment',
                'total' => 8500,
                'item_name' => 'Soft Reset Cleanser',
                'hours_ago' => 2,
            ],
        ];

        $gross = 0;
        foreach ($samples as $sample) {
            $gross += (float) $sample['total'];
            StoreOrder::create([
                'store_id' => $store->id,
                'order_number' => $sample['order_number'],
                'customer_name' => $sample['customer_name'],
                'customer_email' => $sample['customer_email'],
                'customer_phone' => '+2348010000000',
                'delivery_address' => 'Lagos, Nigeria',
                'delivery_method' => 'delivery',
                'delivery_fee' => 1500,
                'status' => $sample['status'],
                'payment_status' => $sample['payment_status'],
                'currency' => 'NGN',
                'subtotal' => $sample['total'],
                'total_amount' => $sample['total'] + 1500,
                'items' => [[
                    'product_id' => 'demo',
                    'name' => $sample['item_name'],
                    'quantity' => 1,
                    'unit_price' => $sample['total'],
                    'total' => $sample['total'],
                    'currency' => 'NGN',
                ]],
                'placed_at' => now()->subHours($sample['hours_ago']),
                'source' => 'storefront',
            ]);
        }

        $store->update([
            'orders_count' => count($samples),
            'gross_revenue' => $gross,
        ]);
    }
}
