<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PollTikTokPublishStatus;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreSocialConnection;
use App\Support\UtmUrl;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Owns the whole life of a social post: draft → (approve) → scheduled →
 * published, plus editing, retrying and deleting along the way.
 *
 * The marketing agent only ever produces drafts. Publishing is a deliberate
 * merchant action taken through this service, so nothing reaches a real
 * audience without a human approving the copy.
 */
class SocialPostService
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly FacebookService $facebook,
        private readonly InstagramService $instagram,
        private readonly TikTokContentPostingService $tiktokContent,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(Store $store, array $attributes): SocialPost
    {
        $provider = (string) ($attributes['provider'] ?? 'facebook');

        return SocialPost::create([
            'store_id' => $store->id,
            'social_connection_id' => $attributes['social_connection_id']
                ?? $this->defaultConnectionFor($store, $provider)?->id,
            'provider' => $provider,
            'post_type' => (string) ($attributes['post_type'] ?? $this->defaultPostType($provider, $attributes)),
            'status' => self::STATUS_DRAFT,
            'message' => (string) ($attributes['message'] ?? ''),
            'link_url' => $this->nullableString($attributes['link_url'] ?? null),
            'image_url' => $this->nullableString($attributes['image_url'] ?? null),
            'video_url' => $this->nullableString($attributes['video_url'] ?? null),
            'metadata' => is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateDraft(SocialPost $post, array $attributes): SocialPost
    {
        if (! $post->isEditable()) {
            throw new RuntimeException('Only drafts, scheduled and failed posts can be edited.');
        }

        foreach (['message', 'link_url', 'image_url', 'video_url'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $post->{$field} = $field === 'message'
                    ? (string) $attributes[$field]
                    : $this->nullableString($attributes[$field]);
            }
        }

        if (array_key_exists('social_connection_id', $attributes)) {
            $post->social_connection_id = $attributes['social_connection_id'] === null
                ? null
                : (int) $attributes['social_connection_id'];
        }

        // Editing a failed post makes it a fresh attempt, not a 4th retry.
        if ($post->status === self::STATUS_FAILED) {
            $post->status = self::STATUS_DRAFT;
            $post->attempts = 0;
            $post->error_message = null;
        }

        $post->save();

        return $post->fresh();
    }

    /**
     * Park an approved post for a future publish run.
     */
    public function schedule(SocialPost $post, \DateTimeInterface $when, int $approvedByUserId): SocialPost
    {
        if (! $post->isEditable()) {
            throw new RuntimeException('This post can no longer be scheduled.');
        }

        if ($when <= now()) {
            throw new RuntimeException('Pick a time in the future to schedule this post.');
        }

        $this->assertPublishable($post);

        $post->update([
            'status' => self::STATUS_SCHEDULED,
            'scheduled_for' => $when,
            'approved_at' => now(),
            'approved_by_user_id' => $approvedByUserId,
            'error_message' => null,
            'attempts' => 0,
        ]);

        return $post->fresh();
    }

    public function unschedule(SocialPost $post): SocialPost
    {
        if ($post->status !== self::STATUS_SCHEDULED) {
            throw new RuntimeException('This post is not scheduled.');
        }

        $post->update([
            'status' => self::STATUS_DRAFT,
            'scheduled_for' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
        ]);

        return $post->fresh();
    }

    public function delete(SocialPost $post): void
    {
        if ($post->status === self::STATUS_PUBLISHED) {
            throw new RuntimeException('Published posts cannot be deleted from Bizgrid — remove them on the platform itself.');
        }

        $post->delete();
    }

    /**
     * Publish immediately on the merchant's explicit instruction.
     *
     * @return array{ok: bool, post: array<string, mixed>, error?: string, external_url?: ?string}
     */
    public function publishNow(SocialPost $post, ?int $approvedByUserId = null): array
    {
        if ($post->status === self::STATUS_PUBLISHED) {
            throw new RuntimeException('This post has already been published.');
        }

        if ($post->status === self::STATUS_PUBLISHING) {
            throw new RuntimeException('This post is already publishing.');
        }

        $post->update([
            'approved_at' => $post->approved_at ?? now(),
            'approved_by_user_id' => $post->approved_by_user_id ?? $approvedByUserId,
        ]);

        return $this->dispatchPublish($post);
    }

    /**
     * Publish every scheduled post whose time has come. Called by the
     * scheduler command; returns a per-post summary for the log line.
     *
     * @return array{published: int, failed: int}
     */
    public function publishDue(int $limit = 50): array
    {
        $due = SocialPost::query()
            ->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $published = 0;
        $failed = 0;

        foreach ($due as $post) {
            try {
                $result = $this->dispatchPublish($post);
                $result['ok'] ? $published++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                $post->update([
                    'status' => self::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                    'attempts' => (int) $post->attempts + 1,
                ]);
                Log::warning('Scheduled social post failed', [
                    'post_id' => $post->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }

    /**
     * @return array{ok: bool, post: array<string, mixed>, error?: string, external_url?: ?string}
     */
    private function dispatchPublish(SocialPost $post): array
    {
        try {
            $this->assertPublishable($post);
        } catch (\Throwable $e) {
            $post->update([
                'status' => self::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'post' => $this->format($post->fresh())];
        }

        $connection = $this->resolveConnection($post);

        $this->stampAttributionOnLink($post);

        $post->update([
            'status' => self::STATUS_PUBLISHING,
            'social_connection_id' => $connection->id,
            'attempts' => (int) $post->attempts + 1,
            'error_message' => null,
        ]);

        try {
            $result = match ($post->provider) {
                'facebook' => $this->publishFacebook($connection, $post->fresh() ?? $post),
                'instagram' => $this->instagram->publishImagePost(
                    $connection,
                    (string) $post->message,
                    (string) $post->image_url,
                ),
                'tiktok_creator' => $this->publishTikTok($connection, $post->fresh() ?? $post),
                default => throw new RuntimeException("Publishing to {$post->provider} is not supported."),
            };
        } catch (\Throwable $e) {
            $post->update([
                'status' => self::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $this->flagConnectionIfAuthFailure($connection, $e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage(), 'post' => $this->format($post->fresh())];
        }

        // TikTok publishes asynchronously — the poll job flips it to published.
        if ($post->provider === 'tiktok_creator') {
            return ['ok' => true, 'post' => $this->format($post->fresh()), 'external_url' => null];
        }

        $post->update([
            'status' => self::STATUS_PUBLISHED,
            'external_post_id' => $result['post_id'],
            'published_at' => now(),
            'error_message' => null,
            'metadata' => array_merge($post->metadata ?? [], [
                'external_url' => $result['url'] ?? null,
            ]),
        ]);

        return [
            'ok' => true,
            'post' => $this->format($post->fresh()),
            'external_url' => $result['url'] ?? null,
        ];
    }

    /**
     * Persist last-touch UTMs on the post's storefront link before it goes live
     * so visit → order attribution can join on utm_content=post_{id}.
     */
    private function stampAttributionOnLink(SocialPost $post): void
    {
        $link = $this->nullableString($post->link_url);
        if ($link === null) {
            return;
        }

        $store = $post->store;
        $campaign = $store?->slug ?: ($store?->name ?: 'store');

        $stamped = UtmUrl::merge($link, UtmUrl::forSocialPost(
            (string) $post->provider,
            (int) $post->id,
            (string) $campaign,
        ));

        if ($stamped !== $link) {
            $post->update(['link_url' => $stamped]);
        }
    }

    /**
     * @return array{post_id: string, url: ?string}
     */
    private function publishFacebook(StoreSocialConnection $connection, SocialPost $post): array
    {
        $imageUrl = $this->nullableString($post->image_url);

        // A photo post outperforms a bare link post for a product, so prefer
        // it whenever the draft carries an image.
        if ($imageUrl !== null) {
            return $this->facebook->publishPhotoPost(
                $connection,
                (string) $post->message,
                $imageUrl,
                $this->nullableString($post->link_url),
            );
        }

        return $this->facebook->publishFeedPost(
            $connection,
            (string) $post->message,
            $this->nullableString($post->link_url),
        );
    }

    /**
     * @return array{post_id: string, url: ?string}
     */
    private function publishTikTok(StoreSocialConnection $connection, SocialPost $post): array
    {
        $result = $this->tiktokContent->publishVideo(
            $connection,
            $post,
            (string) $post->video_url,
            (string) $post->message,
        );

        PollTikTokPublishStatus::dispatch($result['post']->id, $connection->id);

        return ['post_id' => (string) $result['publish_id'], 'url' => null];
    }

    /**
     * Every provider has a different minimum viable post; catching that here
     * turns an opaque Graph error into something the merchant can act on.
     */
    public function assertPublishable(SocialPost $post): void
    {
        if (trim((string) $post->message) === '') {
            throw new RuntimeException('Add some copy before publishing.');
        }

        if ($post->provider === 'instagram' && $this->nullableString($post->image_url) === null) {
            throw new RuntimeException('Instagram posts need an image.');
        }

        if ($post->provider === 'tiktok_creator' && $this->nullableString($post->video_url) === null) {
            throw new RuntimeException('TikTok posts need a public video URL.');
        }

        if ((int) $post->attempts >= self::MAX_ATTEMPTS) {
            throw new RuntimeException('This post failed too many times. Edit it before trying again.');
        }
    }

    private function resolveConnection(SocialPost $post): StoreSocialConnection
    {
        $connection = $post->social_connection_id
            ? StoreSocialConnection::query()
                ->where('store_id', $post->store_id)
                ->find($post->social_connection_id)
            : null;

        if (! $connection instanceof StoreSocialConnection) {
            $store = $post->store;
            $connection = $store ? $this->defaultConnectionFor($store, (string) $post->provider) : null;
        }

        if (! $connection instanceof StoreSocialConnection) {
            throw new RuntimeException($this->connectPrompt((string) $post->provider));
        }

        if (! $connection->isUsable()) {
            throw new RuntimeException(
                $connection->invalid_reason
                    ?: 'Your '.$this->providerLabel((string) $post->provider).' connection expired. Reconnect it to keep publishing.',
            );
        }

        return $connection;
    }

    private function defaultConnectionFor(Store $store, string $provider): ?StoreSocialConnection
    {
        return $store->socialConnections()
            ->where('provider', $provider === 'tiktok_creator' ? 'tiktok_creator' : $provider)
            ->latest()
            ->first();
    }

    /**
     * Meta returns OAuthException for a dead token; mark the connection so the
     * UI can prompt a reconnect instead of failing post after post.
     */
    private function flagConnectionIfAuthFailure(StoreSocialConnection $connection, string $error): void
    {
        $needle = strtolower($error);

        $isAuthError = str_contains($needle, 'access token')
            || str_contains($needle, 'oauthexception')
            || str_contains($needle, 'session has expired')
            || str_contains($needle, 'permission');

        if (! $isAuthError) {
            return;
        }

        $connection->update([
            'status' => 'invalid',
            'invalid_reason' => 'Reconnect your '.$this->providerLabel($connection->provider).' account — the saved login is no longer valid.',
            'last_checked_at' => now(),
        ]);
    }

    private function connectPrompt(string $provider): string
    {
        return match ($provider) {
            'facebook' => 'Connect a Facebook Page before publishing.',
            'instagram' => 'Connect an Instagram Business account before publishing.',
            'tiktok_creator' => 'Connect your TikTok creator account before publishing.',
            default => 'Connect this channel before publishing.',
        };
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'facebook', 'facebook_user' => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok_creator', 'tiktok' => 'TikTok',
            'whatsapp' => 'WhatsApp',
            default => ucfirst($provider),
        };
    }

    private function defaultPostType(string $provider, array $attributes): string
    {
        if ($provider === 'tiktok_creator') {
            return 'video';
        }

        return filled($attributes['image_url'] ?? null) ? 'image' : 'text';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    public function format(SocialPost $post): array
    {
        return [
            'id' => (string) $post->id,
            'provider' => $post->provider,
            'post_type' => $post->post_type ?? 'text',
            'status' => $post->status,
            'message' => $post->message,
            'link_url' => $post->link_url,
            'image_url' => $post->image_url,
            'video_url' => $post->video_url,
            'external_post_id' => $post->external_post_id,
            'external_url' => $post->metadata['external_url'] ?? null,
            'publish_id' => $post->publish_id,
            'scheduled_for' => $post->scheduled_for?->toIso8601String(),
            'approved_at' => $post->approved_at?->toIso8601String(),
            'insights' => $post->insights,
            'insights_synced_at' => $post->insights_synced_at?->toIso8601String(),
            'attempts' => (int) $post->attempts,
            'editable' => $post->isEditable(),
            'error_message' => $post->error_message,
            'published_at' => $post->published_at?->toIso8601String(),
            'created_at' => $post->created_at?->toIso8601String(),
        ];
    }
}
