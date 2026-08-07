<?php

declare(strict_types=1);

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\SocialPost;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Log;

/**
 * Reads the comments on a published post and classifies how the audience
 * reacted. Every classification is an LLM call, so this is deliberately
 * conservative: it only runs on posts with enough comments to be meaningful,
 * and never re-runs unless the comment count actually moved.
 */
class PostSentimentService
{
    /** Below this, a "sentiment" reading is noise dressed up as insight. */
    public const MIN_COMMENTS = 3;

    private const MAX_COMMENTS = 40;

    public function __construct(
        private readonly AgentRegistry $registry,
        private readonly MetaGraphClient $graph,
        private readonly MerchantUsageEnforcementService $enforcement,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function analyze(SocialPost $post, bool $meterCredits = true): ?array
    {
        if ($post->status !== SocialPostService::STATUS_PUBLISHED || ! filled($post->external_post_id)) {
            return null;
        }

        $connection = $post->social_connection_id
            ? StoreSocialConnection::find($post->social_connection_id)
            : null;

        if (! $connection instanceof StoreSocialConnection || ! $connection->isUsable()) {
            return null;
        }

        $comments = $this->fetchComments($post, $connection);

        if (count($comments) < self::MIN_COMMENTS) {
            // Record the attempt so the sync loop stops reconsidering this post
            // every hour, but keep the result honest rather than inventing one.
            $post->update([
                'sentiment' => [
                    'label' => null,
                    'score' => null,
                    'summary' => 'Not enough comments yet to read the room.',
                    'sample_size' => count($comments),
                ],
                'sentiment_synced_at' => now(),
            ]);

            return null;
        }

        $merchant = $post->store?->merchant;

        if ($meterCredits && $merchant) {
            // Sentiment is an LLM call like any other, so it draws on the same
            // plan allowance. A store out of credits simply skips the read.
            try {
                $this->enforcement->assertCanUseAi($merchant);
            } catch (\Throwable) {
                return null;
            }
        }

        $result = $this->registry->execute('sentiment-agent', [
            'post_message' => (string) $post->message,
            'comments' => $comments,
        ]);

        if (! is_array($result)) {
            $post->update(['sentiment_synced_at' => now()]);

            return null;
        }

        if ($meterCredits && $merchant) {
            $this->enforcement->consumeAiCredit($merchant);
        }

        $sentiment = [
            'label' => $result['label'],
            'score' => $result['score'],
            'summary' => $result['summary'],
            'sample_size' => count($comments),
        ];

        $post->update([
            'sentiment' => $sentiment,
            'sentiment_synced_at' => now(),
        ]);

        return $sentiment;
    }

    /**
     * Posts worth spending a classification on: published, commented on, and
     * either never read or materially changed since the last read.
     *
     * @return \Illuminate\Support\Collection<int, SocialPost>
     */
    public function pendingPosts(int $limit = 20)
    {
        return SocialPost::query()
            ->where('status', SocialPostService::STATUS_PUBLISHED)
            ->whereNotNull('external_post_id')
            ->whereIn('provider', ['facebook', 'instagram'])
            ->where('published_at', '>=', now()->subDays(30))
            ->where(function ($query) {
                $query->whereNull('sentiment_synced_at')
                    ->orWhere('sentiment_synced_at', '<=', now()->subHours(24));
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            // Only bother where the insights sync has already seen comments —
            // saves a Graph round trip per empty post.
            ->filter(fn (SocialPost $post): bool => (int) ($post->insights['comments'] ?? 0) >= self::MIN_COMMENTS);
    }

    /**
     * @return list<string>
     */
    private function fetchComments(SocialPost $post, StoreSocialConnection $connection): array
    {
        try {
            $response = $this->graph->get("/{$post->external_post_id}/comments", [
                'fields' => 'message,like_count',
                'limit' => self::MAX_COMMENTS,
                'order' => 'reverse_chronological',
            ], (string) $connection->page_access_token);
        } catch (\Throwable $e) {
            Log::info('Comment fetch failed for sentiment', [
                'post_id' => $post->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        $comments = [];

        foreach ($response['data'] ?? [] as $comment) {
            if (! is_array($comment)) {
                continue;
            }

            $message = $comment['message'] ?? null;

            if (is_string($message) && trim($message) !== '') {
                $comments[] = trim($message);
            }
        }

        return $comments;
    }
}
