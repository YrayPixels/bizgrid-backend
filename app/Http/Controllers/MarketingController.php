<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Store;
use App\Services\AbandonedRecoveryService;
use App\Services\FacebookService;
use App\Services\MarketingService;
use App\Services\TikTokContentPostingService;
use App\Services\TikTokMessagingService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly MarketingService $marketing,
        private readonly FacebookService $facebook,
        private readonly WhatsAppService $whatsapp,
        private readonly TikTokMessagingService $tiktok,
        private readonly TikTokContentPostingService $tiktokCreator,
        private readonly AbandonedRecoveryService $abandonedRecovery,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json($this->marketing->marketingStatus($store));
    }

    public function connectFacebook(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        if (! $this->facebook->isConfigured()) {
            return response()->json([
                'message' => 'Facebook integration is not configured on this platform.',
            ], 503);
        }

        $auth = $this->facebook->buildAuthorizationUrl($store, (int) $request->user()->id);

        return response()->json([
            'authorization_url' => $auth['url'],
            'state' => $auth['state'],
        ]);
    }

    public function facebookCallback(Request $request): RedirectResponse
    {
        $frontendBase = rtrim((string) config('storehause.app_url', config('app.url')), '/');
        $redirectBase = $frontendBase.'/admin/marketing';

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return redirect()->away($redirectBase.'?facebook=error&message='.urlencode($error));
        }

        $payload = $this->facebook->consumeOAuthState($state);
        if (! is_array($payload)) {
            return redirect()->away($redirectBase.'?facebook=error&message='.urlencode('Invalid or expired Facebook login state.'));
        }

        $store = Store::query()->find($payload['store_id'] ?? null);
        if (! $store instanceof Store) {
            return redirect()->away($redirectBase.'?facebook=error&message='.urlencode('Store not found.'));
        }

        try {
            $this->facebook->connectStoreFromOAuthCode($store, $code);
        } catch (\Throwable $e) {
            return redirect()->away($redirectBase.'?facebook=error&message='.urlencode($e->getMessage()));
        }

        return redirect()->away($redirectBase.'?facebook=connected');
    }

    public function disconnectFacebook(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'connection_id' => 'nullable|integer',
        ]);

        $this->facebook->disconnect(
            $store,
            isset($data['connection_id']) ? (int) $data['connection_id'] : null,
        );

        return response()->json([
            'message' => 'Facebook disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'message' => 'required|string|max:4000',
            'recent_messages' => 'nullable|array|max:20',
            'recent_messages.*.role' => 'required_with:recent_messages|in:user,assistant',
            'recent_messages.*.content' => 'required_with:recent_messages|string|max:4000',
        ]);

        $result = $this->marketing->handleChatTurn(
            $store,
            $data['message'],
            is_array($data['recent_messages'] ?? null) ? $data['recent_messages'] : [],
        );

        if (! is_array($result)) {
            return response()->json([
                'message' => 'Marketing agent is unavailable right now.',
            ], 503);
        }

        return response()->json([
            'assistant_message' => $result['assistant_message'],
            'tool_calls' => $result['tool_calls'],
            'tool_results' => $result['tool_results'],
            'post' => $result['post'] ?? null,
            'status' => $this->marketing->marketingStatus($store),
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'posts' => $this->marketing->listPosts($store),
        ]);
    }

    public function connectWhatsApp(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'phone_number_id' => 'required|string|max:64',
            'display_phone_number' => 'required|string|max:32',
            'access_token' => 'required|string|max:5000',
            'waba_id' => 'nullable|string|max:64',
        ]);

        try {
            $this->whatsapp->connectStoreChannel($store->id, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'WhatsApp connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectWhatsApp(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->whatsapp->disconnect($store->id);

        return response()->json([
            'message' => 'WhatsApp disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function connectTikTok(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'business_account_id' => 'required|string|max:128',
            'account_name' => 'nullable|string|max:160',
            'access_token' => 'required|string|max:5000',
        ]);

        try {
            $this->tiktok->connectStoreChannel($store->id, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'TikTok Business account connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectTikTok(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->tiktok->disconnect($store->id);

        return response()->json([
            'message' => 'TikTok disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function connectTikTokCreator(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        if (! $this->tiktokCreator->isConfigured()) {
            return response()->json([
                'message' => 'TikTok Content Posting is not configured on this platform.',
            ], 503);
        }

        $auth = $this->tiktokCreator->buildAuthorizationUrl($store->id);

        return response()->json([
            'authorization_url' => $auth['url'],
            'state' => $auth['state'],
        ]);
    }

    public function tiktokCreatorCallback(Request $request): RedirectResponse
    {
        $frontendBase = rtrim((string) config('storehause.app_url', config('app.url')), '/');
        $redirectBase = $frontendBase.'/admin/marketing';

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');

        if ($error !== '') {
            return redirect()->away($redirectBase.'?tiktok_creator=error&message='.urlencode($error));
        }

        $payload = $this->tiktokCreator->consumeOAuthState($state);
        if (! is_array($payload)) {
            return redirect()->away($redirectBase.'?tiktok_creator=error&message='.urlencode('Invalid or expired TikTok login state.'));
        }

        $store = Store::query()->find($payload['store_id'] ?? null);
        if (! $store instanceof Store) {
            return redirect()->away($redirectBase.'?tiktok_creator=error&message='.urlencode('Store not found.'));
        }

        try {
            $this->tiktokCreator->connectStoreFromOAuthCode($store->id, $code);
        } catch (\Throwable $e) {
            return redirect()->away($redirectBase.'?tiktok_creator=error&message='.urlencode($e->getMessage()));
        }

        return redirect()->away($redirectBase.'?tiktok_creator=connected');
    }

    public function disconnectTikTokCreator(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->tiktokCreator->disconnect($store->id);

        return response()->json([
            'message' => 'TikTok creator account disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function publishTikTokVideo(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'video_url' => 'required|url|max:2048',
            'caption' => 'required|string|max:2200',
        ]);

        $result = $this->marketing->publishTikTokVideo(
            $store,
            $data['video_url'],
            $data['caption'],
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Failed to publish TikTok video.',
                'post' => $result['post'] ?? null,
                ...$this->marketing->marketingStatus($store),
            ], 422);
        }

        return response()->json([
            'message' => 'TikTok video is publishing.',
            'post' => $result['post'] ?? null,
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function updateMessagingSettings(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'whatsapp_auto_reply_enabled' => 'nullable|boolean',
            'tiktok_auto_reply_enabled' => 'nullable|boolean',
        ]);

        $this->marketing->updateMessagingSettings($store, $data);

        return response()->json([
            'message' => 'Messaging settings updated.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'conversations' => $this->marketing->marketingStatus($store)['recent_conversations'] ?? [],
        ]);
    }

    public function abandoned(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min((int) $request->get('per_page', 20), 50);

        return response()->json($this->abandonedRecovery->listAbandoned($store, $perPage, $page));
    }

    public function draftAbandonedMessage(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'source_type' => 'required|string|in:checkout,cart',
            'source_id' => 'required|integer|min:1',
            'channel' => 'nullable|string|in:email,whatsapp',
        ]);

        $draft = $this->abandonedRecovery->draftRecoveryMessage(
            $store,
            $data['source_type'],
            (int) $data['source_id'],
            $data['channel'] ?? 'email',
        );

        return response()->json([
            'draft' => $draft,
        ]);
    }

    public function sendAbandonedMessage(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'source_type' => 'required|string|in:checkout,cart',
            'source_id' => 'required|integer|min:1',
            'channel' => 'required|string|in:email,whatsapp',
            'message' => 'required|string|max:4000',
            'subject' => 'nullable|string|max:160',
        ]);

        try {
            $result = $this->abandonedRecovery->sendRecoveryMessage(
                $store,
                $data['source_type'],
                (int) $data['source_id'],
                $data['channel'],
                $data['message'],
                $data['subject'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
