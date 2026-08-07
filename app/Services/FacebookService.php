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
    /**
     * The long-lived *user* token is kept alongside the page tokens under this
     * provider. Instagram publishing and Ads both need it — page tokens cannot
     * reach either API.
     */
    public const USER_PROVIDER = 'facebook_user';

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

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
     * Scopes actually requested at the dialog. Instagram and Ads scopes only
     * join once the platform's Meta app has been approved for them.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        $scopes = (array) config('facebook.scopes', []);

        if (config('facebook.insights_enabled')) {
            $scopes = array_merge($scopes, (array) config('facebook.insights_scopes', []));
        }

        if (config('facebook.instagram_enabled')) {
            $scopes = array_merge($scopes, (array) config('facebook.instagram_scopes', []));
        }

        if (config('facebook.ads_enabled')) {
            $scopes = array_merge($scopes, (array) config('facebook.ads_scopes', []));
        }

        return array_values(array_unique(array_filter($scopes, 'is_string')));
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
            'scope' => implode(',', $this->scopes()),
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

        $userToken = (string) $longLived['access_token'];
        $expiresAt = isset($longLived['expires_in'])
            ? now()->addSeconds((int) $longLived['expires_in'])
            : null;

        $facebookUserId = (string) ($shortLived['user_id'] ?? '');
        if ($facebookUserId === '') {
            $facebookUserId = (string) ($this->fetchUserProfile($userToken)['id'] ?? '');
        }

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
                    'provider_account_id' => $facebookUserId,
                    'page_name' => $pageName,
                    'page_access_token' => $pageToken,
                    // Page tokens derived from a long-lived user token do not
                    // themselves expire, but they die with the user token, so
                    // that is the expiry worth surfacing.
                    'token_expires_at' => $expiresAt,
                    'status' => 'active',
                    'invalid_reason' => null,
                    'last_checked_at' => now(),
                    'metadata' => [
                        'category' => $page['category'] ?? null,
                        'instagram_business_account' => $page['instagram_business_account']['id'] ?? null,
                    ],
                ],
            );
        }

        if ($connections === []) {
            throw new RuntimeException('No Facebook Pages were found on this account. You need admin access to at least one Page.');
        }

        if ($facebookUserId !== '') {
            StoreSocialConnection::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'provider' => self::USER_PROVIDER,
                    'page_id' => $facebookUserId,
                ],
                [
                    'provider_account_id' => $facebookUserId,
                    'page_name' => 'Facebook account',
                    'page_access_token' => $userToken,
                    'token_expires_at' => $expiresAt,
                    'status' => 'active',
                    'invalid_reason' => null,
                    'last_checked_at' => now(),
                    'metadata' => [
                        'granted_scopes' => $this->fetchGrantedScopes($userToken),
                    ],
                ],
            );
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
                'status' => $connection->status,
                'token_expires_at' => $connection->token_expires_at?->toIso8601String(),
                'expiring_soon' => $connection->isExpiringSoon(),
                'instagram_connected' => filled($connection->metadata['instagram_business_account'] ?? null),
            ])
            ->values()
            ->all();
    }

    public function userConnection(Store $store): ?StoreSocialConnection
    {
        return $store->socialConnections()
            ->where('provider', self::USER_PROVIDER)
            ->latest()
            ->first();
    }

    public function disconnect(Store $store, ?int $connectionId = null): void
    {
        $query = $store->socialConnections()->where('provider', 'facebook');

        if ($connectionId !== null) {
            $query->where('id', $connectionId);

            $query->delete();

            // Only drop the shared user token once the last Page is gone —
            // Instagram and Ads still depend on it.
            if (! $store->socialConnections()->where('provider', 'facebook')->exists()) {
                $this->disconnectUserToken($store);
            }

            return;
        }

        $query->delete();
        $this->disconnectUserToken($store);
    }

    private function disconnectUserToken(Store $store): void
    {
        $store->socialConnections()
            ->whereIn('provider', [self::USER_PROVIDER, 'instagram'])
            ->delete();
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

        $response = $this->graph->post(
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
     * Publish a photo post. Merchants sell things people look at, so an image
     * post is the format that actually performs — the link variant buries the
     * product behind a preview card.
     *
     * @return array{post_id: string, url: ?string}
     */
    public function publishPhotoPost(
        StoreSocialConnection $connection,
        string $message,
        string $imageUrl,
        ?string $linkUrl = null,
    ): array {
        // A link in the caption still renders as a tappable URL, and keeps the
        // photo as the visual rather than the link preview.
        $caption = $linkUrl !== null && $linkUrl !== '' && ! str_contains($message, $linkUrl)
            ? rtrim($message)."\n\n".$linkUrl
            : $message;

        $response = $this->graph->post(
            "/{$connection->page_id}/photos",
            [
                'url' => $imageUrl,
                'caption' => $caption,
                'published' => true,
            ],
            (string) $connection->page_access_token,
        );

        // /photos returns the photo id plus the feed story it created; the
        // post_id is what insights and permalinks key off.
        $postId = (string) ($response['post_id'] ?? $response['id'] ?? '');

        if ($postId === '') {
            throw new RuntimeException('Facebook did not return a post id for the photo.');
        }

        return [
            'post_id' => $postId,
            'url' => $this->guessPostUrl($postId),
        ];
    }

    /**
     * Engagement numbers for a published post, so merchants can see which
     * campaigns actually worked instead of guessing.
     *
     * @return array<string, int|string|null>
     */
    public function fetchPostInsights(StoreSocialConnection $connection, string $externalPostId): array
    {
        $response = $this->graph->get(
            "/{$externalPostId}",
            [
                'fields' => 'permalink_url,shares,comments.summary(true).limit(0),reactions.summary(true).limit(0),insights.metric(post_impressions_unique,post_clicks)',
            ],
            (string) $connection->page_access_token,
        );

        $metrics = [];
        foreach ($response['insights']['data'] ?? [] as $entry) {
            if (! is_array($entry) || ! isset($entry['name'])) {
                continue;
            }
            $metrics[(string) $entry['name']] = (int) ($entry['values'][0]['value'] ?? 0);
        }

        return [
            'permalink_url' => $response['permalink_url'] ?? null,
            'reach' => $metrics['post_impressions_unique'] ?? null,
            'clicks' => $metrics['post_clicks'] ?? null,
            'reactions' => isset($response['reactions']['summary']['total_count'])
                ? (int) $response['reactions']['summary']['total_count']
                : null,
            'comments' => isset($response['comments']['summary']['total_count'])
                ? (int) $response['comments']['summary']['total_count']
                : null,
            'shares' => isset($response['shares']['count']) ? (int) $response['shares']['count'] : 0,
        ];
    }

    /**
     * Ask Meta whether a stored token is still good. Cheaper and more reliable
     * than waiting for the next publish to fail.
     *
     * @return array{valid: bool, expires_at: ?\Illuminate\Support\Carbon, reason: ?string, scopes: list<string>}
     */
    public function inspectToken(string $token): array
    {
        $appToken = config('facebook.app_id').'|'.config('facebook.app_secret');

        try {
            $response = $this->graph->get('/debug_token', [
                'input_token' => $token,
            ], $appToken);
        } catch (\Throwable $e) {
            return ['valid' => false, 'expires_at' => null, 'reason' => $e->getMessage(), 'scopes' => []];
        }

        $data = $response['data'] ?? [];
        if (! is_array($data)) {
            return ['valid' => false, 'expires_at' => null, 'reason' => 'Unreadable token response.', 'scopes' => []];
        }

        $expiresAt = ! empty($data['expires_at']) ? now()->setTimestamp((int) $data['expires_at']) : null;

        return [
            'valid' => (bool) ($data['is_valid'] ?? false),
            'expires_at' => $expiresAt,
            'reason' => is_string($data['error']['message'] ?? null) ? $data['error']['message'] : null,
            'scopes' => array_values(array_filter((array) ($data['scopes'] ?? []), 'is_string')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUserProfile(string $userAccessToken): array
    {
        try {
            return $this->graph->get('/me', ['fields' => 'id,name'], $userAccessToken);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function fetchGrantedScopes(string $userAccessToken): array
    {
        return $this->inspectToken($userAccessToken)['scopes'];
    }

    /**
     * @return array{access_token: string, user_id?: string}
     */
    private function exchangeCodeForToken(string $code): array
    {
        $response = Http::acceptJson()->get($this->graph->url('/oauth/access_token'), [
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->graph->errorMessage($response->json(), 'Facebook authorization failed.'));
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
        $response = Http::acceptJson()->get($this->graph->url('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('facebook.app_id'),
            'client_secret' => config('facebook.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->graph->errorMessage($response->json(), 'Failed to exchange Facebook token.'));
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
        $response = $this->graph->get('/me/accounts', [
            'fields' => 'id,name,access_token,category,instagram_business_account{id,username}',
        ], $userAccessToken);

        $data = $response['data'] ?? [];

        return is_array($data) ? $data : [];
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

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Facebook integration is not configured.');
        }
    }
}
