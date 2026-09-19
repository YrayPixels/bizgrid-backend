<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdminEmailMessage;
use App\Services\AdminEmailSendService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAdminEmailMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public int $uniqueFor = 3600;

    public function __construct(public int $messageId) {}

    public function uniqueId(): string
    {
        return (string) $this->messageId;
    }

    public function handle(AdminEmailSendService $sender): void
    {
        $sender->sendQueued($this->messageId);
    }

    public function failed(?\Throwable $exception): void
    {
        AdminEmailMessage::query()
            ->whereKey($this->messageId)
            ->where('status', 'queued')
            ->update([
                'status' => 'failed',
                'error' => $exception?->getMessage() ?: 'Queued send failed.',
            ]);
    }
}
