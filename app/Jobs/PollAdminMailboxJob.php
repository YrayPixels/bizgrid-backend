<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AdminMailboxImapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PollAdminMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(AdminMailboxImapService $imap): void
    {
        try {
            $result = $imap->pollAll(75);
            Log::info('admin.email.imap_poll', $result);
        } catch (\Throwable $e) {
            Log::warning('admin.email.imap_poll_job_failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
