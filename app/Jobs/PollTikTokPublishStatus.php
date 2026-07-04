<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Models\StoreSocialConnection;
use App\Services\TikTokContentPostingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollTikTokPublishStatus implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $postId,
        public int $connectionId,
        public int $attempt = 1,
    ) {}

    public function handle(TikTokContentPostingService $tiktok): void
    {
        $post = SocialPost::query()->find($this->postId);
        $connection = StoreSocialConnection::query()->find($this->connectionId);

        if (! $post instanceof SocialPost || ! $connection instanceof StoreSocialConnection) {
            return;
        }

        if (in_array($post->status, ['published', 'failed'], true)) {
            return;
        }

        $post = $tiktok->refreshPublishStatus($connection, $post);

        if (in_array($post->status, ['published', 'failed'], true)) {
            return;
        }

        if ($this->attempt >= 12) {
            $post->update([
                'status' => 'failed',
                'error_message' => 'TikTok publish timed out while processing.',
            ]);

            return;
        }

        self::dispatch($this->postId, $this->connectionId, $this->attempt + 1)
            ->delay(now()->addSeconds(10));
    }
}
