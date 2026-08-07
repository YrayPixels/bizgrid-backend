<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\SocialPost;
use App\Models\Store;
use App\Models\StoreAdCampaign;
use App\Services\AbandonedRecoveryService;
use App\Services\FacebookService;
use App\Services\InstagramService;
use App\Services\MarketingService;
use App\Services\MerchantUsageEnforcementService;
use App\Services\MetaAdsService;
use App\Services\SocialPostService;
use App\Services\TikTokContentPostingService;
use App\Services\TikTokMessagingService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MarketingController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly MarketingService $marketing,
        private readonly FacebookService $facebook,
        private readonly InstagramService $instagram,
        private readonly WhatsAppService $whatsapp,
        private readonly TikTokMessagingService $tiktok,
        private readonly TikTokContentPostingService $tiktokCreator,
        private readonly AbandonedRecoveryService $abandonedRecovery,
        private readonly SocialPostService $posts,
        private readonly MetaAdsService $ads,
        private readonly MerchantUsageEnforcementService $enforcement,
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

        $this->invalidateStoreApiCache($store);

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

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Facebook disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function connectInstagram(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        if (! $this->instagram->isConfigured()) {
            return response()->json([
                'message' => 'Instagram publishing is not enabled on this platform yet.',
            ], 503);
        }

        try {
            $this->instagram->syncFromFacebookPages($store);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Instagram account connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectInstagram(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->instagram->disconnect($store->id);

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Instagram disconnected.',
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

        // Marketing chat is a full LLM turn like every other agent surface, so
        // it draws on the same plan limits rather than running free.
        $merchant = $this->enforcement->merchantForUser((int) $request->user()->id);
        if ($merchant) {
            $this->enforcement->assertCanUseAi($merchant);
            $this->enforcement->consumeAiCredit($merchant);
        }

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

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'assistant_message' => $result['assistant_message'],
            'tool_calls' => $result['tool_calls'],
            'tool_results' => $result['tool_results'],
            'post' => $result['post'] ?? null,
            'campaign' => $result['campaign'] ?? null,
            'status' => $this->marketing->marketingStatus($store),
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'status' => 'nullable|string|in:draft,scheduled,publishing,published,failed',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'posts' => $this->marketing->listPosts(
                $store,
                (int) ($data['limit'] ?? 20),
                $data['status'] ?? null,
            ),
        ]);
    }

    public function scheduledPosts(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'posts' => $this->marketing->listScheduled($store),
        ]);
    }

    public function createPost(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'provider' => 'required|string|in:facebook,instagram,tiktok_creator',
            'message' => 'required|string|max:4000',
            'link_url' => 'nullable|url|max:2048',
            'image_url' => 'nullable|url|max:2048',
            'video_url' => 'nullable|url|max:2048',
            'social_connection_id' => 'nullable|integer',
        ]);

        $post = $this->posts->createDraft($store, $data);

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Draft saved.',
            'post' => $this->posts->format($post),
        ], 201);
    }

    public function updatePost(Request $request, string $postId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $post = $this->findOwnedPost($store, $postId);

        $data = $request->validate([
            'message' => 'nullable|string|max:4000',
            'link_url' => 'nullable|url|max:2048',
            'image_url' => 'nullable|url|max:2048',
            'video_url' => 'nullable|url|max:2048',
            'social_connection_id' => 'nullable|integer',
        ]);

        try {
            $updated = $this->posts->updateDraft($post, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Draft updated.',
            'post' => $this->posts->format($updated),
        ]);
    }

    public function deletePost(Request $request, string $postId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $post = $this->findOwnedPost($store, $postId);

        try {
            $this->posts->delete($post);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json(['message' => 'Post deleted.']);
    }

    public function publishPost(Request $request, string $postId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $post = $this->findOwnedPost($store, $postId);

        try {
            $result = $this->posts->publishNow($post, (int) $request->user()->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => $result['error'] ?? 'Failed to publish.',
                'post' => $result['post'],
                ...$this->marketing->marketingStatus($store),
            ], 422);
        }

        return response()->json([
            'message' => $post->provider === 'tiktok_creator' ? 'Publishing to TikTok.' : 'Post published.',
            'post' => $result['post'],
            'external_url' => $result['external_url'] ?? null,
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function schedulePost(Request $request, string $postId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $post = $this->findOwnedPost($store, $postId);

        $data = $request->validate([
            'scheduled_for' => 'required|date|after:now',
        ]);

        try {
            $scheduled = $this->posts->schedule(
                $post,
                Carbon::parse($data['scheduled_for']),
                (int) $request->user()->id,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Post scheduled.',
            'post' => $this->posts->format($scheduled),
        ]);
    }

    public function unschedulePost(Request $request, string $postId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $post = $this->findOwnedPost($store, $postId);

        try {
            $draft = $this->posts->unschedule($post);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Post moved back to drafts.',
            'post' => $this->posts->format($draft),
        ]);
    }

    public function adAccounts(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        try {
            $accounts = $this->ads->listAvailableAdAccounts($store);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['accounts' => $accounts]);
    }

    public function selectAdAccount(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $request->validate([
            'ad_account_id' => 'required|string|max:64',
        ]);

        try {
            $this->ads->selectAdAccount($store, $data['ad_account_id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Ad account connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectAdAccount(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->ads->disconnect($store->id);

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Ad account disconnected.',
            ...$this->marketing->marketingStatus($store),
        ]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        $campaigns = StoreAdCampaign::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', 'archived')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (StoreAdCampaign $campaign): array => $this->ads->format($campaign))
            ->all();

        return response()->json(['campaigns' => $campaigns]);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $data = $this->validateCampaignInput($request, true);

        $campaign = $this->ads->createDraft($store, $data);

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Campaign draft saved. Nothing is spent until you launch it.',
            'campaign' => $this->ads->format($campaign),
        ], 201);
    }

    public function updateCampaign(Request $request, string $campaignId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $campaign = $this->findOwnedCampaign($store, $campaignId);
        $data = $this->validateCampaignInput($request, false);

        try {
            $updated = $this->ads->updateDraft($campaign, $data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Campaign updated.',
            'campaign' => $this->ads->format($updated),
        ]);
    }

    public function launchCampaign(Request $request, string $campaignId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $campaign = $this->findOwnedCampaign($store, $campaignId);

        try {
            $launched = $this->ads->launch($campaign, (int) $request->user()->id);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'Campaign created on Meta and paused. Turn it on when you are ready to spend.',
            'campaign' => $this->ads->format($launched),
        ]);
    }

    public function setCampaignState(Request $request, string $campaignId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $campaign = $this->findOwnedCampaign($store, $campaignId);

        $data = $request->validate([
            'active' => 'required|boolean',
        ]);

        try {
            $updated = $this->ads->setRunningState($campaign, (bool) $data['active']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => $data['active'] ? 'Campaign is now running.' : 'Campaign paused.',
            'campaign' => $this->ads->format($updated),
        ]);
    }

    public function archiveCampaign(Request $request, string $campaignId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $campaign = $this->findOwnedCampaign($store, $campaignId);

        $this->ads->archive($campaign);

        $this->invalidateMarketingApiCache($store);

        return response()->json(['message' => 'Campaign archived.']);
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

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'WhatsApp connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectWhatsApp(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->whatsapp->disconnect($store->id);

        $this->invalidateMarketingApiCache($store);

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

        $this->invalidateMarketingApiCache($store);

        return response()->json([
            'message' => 'TikTok Business account connected.',
            ...$this->marketing->marketingStatus($store->fresh()),
        ]);
    }

    public function disconnectTikTok(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->tiktok->disconnect($store->id);

        $this->invalidateMarketingApiCache($store);

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

        $this->invalidateStoreApiCache($store);

        return redirect()->away($redirectBase.'?tiktok_creator=connected');
    }

    public function disconnectTikTokCreator(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $this->tiktokCreator->disconnect($store->id);

        $this->invalidateMarketingApiCache($store);

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

        $this->invalidateMarketingApiCache($store);

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

        $this->invalidateMarketingApiCache($store);

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

        // Drafting a recovery message is an LLM call, so it is metered too.
        $merchant = $this->enforcement->merchantForUser((int) $request->user()->id);
        if ($merchant) {
            $this->enforcement->assertCanUseAi($merchant);
            $this->enforcement->consumeAiCredit($merchant);
        }

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

        $this->invalidateMarketingApiCache($store);

        return response()->json($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCampaignInput(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => $required.'|string|max:160',
            'objective' => 'nullable|string|in:OUTCOME_TRAFFIC,OUTCOME_AWARENESS,OUTCOME_ENGAGEMENT',
            'daily_budget_minor' => $required.'|integer|min:0',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'targeting' => 'nullable|array',
            'targeting.countries' => 'nullable|array|max:25',
            'targeting.countries.*' => 'string|size:2',
            'targeting.age_min' => 'nullable|integer|min:18|max:65',
            'targeting.age_max' => 'nullable|integer|min:18|max:65',
            'targeting.genders' => 'nullable|array|max:2',
            'targeting.genders.*' => 'integer|in:1,2',
            'targeting.cities' => 'nullable|array|max:25',
            'targeting.interests' => 'nullable|array|max:25',
            'creative' => $required.'|array',
            'creative.message' => $required.'|string|max:2000',
            'creative.headline' => 'nullable|string|max:255',
            'creative.description' => 'nullable|string|max:255',
            'creative.link_url' => $required.'|url|max:2048',
            'creative.image_url' => 'nullable|url|max:2048',
            'creative.call_to_action' => 'nullable|string|in:SHOP_NOW,LEARN_MORE,ORDER_NOW,SIGN_UP,CONTACT_US,MESSAGE_PAGE',
        ]);
    }

    private function findOwnedPost(Store $store, string $postId): SocialPost
    {
        $post = SocialPost::query()
            ->where('store_id', $store->id)
            ->find($postId);

        if (! $post instanceof SocialPost) {
            abort(404, 'Post not found.');
        }

        return $post;
    }

    private function findOwnedCampaign(Store $store, string $campaignId): StoreAdCampaign
    {
        $campaign = StoreAdCampaign::query()
            ->where('store_id', $store->id)
            ->find($campaignId);

        if (! $campaign instanceof StoreAdCampaign) {
            abort(404, 'Campaign not found.');
        }

        return $campaign;
    }

    private function invalidateMarketingApiCache(Store $store): void
    {
        $this->invalidateStoreApiCache($store);
    }
}
