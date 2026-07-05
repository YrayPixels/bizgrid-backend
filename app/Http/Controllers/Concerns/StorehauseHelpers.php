<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\StoreNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

trait StorehauseHelpers
{
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

    protected function formatUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'has_store' => Merchant::where('owner_user_id', $user->id)->whereHas('stores')->exists(),
            'is_admin' => (bool) $user->is_admin,
        ];
    }

    protected function formatStore(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
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
            'staff_count' => $store->staff_count,
            'physical_store_count' => $store->physical_store_count,
            'storefront_template_id' => $store->storefront_template_id ?? 'ai_pick',
            'subdomain' => $store->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
            'notifications' => app(StoreNotificationService::class)->formatNotificationSettings($store),
        ];
    }

    protected function formatOrder(StoreOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'paystack_reference' => $order->paystack_reference,
            'settlement_status' => $order->settlement_status,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'total_amount' => (float) $order->total_amount,
            'items' => $order->items ?? [],
            'notes' => $order->notes,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    protected function productCount(Store $store): int
    {
        return StoreProduct::where('store_id', $store->id)->count();
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
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');

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
