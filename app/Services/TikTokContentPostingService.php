<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialPost;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TikTokContentPostingService
{
    public function isConfigured(): bool
    {
        return filled(config('tiktok.app_id')) && filled(config('tiktok.app_secret'));
    }

    public function contentCapabilities(): array
    {
        return [
            'supports_video_posting' => true,
            'supports_photo_posting' => false,
            'requires_app_audit_for_public' => true,
            'max_caption_length' => 2200,
            'source_methods' => ['PULL_FROM_URL'],
        ];
    }

    public function redirectUri(): string
    {
        $configured = config('tiktok.content_redirect_uri');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/api/storehause/marketing/tiktok/creator/callback';
    }

    /**
     * @return array{url: string, state: string}
     */
    public function buildAuthorizationUrl(int $storeId): array
    {
        $this->assertConfigured();

        $state = Str::random(48);
        Cache::put($this->stateCacheKey($state), ['store_id' => $storeId], now()->addMinutes(15));

        $query = http_build_query([
            'client_key' => config('tiktok.app_id'),
            'scope' => implode(',', config('tiktok.content_scopes', [])),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);

        return [
            'url' => rtrim((string) config('tiktok.oauth_authorize_url'), '?').'?'.$query,
            'state' => $state,
        ];
    }

    /**
     * @return array{store_id: int}|null
     */
    public function consumeOAuthState(string $state): ?array
    {
        $payload = Cache::pull($this->stateCacheKey($state));

        return is_array($payload) ? $payload : null;
    }

    public function connectStoreFromOAuthCode(int $storeId, string $code): StoreSocialConnection
    {
        $tokens = $this->exchangeCodeForTokens($code);
        $openId = (string) ($tokens['open_id'] ?? '');
        $accessToken = (string) ($tokens['access_token'] ?? '');
        $refreshToken = (string) ($tokens['refresh_token'] ?? '');

        if ($openId === '' || $accessToken === '') {
            throw new RuntimeException('TikTok authorization did not return creator credentials.');
        }

        $creator = $this->queryCreatorInfo($accessToken);
        $displayName = (string) ($creator['creator_username'] ?? $creator['creator_nickname'] ?? 'TikTok Creator');

        return StoreSocialConnection::updateOrCreate(
            [
                'store_id' => $storeId,
                'provider' => 'tiktok_creator',
                'page_id' => $openId,
            ],
            [
                'provider_account_id' => $openId,
                'page_name' => $displayName,
                'page_access_token' => $accessToken,
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'metadata' => [
                    'refresh_token' => $refreshToken,
                    'creator' => $creator,
                    'capabilities' => $this->contentCapabilities(),
                ],
            ],
        );
    }

    public function findCreatorConnection(int $storeId): ?StoreSocialConnection
    {
        return StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', 'tiktok_creator')
            ->latest()
            ->first();
    }

    public function disconnect(int $storeId): void
    {
        StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', 'tiktok_creator')
            ->delete();
    }

    /**
     * @return array{publish_id: string, status: string, post: SocialPost}
     */
    public function publishVideo(
        StoreSocialConnection $connection,
        SocialPost $post,
        string $videoUrl,
        string $caption,
    ): array {
        $connection = $this->ensureFreshToken($connection);
        $creator = $this->queryCreatorInfo((string) $connection->page_access_token);
        $privacyLevel = $this->defaultPrivacyLevel($creator);

        $init = $this->contentRequest('post', '/post/publish/video/init/', (string) $connection->page_access_token, [
            'post_info' => [
                'title' => Str::limit($caption, 2200, ''),
                'privacy_level' => $privacyLevel,
                'disable_duet' => false,
                'disable_comment' => false,
                'disable_stitch' => false,
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $videoUrl,
            ],
        ]);

        $publishId = (string) ($init['publish_id'] ?? '');
        if ($publishId === '') {
            throw new RuntimeException('TikTok did not return a publish id.');
        }

        $post->update([
            'publish_id' => $publishId,
            'status' => 'publishing',
            'video_url' => $videoUrl,
        ]);

        return [
            'publish_id' => $publishId,
            'status' => 'publishing',
            'post' => $post->fresh(),
        ];
    }

    public function refreshPublishStatus(StoreSocialConnection $connection, SocialPost $post): SocialPost
    {
        if (! filled($post->publish_id)) {
            return $post;
        }

        $connection = $this->ensureFreshToken($connection);

        $status = $this->contentRequest('post', '/post/publish/status/fetch/', (string) $connection->page_access_token, [
            'publish_id' => $post->publish_id,
        ]);

        $state = (string) ($status['status'] ?? '');
        $failReason = is_string($status['fail_reason'] ?? null) ? $status['fail_reason'] : null;

        if (in_array($state, ['PUBLISH_COMPLETE', 'PUBLISH_COMPLETE_INBOX'], true)) {
            $post->update([
                'status' => 'published',
                'external_post_id' => (string) ($status['publicaly_available_post_id'] ?? $status['publicly_available_post_id'] ?? $post->publish_id),
                'published_at' => now(),
                'error_message' => null,
            ]);
        } elseif ($state === 'FAILED') {
            $post->update([
                'status' => 'failed',
                'error_message' => $failReason ?: 'TikTok publish failed.',
            ]);
        }

        return $post->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function queryCreatorInfo(string $accessToken): array
    {
        $response = $this->contentRequest('post', '/post/publish/creator_info/query/', $accessToken, []);

        return is_array($response) ? $response : [];
    }

    public function ensureFreshToken(StoreSocialConnection $connection): StoreSocialConnection
    {
        if ($connection->token_expires_at === null || $connection->token_expires_at->isFuture()) {
            return $connection;
        }

        $refreshToken = (string) ($connection->metadata['refresh_token'] ?? '');
        if ($refreshToken === '') {
            return $connection;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->tokenUrl(), [
                'client_key' => config('tiktok.app_id'),
                'client_secret' => config('tiktok.app_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'Failed to refresh TikTok token.'));
        }

        $data = $response->json('data') ?? $response->json();
        if (! is_array($data) || ! isset($data['access_token'])) {
            throw new RuntimeException('TikTok token refresh failed.');
        }

        $metadata = is_array($connection->metadata) ? $connection->metadata : [];
        if (isset($data['refresh_token'])) {
            $metadata['refresh_token'] = $data['refresh_token'];
        }

        $connection->update([
            'page_access_token' => (string) $data['access_token'],
            'token_expires_at' => isset($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : now()->addHour(),
            'metadata' => $metadata,
        ]);

        return $connection->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->tokenUrl(), [
                'client_key' => config('tiktok.app_id'),
                'client_secret' => config('tiktok.app_secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'TikTok authorization failed.'));
        }

        $data = $response->json('data') ?? $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('TikTok authorization returned an invalid response.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function contentRequest(string $method, string $path, string $accessToken, array $payload): array
    {
        $url = rtrim((string) config('tiktok.content_api_base_url'), '/').'/'.ltrim($path, '/');

        $request = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(60);

        $response = strtolower($method) === 'get'
            ? $request->get($url, $payload)
            : $request->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->extractErrorMessage($response->json(), 'TikTok Content API request failed.'));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('TikTok Content API returned an invalid response.');
        }

        $error = $json['error'] ?? null;
        if (is_array($error) && ($error['code'] ?? '') !== 'ok' && ($error['code'] ?? '') !== '') {
            throw new RuntimeException((string) ($error['message'] ?? 'TikTok Content API error.'));
        }

        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $creator
     */
    private function defaultPrivacyLevel(array $creator): string
    {
        $options = $creator['privacy_level_options'] ?? [];
        if (is_array($options)) {
            if (in_array('PUBLIC_TO_EVERYONE', $options, true)) {
                return 'PUBLIC_TO_EVERYONE';
            }
            if (in_array('MUTUAL_FOLLOW_FRIENDS', $options, true)) {
                return 'MUTUAL_FOLLOW_FRIENDS';
            }
            if (in_array('SELF_ONLY', $options, true)) {
                return 'SELF_ONLY';
            }
        }

        return 'SELF_ONLY';
    }

    private function tokenUrl(): string
    {
        return rtrim((string) config('tiktok.content_api_base_url'), '/').'/oauth/token/';
    }

    private function stateCacheKey(string $state): string
    {
        return 'tiktok_creator_oauth:'.$state;
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

        $message = $payload['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $fallback;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('TikTok Content Posting is not configured.');
        }
    }
}
