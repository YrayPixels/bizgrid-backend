<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\BillingWebhookEvent;
use App\Models\Merchant;
use App\Models\MerchantNote;
use App\Models\StoreOrder;
use App\Models\AdminAuditLog;
use App\Services\AdminAuditService;
use App\Services\MerchantEmailVerificationService;
use App\Services\MerchantUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminMerchantController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly MerchantUsageService $usage,
        private readonly MerchantEmailVerificationService $emailVerification,
    ) {}
    public function index(Request $request): JsonResponse
    {
        $query = Merchant::with(['owner:id,name,email,email_verified_at'])
            ->withCount('stores')
            ->withSum('stores as gross_revenue', 'gross_revenue')
            ->withSum('stores as products_count', 'products_count')
            ->withSum('stores as orders_count', 'orders_count')
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('plan') && $request->plan !== 'all') {
            $query->where('subscription_plan', $request->plan);
        }

        if ($request->filled('onboarding') && $request->onboarding !== 'all') {
            if ($request->onboarding === 'incomplete') {
                $query->whereDoesntHave('stores');
            } elseif ($request->onboarding === 'complete') {
                $query->whereHas('stores');
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'like', "%{$search}%")
                    ->orWhere('industry', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                        $ownerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $merchants = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $merchants->getCollection()->map(fn ($merchant) => $this->formatMerchant($merchant)),
            'meta' => [
                'current_page' => $merchants->currentPage(),
                'last_page' => $merchants->lastPage(),
                'per_page' => $merchants->perPage(),
                'total' => $merchants->total(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $counts = Merchant::query()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $incompleteOnboarding = Merchant::query()->whereDoesntHave('stores')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'total_merchants' => Merchant::count(),
                'active_merchants' => (int) ($counts['active'] ?? 0),
                'pending_merchants' => (int) ($counts['pending'] ?? 0),
                'suspended_merchants' => (int) ($counts['suspended'] ?? 0),
                'incomplete_onboarding' => $incompleteOnboarding,
                'total_stores' => Merchant::query()->withCount('stores')->get()->sum('stores_count'),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $merchant = Merchant::with(['owner:id,name,email,email_verified_at', 'stores'])
            ->withCount('stores')
            ->withSum('stores as gross_revenue', 'gross_revenue')
            ->withSum('stores as products_count', 'products_count')
            ->withSum('stores as orders_count', 'orders_count')
            ->find($id);

        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatMerchant($merchant, true),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'business_name' => 'sometimes|string|max:255',
            'industry' => 'sometimes|nullable|string|max:120',
            'verify_owner_email' => 'sometimes|boolean',
            'resend_owner_verification_email' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchant = Merchant::with('owner')->find($id);
        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found',
            ], 404);
        }

        $data = $validator->validated();
        $profileFields = ['business_name', 'industry'];
        $changed = [];

        foreach ($profileFields as $field) {
            if (array_key_exists($field, $data)) {
                $merchant->{$field} = $data[$field];
                $changed[$field] = $data[$field];
            }
        }

        if ($changed !== []) {
            $merchant->save();
        }

        $ownerEmailVerified = false;
        if (! empty($data['verify_owner_email'])) {
            if (! $merchant->owner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Merchant has no owner account to verify',
                ], 422);
            }

            if (! $merchant->owner->email_verified_at) {
                $merchant->owner->email_verified_at = now();
                $merchant->owner->verification_code = null;
                $merchant->owner->verification_code_expires_at = null;
                $merchant->owner->save();
            }
            $ownerEmailVerified = true;
            $changed['verify_owner_email'] = true;
            $this->invalidateUserApiCache((int) $merchant->owner->id);
        }

        $verificationResent = false;
        if (! empty($data['resend_owner_verification_email'])) {
            if (! $merchant->owner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Merchant has no owner account',
                ], 422);
            }

            if ($merchant->owner->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Owner email is already verified',
                ], 422);
            }

            $sent = $this->emailVerification->sendCode($merchant->owner);
            if (! $sent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not send verification email. Check mail configuration.',
                    'code' => 'mail_send_failed',
                ], 503);
            }

            $verificationResent = true;
            $changed['resend_owner_verification_email'] = true;
        }

        if ($changed === []) {
            return response()->json([
                'success' => false,
                'message' => 'No updates provided',
            ], 422);
        }

        $this->audit->log($request, 'merchant.updated', 'merchant', $merchant->id, $changed);

        $this->invalidateAdminApiCache();
        $this->invalidateMerchantApiCache($merchant->id);

        $message = 'Merchant updated';
        if ($verificationResent) {
            $message = 'Verification email resent to owner';
        } elseif ($ownerEmailVerified && count($changed) === 1) {
            $message = 'Owner email verified';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->formatMerchant($this->reloadMerchant($merchant), true),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,active,suspended',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant not found',
            ], 404);
        }

        $status = $validator->validated()['status'];
        $merchant->status = $status;

        if ($status === 'active') {
            $merchant->activated_at = $merchant->activated_at ?? now();
            $merchant->suspended_at = null;
            $merchant->suspension_reason = null;
        } elseif ($status === 'suspended') {
            $merchant->suspended_at = now();
            $merchant->suspension_reason = $validator->validated()['reason'] ?? null;
        } else {
            $merchant->suspended_at = null;
            $merchant->suspension_reason = null;
        }

        $merchant->save();

        $this->audit->log($request, 'merchant.status_updated', 'merchant', $merchant->id, [
            'status' => $status,
            'reason' => $validator->validated()['reason'] ?? null,
        ]);

        $this->invalidateAdminApiCache();
        $this->invalidateMerchantApiCache($merchant->id);
        $merchant->load('stores');
        foreach ($merchant->stores as $store) {
            $this->invalidateStoreApiCache($store);
        }

        return response()->json([
            'success' => true,
            'message' => 'Merchant status updated',
            'data' => $this->formatMerchant($this->reloadMerchant($merchant), true),
        ]);
    }

    public function billing(int $id): JsonResponse
    {
        $merchant = Merchant::with('owner:id,name,email')->find($id);

        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBilling($merchant),
        ]);
    }

    public function updateBilling(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subscription_plan' => 'sometimes|in:starter,growth,scale',
            'subscription_status' => 'sometimes|in:trial,active,past_due,cancelled',
            'sms_purchased_balance' => 'sometimes|integer|min:0',
            'whatsapp_purchased_balance' => 'sometimes|integer|min:0',
            'ai_purchased_credits' => 'sometimes|integer|min:0',
            'grant_monthly_allowances' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $data = $validator->validated();

        if (isset($data['subscription_plan'])) {
            $merchant->subscription_plan = $data['subscription_plan'];
        }
        if (isset($data['subscription_status'])) {
            $merchant->subscription_status = $data['subscription_status'];
        }
        if (isset($data['sms_purchased_balance'])) {
            $merchant->sms_purchased_balance = $data['sms_purchased_balance'];
        }
        if (isset($data['whatsapp_purchased_balance'])) {
            $merchant->whatsapp_purchased_balance = $data['whatsapp_purchased_balance'];
        }
        if (isset($data['ai_purchased_credits'])) {
            $merchant->ai_purchased_credits = $data['ai_purchased_credits'];
        }

        $merchant->save();

        if (! empty($data['grant_monthly_allowances'])) {
            $this->usage->grantMonthlyAllowances($merchant);
        }

        $this->audit->log($request, 'merchant.billing_updated', 'merchant', $merchant->id, $data);

        $this->invalidateAdminApiCache();
        $this->invalidateMerchantApiCache($merchant->id);

        return response()->json([
            'success' => true,
            'message' => 'Billing updated',
            'data' => $this->formatBilling($merchant->fresh()),
        ]);
    }

    public function impersonate(Request $request, int $id): JsonResponse
    {
        $merchant = Merchant::with('owner')->find($id);

        if (! $merchant || ! $merchant->owner) {
            return response()->json(['success' => false, 'message' => 'Merchant or owner not found'], 404);
        }

        $tokenResult = $merchant->owner->createToken('admin-impersonation', ['merchant:impersonated']);
        $tokenResult->accessToken->expires_at = now()->addMinutes(15);
        $tokenResult->accessToken->save();

        $token = $tokenResult->plainTextToken;

        // Exchange code pattern: generate short-lived code for impersonation
        $code = Str::random(64);
        \Illuminate\Support\Facades\Cache::put("auth:exchange:{$code}", [
            'token' => $token,
            'user_id' => $merchant->owner->id,
            'type' => 'impersonation',
        ], now()->addMinutes(2));

        $appUrl = rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/');

        $this->audit->log($request, 'merchant.impersonated', 'merchant', $merchant->id, [
            'owner_user_id' => $merchant->owner_user_id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $code,
                'app_url' => $appUrl,
                'expires_in_minutes' => 2,
                'merchant' => [
                    'id' => $merchant->id,
                    'business_name' => $merchant->business_name,
                ],
                'user' => [
                    'id' => $merchant->owner->id,
                    'name' => $merchant->owner->name,
                    'email' => $merchant->owner->email,
                ],
            ],
        ]);
    }

    private function formatBilling(Merchant $merchant): array
    {
        $planKey = $merchant->subscription_plan ?: 'starter';
        $plan = $this->usage->planConfig($planKey);

        return [
            'subscription_plan' => $merchant->subscription_plan,
            'subscription_status' => $merchant->subscription_status,
            'subscription_renews_at' => $merchant->subscription_renews_at?->toIso8601String(),
            'dodo_customer_id' => $merchant->dodo_customer_id,
            'dodo_subscription_id' => $merchant->dodo_subscription_id,
            'plan_name' => $plan['name'] ?? ucfirst($planKey),
            'plan_price_label' => $plan['price_label'] ?? null,
            'usage' => $this->usage->formatUsage($merchant),
            'balances' => [
                'sms_included_remaining' => (int) $merchant->sms_included_remaining,
                'sms_purchased_balance' => (int) $merchant->sms_purchased_balance,
                'whatsapp_included_remaining' => (int) $merchant->whatsapp_included_remaining,
                'whatsapp_purchased_balance' => (int) $merchant->whatsapp_purchased_balance,
                'ai_purchased_credits' => (int) $merchant->ai_purchased_credits,
                'ai_credits_used_today' => (int) $merchant->ai_credits_used_today,
                'monthly_processed_ngn' => (float) $merchant->monthly_processed_ngn,
            ],
        ];
    }

    private function formatMerchant(Merchant $merchant, bool $includeStores = false): array
    {
        $data = [
            'id' => $merchant->id,
            'owner_user_id' => $merchant->owner_user_id,
            'business_name' => $merchant->business_name,
            'slug' => $merchant->slug,
            'industry' => $merchant->industry,
            'status' => $merchant->status,
            'subscription_plan' => $merchant->subscription_plan,
            'subscription_status' => $merchant->subscription_status,
            'subscription_renews_at' => $merchant->subscription_renews_at?->toIso8601String(),
            'dodo_customer_id' => $merchant->dodo_customer_id,
            'dodo_subscription_id' => $merchant->dodo_subscription_id,
            'activated_at' => $merchant->activated_at?->toIso8601String(),
            'suspended_at' => $merchant->suspended_at?->toIso8601String(),
            'suspension_reason' => $merchant->suspension_reason,
            'tags' => $merchant->tags ?? [],
            'stores_count' => (int) ($merchant->stores_count ?? 0),
            'products_count' => (int) ($merchant->products_count ?? 0),
            'orders_count' => (int) ($merchant->orders_count ?? 0),
            'gross_revenue' => (float) ($merchant->gross_revenue ?? 0),
            'onboarding_completed' => $merchant->hasCompletedOnboarding(),
            'owner' => $merchant->relationLoaded('owner') && $merchant->owner ? [
                'id' => $merchant->owner->id,
                'name' => $merchant->owner->name,
                'email' => $merchant->owner->email,
                'email_verified_at' => $merchant->owner->email_verified_at?->toIso8601String(),
            ] : null,
            'created_at' => $merchant->created_at?->toIso8601String(),
            'updated_at' => $merchant->updated_at?->toIso8601String(),
        ];

        if ($includeStores) {
            $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
            $data['stores'] = $merchant->relationLoaded('stores')
                ? $merchant->stores->map(fn ($store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'status' => $store->status,
                    'primary_domain' => $store->primary_domain,
                    'subdomain_host' => "{$store->slug}.{$platformDomain}",
                    'storefront_template_id' => $store->storefront_template_id,
                    'products_count' => (int) $store->products_count,
                    'orders_count' => (int) $store->orders_count,
                    'gross_revenue' => (float) $store->gross_revenue,
                    'published_at' => $store->published_at?->toIso8601String(),
                    'created_at' => $store->created_at?->toIso8601String(),
                    'updated_at' => $store->updated_at?->toIso8601String(),
                ])->values()
                : [];
        }

        return $data;
    }

    private function reloadMerchant(Merchant $merchant): Merchant
    {
        $merchant->load(['owner:id,name,email,email_verified_at', 'stores']);
        $merchant->loadCount('stores');
        $merchant->loadSum('stores as gross_revenue', 'gross_revenue');
        $merchant->loadSum('stores as products_count', 'products_count');
        $merchant->loadSum('stores as orders_count', 'orders_count');

        return $merchant;
    }

    public function notes(int $id): JsonResponse
    {
        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $notes = MerchantNote::with('admin:id,name,email')
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'admin' => $note->admin ? ['id' => $note->admin->id, 'name' => $note->admin->name] : null,
                'created_at' => $note->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function storeNote(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $note = MerchantNote::create([
            'merchant_id' => $merchant->id,
            'admin_user_id' => $request->user()?->id,
            'body' => $data['body'],
        ]);

        $this->audit->log($request, 'merchant.note_added', 'merchant', $merchant->id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function updateTags(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tags' => 'required|array|max:20',
            'tags.*' => 'string|max:40',
        ]);

        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $merchant->tags = array_values(array_unique($data['tags']));
        $merchant->save();

        $this->audit->log($request, 'merchant.tags_updated', 'merchant', $merchant->id, ['tags' => $merchant->tags]);

        $this->invalidateAdminApiCache();

        return response()->json(['success' => true, 'data' => ['tags' => $merchant->tags]]);
    }

    public function timeline(int $id): JsonResponse
    {
        $merchant = Merchant::with('stores')->find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $events = collect([
            ['type' => 'merchant_created', 'label' => 'Merchant signed up', 'at' => $merchant->created_at?->toIso8601String()],
            $merchant->activated_at ? ['type' => 'activated', 'label' => 'Account activated', 'at' => $merchant->activated_at->toIso8601String()] : null,
            $merchant->suspended_at ? ['type' => 'suspended', 'label' => 'Account suspended', 'at' => $merchant->suspended_at->toIso8601String()] : null,
        ])->filter();

        foreach ($merchant->stores as $store) {
            $events->push(['type' => 'store_created', 'label' => "Store created: {$store->name}", 'at' => $store->created_at?->toIso8601String()]);
            if ($store->published_at) {
                $events->push(['type' => 'store_published', 'label' => "Store published: {$store->name}", 'at' => $store->published_at->toIso8601String()]);
            }
        }

        $firstOrder = StoreOrder::query()
            ->whereIn('store_id', $merchant->stores->pluck('id'))
            ->orderBy('placed_at')
            ->first();

        if ($firstOrder) {
            $events->push(['type' => 'first_order', 'label' => "First order: {$firstOrder->order_number}", 'at' => $firstOrder->placed_at?->toIso8601String()]);
        }

        $auditEvents = AdminAuditLog::query()
            ->where('target_type', 'merchant')
            ->where('target_id', $merchant->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($log) => [
                'type' => $log->action,
                'label' => str_replace('.', ' ', $log->action),
                'at' => $log->created_at?->toIso8601String(),
            ]);

        $timeline = $events->merge($auditEvents)->filter(fn ($e) => ! empty($e['at']))
            ->sortByDesc('at')
            ->values();

        return response()->json(['success' => true, 'data' => $timeline]);
    }

    public function billingEvents(int $id): JsonResponse
    {
        $merchant = Merchant::find($id);
        if (! $merchant) {
            return response()->json(['success' => false, 'message' => 'Merchant not found'], 404);
        }

        $events = BillingWebhookEvent::query()
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'status' => $e->status,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $events]);
    }
}
