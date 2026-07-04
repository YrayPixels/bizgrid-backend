<?php

namespace App\Jobs;

use App\Models\StoreSocialConnection;
use App\Services\InboundMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundCustomerMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $connectionId,
        public array $payload,
    ) {}

    public function handle(InboundMessagingService $messaging): void
    {
        $connection = StoreSocialConnection::query()->find($this->connectionId);
        if (! $connection) {
            return;
        }

        $messaging->handleInbound($connection, $this->payload);
    }
}
