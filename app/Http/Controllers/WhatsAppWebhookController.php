<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundCustomerMessage;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        return $this->handle($request);
    }

    private function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode === 'subscribe' && $this->whatsapp->verifyWebhookToken($token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Verification failed.', 403);
    }

    private function handle(Request $request): Response
    {
        $raw = $request->getContent();
        if (! $this->whatsapp->verifySignature($raw, $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature.', 403);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return response('Ignored.', 200);
        }

        foreach ($this->whatsapp->parseInboundMessages($payload) as $message) {
            $connection = $this->whatsapp->findConnectionByPhoneNumberId($message['phone_number_id']);
            if (! $connection) {
                continue;
            }

            ProcessInboundCustomerMessage::dispatch($connection->id, [
                'channel' => 'whatsapp',
                'external_user_id' => $message['from'],
                'external_user_name' => $message['profile_name'],
                'text' => $message['text'],
                'provider_message_id' => $message['message_id'],
            ]);
        }

        return response('OK', 200);
    }
}
