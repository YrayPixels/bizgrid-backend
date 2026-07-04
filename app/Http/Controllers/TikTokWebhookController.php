<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundCustomerMessage;
use App\Services\TikTokMessagingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TikTokWebhookController extends Controller
{
    public function __construct(
        private readonly TikTokMessagingService $tiktok,
    ) {}

    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();
        if (! is_array($payload)) {
            return response('Ignored.', 200);
        }

        foreach ($this->tiktok->parseInboundMessages($payload) as $message) {
            $connection = $this->tiktok->findConnectionByBusinessAccountId($message['business_account_id']);
            if (! $connection) {
                continue;
            }

            ProcessInboundCustomerMessage::dispatch($connection->id, [
                'channel' => 'tiktok',
                'external_user_id' => $message['sender_id'],
                'external_user_name' => $message['sender_name'],
                'text' => $message['text'],
                'provider_message_id' => $message['message_id'],
                'metadata' => [
                    'tiktok_conversation_id' => $message['sender_id'],
                ],
            ]);
        }

        return response('OK', 200);
    }
}
