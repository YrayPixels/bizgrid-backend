<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SocialPostService;
use Illuminate\Console\Command;

class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'storehause:publish-scheduled-posts {--limit=50 : Maximum posts to publish in one run}';

    protected $description = 'Publish social posts whose scheduled time has arrived';

    public function handle(SocialPostService $posts): int
    {
        $result = $posts->publishDue(max(1, (int) $this->option('limit')));

        $this->info("Published {$result['published']} post(s), {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
