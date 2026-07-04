<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FacebookService
{
    public function isConfigured(): bool
    {
        return filled(config('facebook.app_id')) && filled(config('facebook.app_secret'));
    }

    public function redirectUri(): string
    {
        $configured = config('facebook.redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/api/storehause/marketing/facebook/callback';
    }

    /**
     * @return array{url: string, state: string}
     */
    public function buildAuthorizationUrl(Store $store, int $userId): array
    {
        $this->assertConfigured();

        $state = Str::random(48);
        Cache::put($this->stateCacheKey($state), [
            'store_id' => $store->id,
            'user_id' => $userId,
        ], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => config('facebook.app_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'scope' => implode(',', config('facebook.scopes', [])),
            'response_type' => 'code',
        ]);

        return [
            'url' => 'https://www.facebook.com/'.config('facebook.graph_version').'/dialog/oauth?'.$query,
            'state' => $state,
        ];
    }

    /**
     * @return array{store_id: int, user_id: int}|null
     */
    public function consumeOAuthState(string $state): ?array
    {
        $key = $this->stateCacheKey($state);
        $payload = Cache::pull($key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Exchange OAuth code, fetch managed pages, and persist connections for the store.
     *
     * @return list<StoreSocialConnection>
     */
    public function connectStoreFromOAuthCode(Store $store, string $code): array
    {
        $this->assertConfigured();

        $shortLived = $this->exchangeCodeForToken($code);
        $longLived = $this->exchangeForLongLivedToken($shortLived['access_token']);
        $pages = $this->fetchManagedPages($longLived['access_token']);

        $connections = [];

        foreach ($pages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            $pageName = (string) ($page['name'] ?? 'Facebook Page');
            $pageToken = (string) ($page['access_token'] ?? '');

            if ($pageId === '' || $pageToken === '') {
                continue;
            }

            $connections[] = StoreSocialConnection::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'provider' => 'facebook',
                    'page_id' => $pageId,
                ],
                [
                    'provider_account_id' => (string) ($shortLived['user_id'] ?? ''),
                    'page_name' => $pageName,
                    'page_access_token' => $pageToken,
                    'token_expires_at' => isset($longLived['expires_in'])
                        ? now()->addSeconds((int) $longLived['expires_in'])
                        : null,
                    'metadata' => [
                        'category' => $page['category'] ?? null,
                    ],
                ],
            );
        }

        if ($connections === []) {
            throw new RuntimeException('No Facebook Pages were found on this account. You need admin access to at least one Page.');
        }

        return $connections;
    }

    /**
     * @return list<array{id: string, name: string, provider: string}>
     */
    public function listConnections(Store $store): array
    {
        return $store->socialConnections()
            ->where('provider', 'facebook')
            ->orderBy('page_name')
            ->get()
            ->map(fn (StoreSocialConnection $connection): array => [
                'id' => (string) $connection->id,
                'provider' => $connection->provider,
                'page_id' => $connection->page_id,
                'name' => $connection->page_name,
            ])
            ->values()
            ->all();
    }

    public function disconnect(Store $store, ?int $connectionId = null): void
    {
        $query = $store->socialConnections()->where('provider', 'facebook');

        if ($connectionId !== null) {
            $query->where('id', $connectionId);
        }

        $query->delete();
    }

    /**
     * @return array{post_id: string, url: ?string}
     */
    public function publishFeedPost(StoreSocialConnection $connection, string $message, ?string $linkUrl = null): array
    {
        $payload = ['message' => $message];

        if ($linkUrl !== null && $linkUrl !== '') {
            $payload['link'] = $linkUrl;
        }

        $response = $this->graphRequest(
            'post',
            "/{$connection->page_id}/feed",
            $payload,
            (string) $connection->page_access_token,
        );

        $postId = (string) ($response['id'] ?? '');

        if ($postId === '') {
            throw new RuntimeException('Facebook did not return a post id.');
        }

        return [
            'post_id' => $postId,
            'url' => $this->guessPostUrl($postId),
        ];
    }

    /**
     * @return array{access_token: string, user_id?: string}
     */
    private function exchangeCodeForToken(string $code): array
    {
        $response = Http::acceptJson()->get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Facebook authorization failed.'));
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['access_token'])) {
            throw new RuntimeException('Facebook authorization did not return an access token.');
        }

        return $data;
    }

    /**
     * @return array{access_token: string, expires_in?: int}
     */
    private function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::acceptJson()->get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Failed to exchange Facebook token.'));
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['access_token'])) {
            throw new RuntimeException('Facebook long-lived token exchange failed.');
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchManagedPages(string $userAccessToken): array
    {
        $response = $this->graphRequest('get', '/me/accounts', [
            'fields' => 'id,name,access_token,category',
        ], $userAccessToken);

        $data = $response['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function graphRequest(string $method, string $path, array $payload = [], ?string $accessToken = null): array
    {
        $url = $this->graphUrl($path);
        $request = Http::acceptJson()->timeout(30);

        if ($accessToken !== null) {
            $payload['access_token'] = $accessToken;
        }

        $response = match (strtolower($method)) {
            'get' => $request->get($url, $payload),
            'post' => $request->post($url, $payload),
            default => throw new RuntimeException("Unsupported Graph API method [{$method}]."),
        };

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Facebook API request failed.'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('facebook.graph_version').'/'.ltrim($path, '/');
    }

    private function stateCacheKey(string $state): string
    {
        return 'facebook_oauth:'.$state;
    }

    private function guessPostUrl(string $postId): ?string
    {
        if (! str_contains($postId, '_')) {
            return null;
        }

        [$pageId, $storyId] = explode('_', $postId, 2);

        return "https://www.facebook.com/{$pageId}/posts/{$storyId}";
    }

    /**
     * @param  mixed  $payload
     */
    private function extractErrorMessage(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $error = $payload['error'] ?? null;

        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return $error['message'];
        }

        return $fallback;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Facebook integration is not configured.');
        }
    }
}
