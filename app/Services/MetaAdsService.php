<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreAdCampaign;
use App\Models\StoreSocialConnection;
use App\Support\UtmUrl;
use RuntimeException;

/**
 * Paid campaigns on the Meta Marketing API (Facebook + Instagram placements).
 *
 * Ads spend the merchant's own money, so every campaign is created PAUSED and
 * stays that way until the merchant explicitly activates it from the dashboard.
 * Budgets are clamped server-side — a agent-suggested number can never reach
 * Meta unchecked.
 */
class MetaAdsService
{
    public const PROVIDER = 'facebook_ads';

    /** Objectives we expose, each mapped to a delivery setup that works without a pixel. */
    private const OBJECTIVES = [
        'OUTCOME_TRAFFIC' => ['optimization_goal' => 'LINK_CLICKS', 'billing_event' => 'IMPRESSIONS'],
        'OUTCOME_AWARENESS' => ['optimization_goal' => 'REACH', 'billing_event' => 'IMPRESSIONS'],
        'OUTCOME_ENGAGEMENT' => ['optimization_goal' => 'POST_ENGAGEMENT', 'billing_event' => 'IMPRESSIONS'],
    ];

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly FacebookService $facebook,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('facebook.app_id'))
            && filled(config('facebook.app_secret'))
            && (bool) config('facebook.ads_enabled');
    }

    /**
     * @return array{objectives: list<array{value: string, label: string}>, min_daily_budget_minor: int, max_daily_budget_minor: int}
     */
    public function capabilities(): array
    {
        return [
            'objectives' => [
                ['value' => 'OUTCOME_TRAFFIC', 'label' => 'Send people to my store'],
                ['value' => 'OUTCOME_AWARENESS', 'label' => 'Reach as many people as possible'],
                ['value' => 'OUTCOME_ENGAGEMENT', 'label' => 'Get likes, comments and shares'],
            ],
            'min_daily_budget_minor' => (int) config('facebook.ads.min_daily_budget_minor'),
            'max_daily_budget_minor' => (int) config('facebook.ads.max_daily_budget_minor'),
        ];
    }

    public function findAdAccount(int $storeId): ?StoreSocialConnection
    {
        return StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', self::PROVIDER)
            ->latest()
            ->first();
    }

    /**
     * Ad accounts the connected Facebook user can spend from.
     *
     * @return list<array<string, mixed>>
     */
    public function listAvailableAdAccounts(Store $store): array
    {
        $this->assertConfigured();

        $user = $this->facebook->userConnection($store);

        if (! $user instanceof StoreSocialConnection) {
            throw new RuntimeException('Connect your Facebook account first to load your ad accounts.');
        }

        $response = $this->graph->get('/me/adaccounts', [
            'fields' => 'id,account_id,name,currency,account_status,disable_reason',
            'limit' => 50,
        ], (string) $user->page_access_token);

        $accounts = [];

        foreach ($response['data'] ?? [] as $account) {
            if (! is_array($account)) {
                continue;
            }

            $accounts[] = [
                'id' => (string) ($account['id'] ?? ''),
                'account_id' => (string) ($account['account_id'] ?? ''),
                'name' => (string) ($account['name'] ?? 'Ad account'),
                'currency' => (string) ($account['currency'] ?? ''),
                // 1 = ACTIVE; anything else cannot run ads right now.
                'active' => (int) ($account['account_status'] ?? 0) === 1,
            ];
        }

        return $accounts;
    }

    public function selectAdAccount(Store $store, string $adAccountId): StoreSocialConnection
    {
        $this->assertConfigured();

        $available = $this->listAvailableAdAccounts($store);
        $match = null;

        foreach ($available as $account) {
            if ($account['id'] === $adAccountId || $account['account_id'] === $adAccountId) {
                $match = $account;
                break;
            }
        }

        if ($match === null) {
            throw new RuntimeException('That ad account is not available on your Facebook login.');
        }

        if (! $match['active']) {
            throw new RuntimeException('That ad account is not active. Check its billing status in Meta Ads Manager.');
        }

        $user = $this->facebook->userConnection($store);

        return StoreSocialConnection::updateOrCreate(
            [
                'store_id' => $store->id,
                'provider' => self::PROVIDER,
                'page_id' => $match['id'],
            ],
            [
                'provider_account_id' => $match['account_id'],
                'page_name' => $match['name'],
                'page_access_token' => (string) $user?->page_access_token,
                'token_expires_at' => $user?->token_expires_at,
                'status' => 'active',
                'invalid_reason' => null,
                'last_checked_at' => now(),
                'metadata' => ['currency' => $match['currency']],
            ],
        );
    }

    public function disconnect(int $storeId): void
    {
        StoreSocialConnection::query()
            ->where('store_id', $storeId)
            ->where('provider', self::PROVIDER)
            ->delete();
    }

    /**
     * Save a campaign locally without touching Meta. Nothing is spent and no
     * objects exist upstream until launch() is called.
     *
     * @param  array<string, mixed>  $input
     */
    public function createDraft(Store $store, array $input): StoreAdCampaign
    {
        $adAccount = $this->findAdAccount($store->id);
        $objective = $this->normalizeObjective((string) ($input['objective'] ?? config('facebook.ads.default_objective')));

        return StoreAdCampaign::create([
            'store_id' => $store->id,
            'social_connection_id' => $adAccount?->id,
            'provider' => 'meta',
            'name' => (string) ($input['name'] ?? 'Untitled campaign'),
            'objective' => $objective,
            'status' => 'draft',
            'daily_budget_minor' => $this->clampBudget((int) ($input['daily_budget_minor'] ?? 0)),
            'currency' => (string) ($adAccount?->metadata['currency'] ?? $input['currency'] ?? 'NGN'),
            'start_at' => isset($input['start_at']) ? \Illuminate\Support\Carbon::parse($input['start_at']) : null,
            'end_at' => isset($input['end_at']) ? \Illuminate\Support\Carbon::parse($input['end_at']) : null,
            'targeting' => $this->normalizeTargeting(is_array($input['targeting'] ?? null) ? $input['targeting'] : []),
            'creative' => $this->normalizeCreative(is_array($input['creative'] ?? null) ? $input['creative'] : []),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateDraft(StoreAdCampaign $campaign, array $input): StoreAdCampaign
    {
        if (! in_array($campaign->status, ['draft', 'failed'], true)) {
            throw new RuntimeException('Only draft campaigns can be edited. Pause and duplicate a live campaign to change it.');
        }

        if (array_key_exists('name', $input)) {
            $campaign->name = (string) $input['name'];
        }

        if (array_key_exists('objective', $input)) {
            $campaign->objective = $this->normalizeObjective((string) $input['objective']);
        }

        if (array_key_exists('daily_budget_minor', $input)) {
            $campaign->daily_budget_minor = $this->clampBudget((int) $input['daily_budget_minor']);
        }

        foreach (['start_at', 'end_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $campaign->{$field} = filled($input[$field])
                    ? \Illuminate\Support\Carbon::parse($input[$field])
                    : null;
            }
        }

        if (array_key_exists('targeting', $input) && is_array($input['targeting'])) {
            $campaign->targeting = $this->normalizeTargeting($input['targeting']);
        }

        if (array_key_exists('creative', $input) && is_array($input['creative'])) {
            $campaign->creative = $this->normalizeCreative($input['creative']);
        }

        if ($campaign->status === 'failed') {
            $campaign->status = 'draft';
            $campaign->error_message = null;
        }

        $campaign->save();

        return $campaign->fresh();
    }

    /**
     * Build the campaign → ad set → creative → ad chain on Meta. Everything
     * lands PAUSED so no budget is spent until the merchant says go.
     */
    public function launch(StoreAdCampaign $campaign, int $approvedByUserId): StoreAdCampaign
    {
        $this->assertConfigured();

        if (! in_array($campaign->status, ['draft', 'failed'], true)) {
            throw new RuntimeException('This campaign has already been launched.');
        }

        $store = $campaign->store;
        $adAccount = $this->findAdAccount($campaign->store_id);

        if (! $adAccount instanceof StoreSocialConnection) {
            throw new RuntimeException('Choose an ad account before launching a campaign.');
        }

        $page = $store?->socialConnections()->where('provider', 'facebook')->latest()->first();

        if (! $page instanceof StoreSocialConnection) {
            throw new RuntimeException('Connect a Facebook Page — every ad has to run from a Page.');
        }

        $this->assertLaunchable($campaign);

        $campaign->update(['status' => 'publishing', 'error_message' => null]);

        $token = (string) $adAccount->page_access_token;
        $act = $adAccount->page_id; // already in act_<id> form

        try {
            $campaignId = $campaign->external_campaign_id ?: $this->createCampaign($act, $token, $campaign);
            $adSetId = $campaign->external_adset_id ?: $this->createAdSet($act, $token, $campaign, $campaignId);
            $creativeId = $campaign->external_creative_id ?: $this->createCreative($act, $token, $campaign, $page);
            $adId = $campaign->external_ad_id ?: $this->createAd($act, $token, $campaign, $adSetId, $creativeId);

            $campaign->update([
                'external_campaign_id' => $campaignId,
                'external_adset_id' => $adSetId,
                'external_creative_id' => $creativeId,
                'external_ad_id' => $adId,
                'social_connection_id' => $adAccount->id,
                'status' => 'paused',
                'approved_at' => now(),
                'approved_by_user_id' => $approvedByUserId,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            $campaign->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $campaign->fresh();
    }

    /**
     * Flip a launched campaign between ACTIVE and PAUSED. This is the moment
     * money starts or stops moving, so it is always merchant-initiated.
     */
    public function setRunningState(StoreAdCampaign $campaign, bool $active): StoreAdCampaign
    {
        $this->assertConfigured();

        if (! filled($campaign->external_campaign_id)) {
            throw new RuntimeException('Launch this campaign before starting it.');
        }

        $adAccount = $this->findAdAccount($campaign->store_id);
        if (! $adAccount instanceof StoreSocialConnection) {
            throw new RuntimeException('Ad account is no longer connected.');
        }

        $token = (string) $adAccount->page_access_token;
        $state = $active ? 'ACTIVE' : 'PAUSED';

        // Meta gates delivery at every level, so all three have to agree.
        foreach (array_filter([
            $campaign->external_campaign_id,
            $campaign->external_adset_id,
            $campaign->external_ad_id,
        ]) as $objectId) {
            $this->graph->post("/{$objectId}", ['status' => $state], $token);
        }

        $campaign->update([
            'status' => $active ? 'active' : 'paused',
            'error_message' => null,
        ]);

        return $campaign->fresh();
    }

    public function archive(StoreAdCampaign $campaign): StoreAdCampaign
    {
        if (filled($campaign->external_campaign_id)) {
            $adAccount = $this->findAdAccount($campaign->store_id);

            if ($adAccount instanceof StoreSocialConnection) {
                try {
                    $this->graph->post(
                        "/{$campaign->external_campaign_id}",
                        ['status' => 'ARCHIVED'],
                        (string) $adAccount->page_access_token,
                    );
                } catch (\Throwable) {
                    // Archiving upstream is best-effort; the local record is
                    // what the merchant sees.
                }
            }
        }

        $campaign->update(['status' => 'archived']);

        return $campaign->fresh();
    }

    /**
     * Pull delivery numbers so merchants can see what their money bought.
     */
    public function syncMetrics(StoreAdCampaign $campaign): StoreAdCampaign
    {
        if (! filled($campaign->external_campaign_id)) {
            return $campaign;
        }

        $adAccount = $this->findAdAccount($campaign->store_id);
        if (! $adAccount instanceof StoreSocialConnection) {
            return $campaign;
        }

        try {
            $response = $this->graph->get("/{$campaign->external_campaign_id}/insights", [
                'fields' => 'impressions,reach,clicks,spend,ctr,cpc,actions,action_values',
                'date_preset' => 'maximum',
            ], (string) $adAccount->page_access_token);
        } catch (\Throwable) {
            return $campaign;
        }

        $row = $response['data'][0] ?? null;

        if (! is_array($row)) {
            return $campaign;
        }

        $campaign->update([
            'metrics' => [
                'impressions' => (int) ($row['impressions'] ?? 0),
                'reach' => (int) ($row['reach'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'spend' => (float) ($row['spend'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0),
                'cpc' => (float) ($row['cpc'] ?? 0),
                ...$this->extractPurchaseMetrics(is_array($row['actions'] ?? null) ? $row['actions'] : [], is_array($row['action_values'] ?? null) ? $row['action_values'] : []),
            ],
            'metrics_synced_at' => now(),
        ]);

        return $campaign->fresh();
    }

    /**
     * Pull purchase counts (and value when Meta reports it) out of the actions
     * arrays. Prefer omni_purchase over purchase when both are present.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $actionValues
     * @return array{purchases: int, purchase_value: float|null}
     */
    private function extractPurchaseMetrics(array $actions, array $actionValues): array
    {
        $purchases = $this->actionCount($actions, ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase']);
        $value = $this->actionCount($actionValues, ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'], asFloat: true);

        return [
            'purchases' => (int) $purchases,
            'purchase_value' => $value > 0 ? round($value, 2) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function actionCount(array $rows, array $types, bool $asFloat = false): float
    {
        foreach ($types as $type) {
            foreach ($rows as $row) {
                if (($row['action_type'] ?? null) !== $type) {
                    continue;
                }

                return $asFloat ? (float) ($row['value'] ?? 0) : (float) ($row['value'] ?? 0);
            }
        }

        return 0.0;
    }

    private function createCampaign(string $act, string $token, StoreAdCampaign $campaign): string
    {
        $response = $this->graph->post("/{$act}/campaigns", [
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'status' => 'PAUSED',
            'special_ad_categories' => [],
        ], $token);

        return $this->requireId($response, 'campaign');
    }

    private function createAdSet(string $act, string $token, StoreAdCampaign $campaign, string $campaignId): string
    {
        $delivery = self::OBJECTIVES[$campaign->objective] ?? self::OBJECTIVES['OUTCOME_TRAFFIC'];

        $payload = [
            'name' => $campaign->name.' — ad set',
            'campaign_id' => $campaignId,
            'daily_budget' => (string) $campaign->daily_budget_minor,
            'billing_event' => $delivery['billing_event'],
            'optimization_goal' => $delivery['optimization_goal'],
            'targeting' => $this->buildTargetingSpec($campaign),
            'status' => 'PAUSED',
        ];

        // Meta rejects a start time in the past, so default to "in a minute".
        $payload['start_time'] = ($campaign->start_at && $campaign->start_at->isFuture()
            ? $campaign->start_at
            : now()->addMinute())->toIso8601String();

        if ($campaign->end_at !== null) {
            $payload['end_time'] = $campaign->end_at->toIso8601String();
        }

        return $this->requireId($this->graph->post("/{$act}/adsets", $payload, $token), 'ad set');
    }

    private function createCreative(string $act, string $token, StoreAdCampaign $campaign, StoreSocialConnection $page): string
    {
        $creative = $campaign->creative ?? [];
        $link = trim((string) ($creative['link_url'] ?? ''));
        if ($link !== '') {
            $link = UtmUrl::merge($link, UtmUrl::forAdCampaign(
                (int) $campaign->id,
                (string) $campaign->name,
            ));
            // Persist the stamped URL so dashboard attribution matches what Meta serves.
            $creative['link_url'] = $link;
            $campaign->update(['creative' => $creative]);
        }

        $linkData = [
            'message' => (string) ($creative['message'] ?? ''),
            'link' => $link,
        ];

        if (filled($creative['headline'] ?? null)) {
            $linkData['name'] = (string) $creative['headline'];
        }

        if (filled($creative['description'] ?? null)) {
            $linkData['description'] = (string) $creative['description'];
        }

        if (filled($creative['image_url'] ?? null)) {
            $linkData['picture'] = (string) $creative['image_url'];
        }

        if (filled($creative['call_to_action'] ?? null)) {
            $linkData['call_to_action'] = [
                'type' => (string) $creative['call_to_action'],
                'value' => ['link' => $linkData['link']],
            ];
        }

        $response = $this->graph->post("/{$act}/adcreatives", [
            'name' => $campaign->name.' — creative',
            'object_story_spec' => [
                'page_id' => $page->page_id,
                'link_data' => $linkData,
            ],
            'degrees_of_freedom_spec' => [
                'creative_features_spec' => [
                    'standard_enhancements' => ['enroll_status' => 'OPT_OUT'],
                ],
            ],
        ], $token);

        return $this->requireId($response, 'ad creative');
    }

    private function createAd(string $act, string $token, StoreAdCampaign $campaign, string $adSetId, string $creativeId): string
    {
        $response = $this->graph->post("/{$act}/ads", [
            'name' => $campaign->name.' — ad',
            'adset_id' => $adSetId,
            'creative' => ['creative_id' => $creativeId],
            'status' => 'PAUSED',
        ], $token);

        return $this->requireId($response, 'ad');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTargetingSpec(StoreAdCampaign $campaign): array
    {
        $targeting = $campaign->targeting ?? [];

        $geo = [];
        if (filled($targeting['countries'] ?? null)) {
            $geo['countries'] = array_values((array) $targeting['countries']);
        }
        if (filled($targeting['cities'] ?? null)) {
            $geo['cities'] = array_map(
                fn ($city): array => [
                    'key' => (string) ($city['key'] ?? $city),
                    'radius' => (int) ($city['radius'] ?? 25),
                    'distance_unit' => 'kilometer',
                ],
                (array) $targeting['cities'],
            );
        }

        if ($geo === []) {
            $geo['countries'] = ['NG'];
        }

        $spec = [
            'geo_locations' => $geo,
            'age_min' => (int) ($targeting['age_min'] ?? 18),
            'age_max' => (int) ($targeting['age_max'] ?? 65),
        ];

        // 1 = male, 2 = female; omitting the key means all genders.
        if (filled($targeting['genders'] ?? null)) {
            $spec['genders'] = array_values(array_map('intval', (array) $targeting['genders']));
        }

        if (filled($targeting['interests'] ?? null)) {
            $spec['flexible_spec'] = [[
                'interests' => array_map(
                    fn ($interest): array => [
                        'id' => (string) ($interest['id'] ?? $interest),
                        'name' => (string) ($interest['name'] ?? ''),
                    ],
                    (array) $targeting['interests'],
                ),
            ]];
        }

        return $spec;
    }

    private function assertLaunchable(StoreAdCampaign $campaign): void
    {
        $creative = $campaign->creative ?? [];

        if (trim((string) ($creative['message'] ?? '')) === '') {
            throw new RuntimeException('Add ad copy before launching.');
        }

        if (trim((string) ($creative['link_url'] ?? '')) === '') {
            throw new RuntimeException('Add a destination link — ads have to send people somewhere.');
        }

        $min = (int) config('facebook.ads.min_daily_budget_minor');

        if ($campaign->daily_budget_minor < $min) {
            throw new RuntimeException('Daily budget is below the minimum Meta will accept for this account.');
        }

        if ($campaign->end_at !== null && $campaign->start_at !== null && $campaign->end_at <= $campaign->start_at) {
            throw new RuntimeException('The campaign end date has to be after the start date.');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function requireId(array $response, string $label): string
    {
        $id = (string) ($response['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException("Meta did not return an id for the {$label}.");
        }

        return $id;
    }

    private function normalizeObjective(string $objective): string
    {
        $objective = strtoupper(trim($objective));

        return array_key_exists($objective, self::OBJECTIVES)
            ? $objective
            : (string) config('facebook.ads.default_objective', 'OUTCOME_TRAFFIC');
    }

    private function clampBudget(int $minor): int
    {
        $min = (int) config('facebook.ads.min_daily_budget_minor');
        $max = (int) config('facebook.ads.max_daily_budget_minor');

        return max($min, min($max, $minor));
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @return array<string, mixed>
     */
    private function normalizeTargeting(array $targeting): array
    {
        return [
            'countries' => array_values(array_filter(
                array_map('strval', (array) ($targeting['countries'] ?? ['NG'])),
                fn (string $code): bool => $code !== '',
            )),
            'cities' => array_values((array) ($targeting['cities'] ?? [])),
            'age_min' => max(18, min(65, (int) ($targeting['age_min'] ?? 18))),
            'age_max' => max(18, min(65, (int) ($targeting['age_max'] ?? 65))),
            'genders' => array_values(array_filter(
                array_map('intval', (array) ($targeting['genders'] ?? [])),
                fn (int $g): bool => in_array($g, [1, 2], true),
            )),
            'interests' => array_values((array) ($targeting['interests'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $creative
     * @return array<string, mixed>
     */
    private function normalizeCreative(array $creative): array
    {
        $allowedCtas = ['SHOP_NOW', 'LEARN_MORE', 'ORDER_NOW', 'SIGN_UP', 'CONTACT_US', 'MESSAGE_PAGE'];
        $cta = strtoupper((string) ($creative['call_to_action'] ?? 'SHOP_NOW'));

        return [
            'message' => trim((string) ($creative['message'] ?? '')),
            'headline' => trim((string) ($creative['headline'] ?? '')),
            'description' => trim((string) ($creative['description'] ?? '')),
            'link_url' => trim((string) ($creative['link_url'] ?? '')),
            'image_url' => trim((string) ($creative['image_url'] ?? '')),
            'call_to_action' => in_array($cta, $allowedCtas, true) ? $cta : 'SHOP_NOW',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(StoreAdCampaign $campaign): array
    {
        return [
            'id' => (string) $campaign->id,
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'status' => $campaign->status,
            'daily_budget_minor' => (int) $campaign->daily_budget_minor,
            'currency' => $campaign->currency,
            'start_at' => $campaign->start_at?->toIso8601String(),
            'end_at' => $campaign->end_at?->toIso8601String(),
            'targeting' => $campaign->targeting,
            'creative' => $campaign->creative,
            'metrics' => $campaign->metrics,
            'metrics_synced_at' => $campaign->metrics_synced_at?->toIso8601String(),
            'external_campaign_id' => $campaign->external_campaign_id,
            'launched' => filled($campaign->external_campaign_id),
            'error_message' => $campaign->error_message,
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Meta Ads is not enabled on this platform yet.');
        }
    }
}
