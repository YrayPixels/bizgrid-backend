<?php

use App\Services\ApiCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('is enabled only when redis is the cache store', function () {
    config(['api-cache.enabled' => true]);
    config(['cache.default' => 'redis']);
    expect(app(ApiCacheService::class)->enabled())->toBeTrue();

    config(['cache.default' => 'database']);
    expect(app(ApiCacheService::class)->enabled())->toBeFalse();
});

it('builds distinct cache keys for merchant paths and query strings', function () {
    $service = app(ApiCacheService::class);

    $base = Request::create('/api/storehause/products', 'GET');
    $base->setUserResolver(fn () => (object) ['id' => 7]);

    $filtered = Request::create('/api/storehause/products', 'GET', ['status' => 'draft']);
    $filtered->setUserResolver(fn () => (object) ['id' => 7]);

    expect($service->resolveKey($base, 'merchant'))
        ->not->toBe($service->resolveKey($filtered, 'merchant'));
});

it('assigns public slug tags for storefront routes', function () {
    $service = app(ApiCacheService::class);

    $request = Request::create('/api/storehause/public/storefronts/demo-shop', 'GET');
    $request->setRouteResolver(function () use ($request) {
        $route = new \Illuminate\Routing\Route('GET', 'public/storefronts/{slug}', []);
        $route->bind($request);
        $route->setParameter('slug', 'demo-shop');

        return $route;
    });

    expect($service->resolveTags($request, 'public'))->toContain('public:demo-shop');
});

it('uses shorter ttl for dashboard than store profile', function () {
    $service = app(ApiCacheService::class);

    $dashboard = Request::create('/api/storehause/dashboard', 'GET');
    $store = Request::create('/api/storehause/stores/me', 'GET');

    expect($service->ttl($dashboard))->toBeLessThan($service->ttl($store));
});
