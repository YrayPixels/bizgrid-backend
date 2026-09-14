<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PollAdminMailboxJob;
use App\Services\AdminMailboxImapService;
use App\Services\PlatformMailConfigService;
use Illuminate\Console\Command;

class PollAdminMailboxCommand extends Command
{
    protected $signature = 'storehause:poll-admin-mailbox {--sync : Run inline instead of queueing}';

    protected $description = 'Poll IMAP mailboxes configured in admin mail providers';

    public function handle(PlatformMailConfigService $mailConfig, AdminMailboxImapService $imap): int
    {
        if (! $mailConfig->hasImapMailboxConfigured()) {
            $this->info('No IMAP-enabled mail providers configured.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $result = $imap->pollAll(75);
            $this->info("Polled {$result['polled']} mailbox(es); imported {$result['imported']} message(s).");

            return self::SUCCESS;
        }

        PollAdminMailboxJob::dispatch();
        $this->info('Queued IMAP mailbox poll.');

        return self::SUCCESS;
    }
}
