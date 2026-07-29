<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiCacheService
{
    public function enabled(): bool
    {
        if (! config('api-cache.enabled', true)) {
            return false;
        }

        return config('cache.default') === 'redis';
    }

    public function supportsTags(): bool
    {
        return $this->enabled();
    }

    public function resolveKey(Request $request, string $profile): ?string
    {
        $path = '/'.ltrim($request->path(), '/');
        $query = $request->query();
        ksort($query);
        $queryHash = $query === [] ? '' : ':'.sha1(http_build_query($query));

        return match ($profile) {
            'merchant' => $this->merchantKey($request, $path, $queryHash),
            'admin' => $this->adminKey($request, $path, $queryHash),
            'public' => $this->publicKey($request, $path, $queryHash),
            'shared' => 'api:shared:'.$path.$queryHash,
            default => null,
        };
    }

    /** @return list<string> */
    public function resolveTags(Request $request, string $profile): array
    {
        $tags = match ($profile) {
            'merchant' => $this->merchantTags($request),
            'admin' => $this->adminTags($request),
            'public' => $this->publicTags($request),
            'shared' => ['shared'],
            default => [],
        };

        return array_values(array_unique(array_filter($tags)));
    }

    public function ttl(Request $request): int
    {
        $path = $request->path();
        $map = config('api-cache.ttl', []);
        $default = (int) config('api-cache.default_ttl', 60);

        return match (true) {
            str_contains($path, 'stores/me/domains') => $map['store_me'] ?? $default,
            str_contains($path, 'stores/me') && ! str_contains($path, 'payments') => $map['store_me'] ?? $default,
            str_ends_with($path, 'dashboard') => $map['dashboard'] ?? $default,
            str_ends_with($path, 'products') => $map['products'] ?? $default,
            str_ends_with($path, 'categories') => $map['categories'] ?? $default,
            str_contains($path, 'orders/') => $map['order'] ?? $default,
            str_ends_with($path, 'orders') => $map['orders'] ?? $default,
            str_contains($path, 'ai/storefront/') => $map['storefront_draft'] ?? $default,
            str_contains($path, 'storefront-builder/sessions/current') => $map['builder_session'] ?? $default,
            str_ends_with($path, 'storefront-templates') => $map['templates'] ?? $default,
            str_contains($path, 'stores/me/payments') => $map['payments'] ?? $default,
            str_contains($path, 'billing/subscription') => $map['billing'] ?? $default,
            str_contains($path, 'marketing/abandoned') => $map['marketing_abandoned'] ?? $default,
            str_contains($path, 'marketing/status') => $map['marketing'] ?? $default,
            str_contains($path, 'public/storefronts/by-host'),
            str_contains($path, 'public/storefronts/resolve-host'),
            str_contains($path, 'public/storefronts/') && ! str_ends_with($path, 'storefronts') => $map['public_storefront'] ?? $default,
            str_ends_with($path, 'public/storefronts') => $map['public_index'] ?? $default,
            str_contains($path, 'admin/analytics/overview') => $map['admin_analytics'] ?? $default,
            str_contains($path, 'admin/merchants') => $map['admin_merchants'] ?? $default,
            str_contains($path, 'admin/orders') => $map['admin_orders'] ?? $default,
            str_contains($path, 'admin/health') => $map['admin_health'] ?? $default,
            str_contains($path, 'admin/search') => $map['admin_search'] ?? $default,
            str_contains($path, 'admin/notifications') => $map['admin_notifications'] ?? $default,
            str_contains($path, 'admin/agent-logs') => $map['admin_agent_logs'] ?? $default,
            str_contains($path, 'admin/') => $map['admin_default'] ?? $default,
            default => $default,
        };
    }

    public function get(string $key, array $tags = []): mixed
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($this->supportsTags() && $tags !== []) {
            return Cache::tags($tags)->get($key);
        }

        return Cache::get($key);
    }

    public function put(string $key, mixed $value, int $ttl, array $tags = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->supportsTags() && $tags !== []) {
            Cache::tags($tags)->put($key, $value, $ttl);

            return;
        }

        Cache::put($key, $value, $ttl);
    }

    public function forgetStore(Store $store): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(["store:{$store->id}"])->flush();

        $ownerUserId = $store->merchant?->owner_user_id;
        if ($ownerUserId) {
            Cache::tags(["user:{$ownerUserId}"])->flush();
        }

        if ($store->slug) {
            Cache::tags(["public:{$store->slug}"])->flush();

            $platformDomain = strtolower((string) config('storehause.platform_domain', 'bizgrid.shop'));
            if ($platformDomain !== '') {
                Cache::tags(['public:host:'.$store->slug.'.'.$platformDomain])->flush();
            }
        }

        if ($store->primary_domain) {
            Cache::tags(['public:host:'.strtolower($store->primary_domain)])->flush();
        }

        foreach ($store->domains()->where('status', 'verified')->pluck('hostname') as $hostname) {
            Cache::tags(['public:host:'.strtolower($hostname)])->flush();
        }

        $this->forgetAdmin();
    }

    public function forgetPublicHost(string $hostname): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(['public:host:'.strtolower($hostname)])->flush();
    }

    public function forgetUser(int $userId): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(["user:{$userId}"])->flush();
    }

    public function forgetMerchant(int $merchantId): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(["merchant:{$merchantId}"])->flush();
    }

    public function forgetPublicSlug(string $slug): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(["public:{$slug}"])->flush();
        Cache::tags(['public:index'])->flush();
    }

    public function forgetTemplates(): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(['templates'])->flush();
        Cache::tags(['shared'])->flush();
        $this->forgetAdmin();
    }

    public function forgetAdmin(): void
    {
        if (! $this->supportsTags()) {
            return;
        }

        Cache::tags(['admin'])->flush();
    }

    private function merchantKey(Request $request, string $path, string $queryHash): ?string
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return null;
        }

        // Auth identity must stay fresh — never serve a stale email_verified_at.
        if (str_contains($path, '/auth/me') || str_ends_with($path, '/auth/me')) {
            return null;
        }

        // Staff/locations change often from the same session; avoid stale team lists.
        if (
            str_ends_with($path, '/staff')
            || str_contains($path, '/staff/')
            || str_ends_with($path, '/pos/locations')
            || str_ends_with($path, '/locations')
            || str_contains($path, '/locations/')
        ) {
            return null;
        }

        return "api:merchant:{$userId}:{$path}{$queryHash}";
    }

    private function adminKey(Request $request, string $path, string $queryHash): ?string
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return null;
        }

        return "api:admin:{$userId}:{$path}{$queryHash}";
    }

    private function publicKey(Request $request, string $path, string $queryHash): string
    {
        $slug = (string) $request->route('slug', '');
        $host = (string) $request->query('host', $request->header('Host', ''));

        if ($slug !== '') {
            return "api:public:slug:{$slug}:{$path}{$queryHash}";
        }

        if ($host !== '') {
            return 'api:public:host:'.strtolower($host).":{$path}{$queryHash}";
        }

        return "api:public:{$path}{$queryHash}";
    }

    /** @return list<string> */
    private function merchantTags(Request $request): array
    {
        $tags = [];
        $userId = $request->user()?->id;
        if ($userId) {
            $tags[] = "user:{$userId}";
        }

        $merchantId = $request->user()?->merchant?->id;
        if ($merchantId) {
            $tags[] = "merchant:{$merchantId}";
        }

        return $tags;
    }

    /** @return list<string> */
    private function adminTags(Request $request): array
    {
        $tags = ['admin'];
        $userId = $request->user()?->id;
        if ($userId) {
            $tags[] = "admin-user:{$userId}";
        }

        return $tags;
    }

    /** @return list<string> */
    private function publicTags(Request $request): array
    {
        $tags = ['public:index'];
        $slug = (string) $request->route('slug', '');
        if ($slug !== '') {
            $tags[] = "public:{$slug}";
        }

        $host = (string) $request->query('host', $request->header('Host', ''));
        if ($host !== '') {
            $tags[] = 'public:host:'.strtolower($host);
        }

        if (str_ends_with($request->path(), 'storefront-templates')) {
            $tags[] = 'templates';
        }

        return $tags;
    }
}
