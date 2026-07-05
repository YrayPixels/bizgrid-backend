<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiCacheService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheApiGetResponse
{
    public function __construct(
        private readonly ApiCacheService $apiCache,
    ) {}

    public function handle(Request $request, Closure $next, string $profile = 'merchant'): Response
    {
        if (! $this->apiCache->enabled() || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $key = $this->apiCache->resolveKey($request, $profile);
        if ($key === null) {
            return $next($request);
        }

        $tags = $this->apiCache->resolveTags($request, $profile);
        $cached = $this->apiCache->get($key, $tags);

        if (is_array($cached) && array_key_exists('body', $cached)) {
            return response()
                ->json($cached['body'], (int) ($cached['status'] ?? 200))
                ->header('X-Api-Cache', 'HIT');
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->apiCache->put($key, [
                'body' => $response->getData(true),
                'status' => $response->getStatusCode(),
            ], $this->apiCache->ttl($request), $tags);
            $response->headers->set('X-Api-Cache', 'MISS');
        }

        return $response;
    }
}
