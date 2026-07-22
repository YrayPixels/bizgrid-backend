<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorefrontTemplate;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\StoreVisit;
use App\Models\User;
use App\Services\StoreNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait StorehauseHelpers
{
    use InvalidatesApiCache;
    protected function findOwnedStore(Request $request, int $storeId): Store
    {
        $store = Store::with('merchant')
            ->where('id', $storeId)
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->first();

        if (! $store) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    protected function findOwnedStoreForUser(Request $request): Store
    {
        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->first();

        if (! $store) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    protected function formatUser(User $user, bool $impersonating = false): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'has_store' => Merchant::where('owner_user_id', $user->id)->whereHas('stores')->exists(),
            'is_admin' => (bool) $user->is_admin,
            'impersonating' => $impersonating,
        ];
    }

    protected function assertEmailVerified(Request $request, string $message = 'Verify your email before continuing.'): void
    {
        $user = $request->user();
        if ($user->currentAccessToken()?->name === 'admin-impersonation') {
            return;
        }

        if ($user->email_verified_at) {
            return;
        }

        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => $message,
            'code' => 'email_unverified',
        ], 403));
    }

    protected function formatStore(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $subdomainHost = "{$store->slug}.{$platformDomain}";

        return [
            'id' => (string) $store->id,
            'slug' => $store->slug,
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description ?? '',
            'brand_color' => $store->brand_color ?? '#0E7C66',
            'logo_url' => $store->logo_url,
            'contact_email' => $store->contact_email ?? $store->merchant?->email,
            'contact_phone' => $store->contact_phone,
            'business_location' => $store->business_location,
            'weekly_orders' => $store->weekly_orders,
            'payment_currencies' => $store->payment_currencies ?? [],
            'payout_account_name' => $store->payout_account_name,
            'payout_bank_name' => $store->payout_bank_name,
            'payout_account_number' => $store->payout_account_number,
            'payouts_configured' => filled($store->payout_account_name)
                && filled($store->payout_bank_name)
                && filled($store->payout_account_number),
            'checkout_enabled' => filled(config('paystack.public_key')) && filled(config('paystack.secret_key')),
            'subscription_plan' => $store->merchant?->subscription_plan ?? 'starter',
            'subscription_status' => $store->merchant?->subscription_status ?? 'trialing',
            'staff_count' => $store->staff_count,
            'physical_store_count' => $store->physical_store_count,
            'storefront_template_id' => $store->storefront_template_id ?? StorefrontTemplate::DEFAULT_ID,
            'subdomain' => $store->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
            'notifications' => app(StoreNotificationService::class)->formatNotificationSettings($store),
            'shipping' => [
                'allow_local_delivery' => (bool) ($store->allow_local_delivery ?? true),
                'allow_pickup' => (bool) ($store->allow_pickup ?? false),
                'default_delivery_fee' => $store->default_delivery_fee !== null
                    ? (float) $store->default_delivery_fee
                    : null,
                'fulfilment_promise' => $store->fulfilment_promise,
                'shipping_policy' => $store->shipping_policy,
                'return_policy' => $store->return_policy,
            ],
            'store_perks' => array_values(array_filter(array_map(
                fn ($perk) => is_string($perk) ? trim($perk) : '',
                is_array($store->store_perks) ? $store->store_perks : [],
            ))),
        ];
    }

    protected function formatOrder(StoreOrder $order): array
    {
        $items = app(\App\Services\StoreOrderItemService::class)->linesForOrder($order);

        return [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'invoice_number' => $order->invoice_number,
            'store_customer_id' => $order->store_customer_id
                ? (string) $order->store_customer_id
                : null,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'delivery_method' => $order->delivery_method ?? 'delivery',
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'tracking_number' => $order->tracking_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'paystack_reference' => $order->paystack_reference,
            'settlement_status' => $order->settlement_status,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'discount_label' => $order->discount_label,
            'total_amount' => (float) $order->total_amount,
            'items' => $items,
            'notes' => $order->notes,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    protected function productCount(Store $store): int
    {
        return StoreProduct::where('store_id', $store->id)->count();
    }

    /**
     * Merchant store dashboard overview payload.
     *
     * @return array<string, mixed>
     */
    protected function buildMerchantDashboardPayload(Store $store): array
    {
        $since = now()->subDays(29)->startOfDay();
        $orderQuery = StoreOrder::where('store_id', $store->id);
        $salesQuery = (clone $orderQuery)
            ->where('status', '!=', 'cancelled')
            ->where('payment_status', '!=', 'refunded');
        $totalVisits = StoreVisit::where('store_id', $store->id)->count();
        $totalOrders = (clone $orderQuery)->count();
        $totalSales = (float) (clone $salesQuery)->sum('total_amount');

        $statusCounts = (clone $orderQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $salesByDate = (clone $salesQuery)
            ->where('placed_at', '>=', $since)
            ->selectRaw('DATE(placed_at) as date, COUNT(*) as orders, SUM(total_amount) as sales')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $salesByDay = collect(range(0, 29))->map(function (int $offset) use ($since, $salesByDate) {
            $date = $since->copy()->addDays($offset)->toDateString();
            $row = $salesByDate->get($date);

            return [
                'date' => $date,
                'orders' => (int) ($row->orders ?? 0),
                'sales' => (float) ($row->sales ?? 0),
            ];
        })->values();

        $topProducts = \App\Models\StoreOrderItem::query()
            ->selectRaw('product_id, name, MAX(image_url) as image_url, MAX(unit_price) as unit_price, MAX(currency) as currency, SUM(quantity) as quantity_sold, SUM(line_total) as total_earning')
            ->where('store_id', $store->id)
            ->whereIn('store_order_id', (clone $salesQuery)->select('id'))
            ->groupBy('product_id', 'name')
            ->orderByDesc('total_earning')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'product_id' => (string) ($row->product_id ?? ''),
                'name' => (string) $row->name,
                'image_url' => $row->image_url,
                'unit_price' => (float) $row->unit_price,
                'currency' => (string) ($row->currency ?? 'NGN'),
                'quantity_sold' => (int) $row->quantity_sold,
                'total_earning' => (float) $row->total_earning,
            ])
            ->values()
            ->all();

        // Fallback for stores whose items were not yet backfilled.
        if ($topProducts === []) {
            $productTotals = [];
            foreach ((clone $salesQuery)->get(['items']) as $order) {
                foreach ($order->items ?? [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $key = (string) ($item['product_id'] ?? $item['name'] ?? '');
                    if ($key === '') {
                        continue;
                    }
                    if (! isset($productTotals[$key])) {
                        $productTotals[$key] = [
                            'product_id' => (string) ($item['product_id'] ?? ''),
                            'name' => (string) ($item['name'] ?? 'Product'),
                            'image_url' => $item['image_url'] ?? null,
                            'unit_price' => (float) ($item['unit_price'] ?? 0),
                            'currency' => (string) ($item['currency'] ?? 'NGN'),
                            'quantity_sold' => 0,
                            'total_earning' => 0.0,
                        ];
                    }
                    $qty = (int) ($item['quantity'] ?? 0);
                    $lineTotal = (float) ($item['total'] ?? (($item['unit_price'] ?? 0) * $qty));
                    $productTotals[$key]['quantity_sold'] += $qty;
                    $productTotals[$key]['total_earning'] += $lineTotal;
                }
            }
            $topProducts = collect($productTotals)
                ->sortByDesc('total_earning')
                ->take(5)
                ->values()
                ->all();
        }

        $referrers = StoreVisit::where('store_id', $store->id)
            ->where('visited_at', '>=', $since)
            ->pluck('referrer');

        $trafficBuckets = [
            'Direct' => 0,
            'Google' => 0,
            'Social' => 0,
            'Other' => 0,
        ];

        foreach ($referrers as $referrer) {
            $host = $this->classifyVisitReferrer(is_string($referrer) ? $referrer : null);
            $trafficBuckets[$host]++;
        }

        $trafficTotal = max(array_sum($trafficBuckets), 1);
        $trafficSources = collect($trafficBuckets)
            ->map(fn (int $count, string $source) => [
                'source' => $source,
                'count' => $count,
                'percentage' => (int) round(($count / $trafficTotal) * 100),
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'metrics' => [
                'total_orders' => $totalOrders,
                'pending_orders' => (int) ($statusCounts['pending'] ?? 0),
                'processing_orders' => (int) ($statusCounts['processing'] ?? 0),
                'shipped_orders' => (int) ($statusCounts['shipped'] ?? 0),
                'delivered_orders' => (int) ($statusCounts['delivered'] ?? 0),
                'fulfilled_orders' => (int) ($statusCounts['delivered'] ?? 0),
                'cancelled_orders' => (int) ($statusCounts['cancelled'] ?? 0),
                'total_sales' => $totalSales,
                'average_order_value' => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
                'total_visits' => $totalVisits,
                'visits_today' => StoreVisit::where('store_id', $store->id)
                    ->where('visited_at', '>=', now()->startOfDay())
                    ->count(),
                'visits_last_30_days' => StoreVisit::where('store_id', $store->id)
                    ->where('visited_at', '>=', $since)
                    ->count(),
                'conversion_rate' => $totalVisits > 0 ? round(($totalOrders / $totalVisits) * 100, 2) : 0,
                'products_count' => $this->productCount($store),
            ],
            'sales_by_day' => $salesByDay,
            'top_products' => $topProducts,
            'traffic_sources' => $trafficSources,
            'orders_by_status' => [
                ['status' => 'pending', 'label' => 'Pending', 'count' => (int) ($statusCounts['pending'] ?? 0)],
                ['status' => 'processing', 'label' => 'Processing', 'count' => (int) ($statusCounts['processing'] ?? 0)],
                ['status' => 'shipped', 'label' => 'Shipped', 'count' => (int) ($statusCounts['shipped'] ?? 0)],
                ['status' => 'delivered', 'label' => 'Delivered', 'count' => (int) ($statusCounts['delivered'] ?? 0)],
            ],
            'recent_orders' => StoreOrder::where('store_id', $store->id)
                ->latest('placed_at')
                ->limit(5)
                ->get()
                ->map(fn (StoreOrder $order) => $this->formatOrder($order))
                ->values(),
        ];
    }

    protected function classifyVisitReferrer(?string $referrer): string
    {
        if ($referrer === null || trim($referrer) === '') {
            return 'Direct';
        }

        $host = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?? $referrer));

        if (str_contains($host, 'google.') || $host === 'google' || str_contains($host, 'bing.') || str_contains($host, 'yahoo.')) {
            return 'Google';
        }

        if (
            str_contains($host, 'facebook.')
            || str_contains($host, 'instagram.')
            || str_contains($host, 'twitter.')
            || str_contains($host, 'x.com')
            || str_contains($host, 'tiktok.')
            || str_contains($host, 'linkedin.')
        ) {
            return 'Social';
        }

        return 'Other';
    }

    protected function ensureStoreMerchantActive(Store $store): void
    {
        $store->loadMissing('merchant');
        if ($store->merchant?->status === 'suspended') {
            abort(403, 'This storefront is temporarily unavailable.');
        }
    }

    protected function findStoreByHost(string $host): ?Store
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        if (str_ends_with($host, '.'.$platformDomain)) {
            $prefix = substr($host, 0, -strlen($platformDomain) - 1);
            $subdomain = explode('.', $prefix)[0] ?? '';

            if ($subdomain === '' || in_array($subdomain, $this->reservedSubdomains(), true)) {
                return null;
            }

            return Store::with('merchant')->where('slug', $subdomain)->first();
        }

        if (str_ends_with($host, '.localhost')) {
            $subdomain = explode('.', substr($host, 0, -strlen('.localhost')))[0] ?? '';

            if ($subdomain === '' || in_array($subdomain, $this->reservedSubdomains(), true)) {
                return null;
            }

            return Store::with('merchant')->where('slug', $subdomain)->first();
        }

        $customDomain = StoreDomain::query()
            ->where('hostname', $host)
            ->where('status', 'verified')
            ->first();

        if ($customDomain) {
            return Store::with('merchant')->find($customDomain->store_id);
        }

        return Store::with('merchant')->where('primary_domain', $host)->first();
    }

    /** @return list<string> */
    protected function reservedSubdomains(): array
    {
        return [
            'www', 'app', 'api', 'admin', 'dashboard', 'portal',
            'docs', 'help', 'status', 'blog', 'mail', 'static', 'assets', 'cdn',
        ];
    }

    protected function uniqueMerchantSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Merchant::where('slug', $slug)->exists());
    }

    protected function uniqueStoreSlug(string $name, ?string $baseSlug = null): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Store::where('slug', $slug)->exists(), $baseSlug);
    }

    protected function uniqueOrderNumber(): string
    {
        do {
            $orderNumber = 'SH-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (StoreOrder::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    protected function uniqueSlug(string $name, callable $exists, ?string $baseSlug = null): string
    {
        $base = $baseSlug ?: (Str::slug($name) ?: 'store');
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    protected function businessProfileRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'business_location' => [$presence, 'string', Rule::in(['nigeria', 'kenya'])],
            'weekly_orders' => [$presence, 'string', Rule::in(['0-50', '51-100', '101-1000', '1001+'])],
            'payment_currencies' => [$presence, 'array', 'min:1'],
            'payment_currencies.*' => ['string', Rule::in(['NGN', 'KES', 'USD', 'GBP', 'CAD', 'others'])],
            'staff_count' => [$presence, 'string', Rule::in(['none', '1-3', '4-5', '6-10', '11+'])],
            'physical_store_count' => [$presence, 'string', Rule::in(['none', '1', '2', '3', '4+'])],
        ];
    }
}
