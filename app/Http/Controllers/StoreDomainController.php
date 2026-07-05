<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\StoreDomain;
use App\Services\StoreDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class StoreDomainController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly StoreDomainService $domains,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $store->loadMissing('merchant');

        $merchant = $store->merchant;
        $entries = StoreDomain::where('store_id', $store->id)
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->get();

        return response()->json([
            'domains' => $entries->map(fn (StoreDomain $domain) => $this->domains->formatDomain($domain, $store))->values(),
            'meta' => [
                'allowed' => $merchant ? $this->domains->planAllowsCustomDomains($merchant) : false,
                'max_domains' => $merchant ? $this->domains->maxCustomDomains($merchant) : 0,
                'used' => $entries->count(),
                'subdomain_host' => $this->domains->cnameTarget($store),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $store->loadMissing('merchant');

        $data = $request->validate([
            'hostname' => 'required|string|max:253',
        ]);

        try {
            $domain = $this->domains->createDomain($store, $data['hostname']);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'domain' => $this->domains->formatDomain($domain, $store),
        ], 201);
    }

    public function verify(Request $request, int $domainId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $domain = StoreDomain::where('store_id', $store->id)->where('id', $domainId)->first();

        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        try {
            $domain = $this->domains->verifyDomain($domain, $store);
        } catch (InvalidArgumentException $exception) {
            $status = $this->domains->verificationStatus($domain, $store);

            return response()->json([
                'message' => $exception->getMessage(),
                'domain' => $this->domains->formatDomain($domain, $store),
                'verification' => $status,
            ], 422);
        }

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'domain' => $this->domains->formatDomain($domain, $store),
            'message' => 'Domain verified and connected.',
        ]);
    }

    public function setPrimary(Request $request, int $domainId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $domain = StoreDomain::where('store_id', $store->id)->where('id', $domainId)->first();

        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        try {
            $domain = $this->domains->setPrimaryDomain($domain, $store);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'domain' => $this->domains->formatDomain($domain, $store),
            'message' => 'Primary domain updated.',
        ]);
    }

    public function destroy(Request $request, int $domainId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $domain = StoreDomain::where('store_id', $store->id)->where('id', $domainId)->first();

        if (! $domain) {
            return response()->json(['message' => 'Domain not found.'], 404);
        }

        $hostname = $domain->hostname;
        $this->domains->deleteDomain($domain, $store);
        $this->invalidateStoreApiCache($store);
        app(\App\Services\ApiCacheService::class)->forgetPublicHost($hostname);

        return response()->json([
            'message' => 'Domain removed.',
        ]);
    }
}
