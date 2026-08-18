<?php

namespace App\Jobs;

use App\Services\MerchantWhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundMerchantMessage implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     type: string,
     *     text: string,
     *     media_id: ?string,
     *     profile_name: ?string
     * }  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(MerchantWhatsAppService $ops): void
    {
        $ops->handleInbound($this->payload);
    }
}
