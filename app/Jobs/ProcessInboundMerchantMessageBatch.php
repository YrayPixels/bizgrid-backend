<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\MerchantWhatsAppInboundBuffer;
use App\Services\MerchantWhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundMerchantMessageBatch implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $phone,
        public int $version,
    ) {}

    public function handle(MerchantWhatsAppInboundBuffer $buffer, MerchantWhatsAppService $ops): void
    {
        if (! $buffer->isCurrentVersion($this->phone, $this->version)) {
            return;
        }

        $messages = $buffer->pull($this->phone);
        if ($messages === []) {
            return;
        }

        $ops->handleInboundBatch($messages);
    }
}
