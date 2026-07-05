<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use App\Services\ApiCacheService;

trait InvalidatesApiCache
{
    protected function invalidateStoreApiCache(Store $store): void
    {
        app(ApiCacheService::class)->forgetStore($store);
    }

    protected function invalidateUserApiCache(int $userId): void
    {
        app(ApiCacheService::class)->forgetUser($userId);
    }

    protected function invalidateMerchantApiCache(int $merchantId): void
    {
        app(ApiCacheService::class)->forgetMerchant($merchantId);
    }

    protected function invalidatePublicStorefrontCache(string $slug): void
    {
        app(ApiCacheService::class)->forgetPublicSlug($slug);
    }

    protected function invalidateTemplateApiCache(): void
    {
        app(ApiCacheService::class)->forgetTemplates();
    }

    protected function invalidateAdminApiCache(): void
    {
        app(ApiCacheService::class)->forgetAdmin();
    }
}
