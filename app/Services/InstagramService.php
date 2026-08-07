<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreSocialConnection;
use RuntimeException;

/**
 * Instagram Business publishing. Rides on the Facebook connection: an IG
 * Business account is always attached to a Page, and the Page token is what
 * the IG Graph endpoints authenticate with — so there is no separate OAuth.
 */
class InstagramService
{
    public const PROVIDER = 'instagram';

    public function __construct(
        private readonly MetaGraphClient $graph,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('facebook.app_id'))
            && filled(config('facebook.app_secret'))
            && (bool) config('facebook.instagram_enabled');
    }

    /**
     * @return array{image_required: bool, max_caption_length: int, video_supported: bool}
     */
    public function capabilities(): array
    {
        return [
            'image_required' => true,
            'max_caption_length' => 2200,
            'video_supported' => false,
        ];
    }

    public function findConnection(int $storeId): ?StoreSocialConnection
    {
        return StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', self::PROVIDER)
            ->latest()
            ->first();
    }

    /**
     * Walk the store's connected Pages and persist any linked IG Business
     * account as its own connection row.
     *
     * @return list<StoreSocialConnection>
     */
    public function syncFromFacebookPages(Store $store): array
    {
        $this->assertConfigured();

        $pages = $store->socialConnections()->where('provider', 'facebook')->get();

        if ($pages->isEmpty()) {
            throw new RuntimeException('Connect a Facebook Page first — Instagram publishing runs through the linked Page.');
        }

        $connections = [];

        foreach ($pages as $page) {
            $account = $this->fetchLinkedAccount($page);

            if ($account === null) {
                continue;
            }

            $connections[] = StoreSocialConnection::updateOrCreate(
                [
                    'store_id' => $store->id,
                    'provider' => self::PROVIDER,
                    'page_id' => (string) $account['id'],
                ],
                [
                    'provider_account_id' => $page->page_id,
                    'page_name' => (string) ($account['username'] ?? 'Instagram'),
                    // IG Graph calls authenticate with the Page token.
                    'page_access_token' => (string) $page->page_access_token,
                    'token_expires_at' => $page->token_expires_at,
                    'status' => 'active',
                    'invalid_reason' => null,
                    'last_checked_at' => now(),
                    'metadata' => [
                        'facebook_page_id' => $page->page_id,
                        'facebook_page_name' => $page->page_name,
                        'followers_count' => $account['followers_count'] ?? null,
                        'profile_picture_url' => $account['profile_picture_url'] ?? null,
                    ],
                ],
            );
        }

        if ($connections === []) {
            throw new RuntimeException('No Instagram Business account is linked to your Facebook Page. Link one in Meta Business Suite, then try again.');
        }

        return $connections;
    }

    public function disconnect(int $storeId): void
    {
        StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', self::PROVIDER)
            ->delete();
    }

    /**
     * Two-step publish: build a media container, then publish it. Instagram
     * has no text-only format, so an image is mandatory.
     *
     * @return array{post_id: string, url: ?string}
     */
    public function publishImagePost(StoreSocialConnection $connection, string $caption, string $imageUrl): array
    {
        if (trim($imageUrl) === '') {
            throw new RuntimeException('Instagram posts require an image.');
        }

        $token = (string) $connection->page_access_token;

        $container = $this->graph->post("/{$connection->page_id}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ], $token);

        $creationId = (string) ($container['id'] ?? '');

        if ($creationId === '') {
            throw new RuntimeException('Instagram did not return a media container id.');
        }

        $published = $this->graph->post("/{$connection->page_id}/media_publish", [
            'creation_id' => $creationId,
        ], $token);

        $mediaId = (string) ($published['id'] ?? '');

        if ($mediaId === '') {
            throw new RuntimeException('Instagram did not return a media id.');
        }

        return [
            'post_id' => $mediaId,
            'url' => $this->fetchPermalink($connection, $mediaId),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function fetchPostInsights(StoreSocialConnection $connection, string $mediaId): array
    {
        $token = (string) $connection->page_access_token;

        $media = $this->graph->get("/{$mediaId}", [
            'fields' => 'permalink,like_count,comments_count',
        ], $token);

        $metrics = [];

        try {
            $insights = $this->graph->get("/{$mediaId}/insights", [
                'metric' => 'reach,saved',
            ], $token);

            foreach ($insights['data'] ?? [] as $entry) {
                if (is_array($entry) && isset($entry['name'])) {
                    $metrics[(string) $entry['name']] = (int) ($entry['values'][0]['value'] ?? 0);
                }
            }
        } catch (\Throwable) {
            // Insights lag behind publish by a few minutes and 400 until ready.
        }

        return [
            'permalink_url' => $media['permalink'] ?? null,
            'reach' => $metrics['reach'] ?? null,
            'saved' => $metrics['saved'] ?? null,
            'reactions' => isset($media['like_count']) ? (int) $media['like_count'] : null,
            'comments' => isset($media['comments_count']) ? (int) $media['comments_count'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLinkedAccount(StoreSocialConnection $page): ?array
    {
        try {
            $response = $this->graph->get("/{$page->page_id}", [
                'fields' => 'instagram_business_account{id,username,followers_count,profile_picture_url}',
            ], (string) $page->page_access_token);
        } catch (\Throwable) {
            return null;
        }

        $account = $response['instagram_business_account'] ?? null;

        return is_array($account) && filled($account['id'] ?? null) ? $account : null;
    }

    private function fetchPermalink(StoreSocialConnection $connection, string $mediaId): ?string
    {
        try {
            $response = $this->graph->get("/{$mediaId}", [
                'fields' => 'permalink',
            ], (string) $connection->page_access_token);

            return is_string($response['permalink'] ?? null) ? $response['permalink'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Instagram publishing is not enabled on this platform yet.');
        }
    }
}
