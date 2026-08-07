<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use App\Models\StorefrontTemplate;
use App\Services\MerchantUsageEnforcementService;
use App\Services\PlatformNotificationService;
use App\Services\StoreNotificationService;
use App\Services\StorefrontBuilderService;
use App\Services\StorefrontPublishService;
use App\Services\StoreProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly StorefrontBuilderService $builderService,
        private readonly StoreProductService $productService,
        private readonly StorefrontPublishService $publishService,
        private readonly MerchantUsageEnforcementService $enforcement,
        private readonly PlatformNotificationService $notifications,
        private readonly StoreNotificationService $storeNotifications,
    ) {}

    public function createStore(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'business_name' => 'required|string|max:160',
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'industry' => 'required|string|max:80',
            'description' => 'required|string|max:1000',
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => 'nullable|url|max:2048',
            'storefront_template_id' => ['nullable', 'string', Rule::in(array_merge(['ai_pick'], StorefrontTemplate::activeConcreteIds()))],
        ], $this->businessProfileRules(required: true)));

        $user = $request->user();

        $staffMembership = \App\Models\MerchantStaff::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\MerchantStaff::STATUS_ACTIVE)
            ->exists();
        if ($staffMembership) {
            return response()->json([
                'message' => 'Staff accounts cannot create a store. Ask the owner to manage the store.',
            ], 403);
        }

        $existingStore = Store::whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))->first();

        if ($existingStore) {
            $merchant = $existingStore->merchant;
            if ($merchant) {
                $this->enforcement->assertCanCreateStore($merchant);
                $merchant->ensureActive();
            }

            return response()->json([
                'message' => 'Store already exists for this account.',
                'store' => $this->formatStore($existingStore->load('merchant')),
            ], 409);
        }

        $merchant = Merchant::firstOrCreate(
            ['owner_user_id' => $user->id],
            [
                'business_name' => $data['business_name'],
                'slug' => $this->uniqueMerchantSlug($data['business_name']),
                'industry' => $data['industry'],
                'status' => 'active',
                'activated_at' => now(),
                'subscription_plan' => config('dodopayments.default_plan', 'free'),
                'subscription_status' => 'active',
            ],
        );

        $merchant->fill([
            'business_name' => $data['business_name'],
            'industry' => $data['industry'],
        ])->save();

        $merchant->ensureActive();

        $slug = isset($data['slug'])
            ? $this->uniqueStoreSlug($data['slug'], baseSlug: Str::slug($data['slug']))
            : $this->uniqueStoreSlug($data['business_name']);
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        $store = Store::create([
            'merchant_id' => $merchant->id,
            'name' => $data['business_name'],
            'slug' => $slug,
            'status' => 'draft',
            'primary_domain' => "{$slug}.{$platformDomain}",
            'description' => $data['description'],
            'brand_color' => $data['brand_color'],
            'logo_url' => $data['logo_url'] ?? null,
            'contact_email' => $user->email,
            'business_location' => $data['business_location'],
            'weekly_orders' => $data['weekly_orders'],
            'payment_currencies' => $data['payment_currencies'],
            'staff_count' => $data['staff_count'],
            'physical_store_count' => $data['physical_store_count'],
            'storefront_template_id' => $data['storefront_template_id'] ?? StorefrontTemplate::DEFAULT_ID,
        ])->load('merchant');

        $this->notifications->notify(
            'merchant.signup',
            'New merchant: '.$merchant->business_name,
            $user->email,
            ['merchant_id' => $merchant->id],
        );

        app(\App\Services\MerchantMembershipService::class)->ensureDefaultLocation($store);

        $this->invalidateUserApiCache($user->id);
        $this->invalidateStoreApiCache($store);
        $this->invalidateAdminApiCache();

        return response()->json([
            'store' => $this->formatStore($store),
        ], 201);
    }

    public function myStore(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
        ]);
    }

    public function updateMyStore(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        $data = $request->validate(array_merge([
            'business_name' => 'sometimes|string|max:160',
            'slug' => [
                'sometimes',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($this->reservedSubdomains()),
                Rule::unique('stores', 'slug')->ignore($store->id),
            ],
            'industry' => 'sometimes|string|max:80',
            'description' => 'sometimes|nullable|string|max:1000',
            'contact_email' => 'sometimes|nullable|email|max:255',
            'contact_phone' => 'sometimes|nullable|string|max:40',
            'brand_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => 'nullable|url|max:2048',
            'notify_merchant_new_order' => 'sometimes|boolean',
            'notify_customer_order_confirmation' => 'sometimes|boolean',
            'notify_customer_payment_confirmation' => 'sometimes|boolean',
            'notify_merchant_low_stock' => 'sometimes|boolean',
            'notification_email' => 'sometimes|nullable|email|max:255',
            'customer_order_note' => 'sometimes|nullable|string|max:2000',
            'sms_sender_name' => 'sometimes|nullable|string|max:11',
            'store_perks' => 'sometimes|nullable|array|max:12',
            'store_perks.*' => 'string|max:160',
            'allow_local_delivery' => 'sometimes|boolean',
            'allow_pickup' => 'sometimes|boolean',
            'default_delivery_fee' => 'sometimes|nullable|numeric|min:0|max:999999',
            'fulfilment_promise' => 'sometimes|nullable|string|max:255',
            'shipping_policy' => 'sometimes|nullable|string|max:5000',
            'return_policy' => 'sometimes|nullable|string|max:5000',
        ], $this->businessProfileRules(required: false)));

        if (array_key_exists('business_name', $data)) {
            $store->name = trim($data['business_name']);
            $store->merchant?->update(['business_name' => $store->name]);
        }

        if (array_key_exists('industry', $data)) {
            $store->merchant?->update(['industry' => $data['industry']]);
        }

        if (array_key_exists('slug', $data)) {
            $slug = strtolower(trim($data['slug']));
            $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
            $oldSubdomainHost = "{$store->slug}.{$platformDomain}";
            $newSubdomainHost = "{$slug}.{$platformDomain}";

            $store->slug = $slug;

            if (! filled($store->primary_domain) || $store->primary_domain === $oldSubdomainHost) {
                $store->primary_domain = $newSubdomainHost;
            }
        }

        if (array_key_exists('description', $data)) {
            $store->description = $data['description'];
        }

        if (array_key_exists('contact_email', $data)) {
            $store->contact_email = $data['contact_email'];
        }

        if (array_key_exists('contact_phone', $data)) {
            $store->contact_phone = $data['contact_phone'];
        }

        if (isset($data['brand_color'])) {
            $store->brand_color = $data['brand_color'];
        }

        if (array_key_exists('logo_url', $data)) {
            $store->logo_url = $data['logo_url'];
        }

        foreach (['business_location', 'weekly_orders', 'staff_count', 'physical_store_count'] as $field) {
            if (array_key_exists($field, $data)) {
                $store->{$field} = $data[$field];
            }
        }

        if (array_key_exists('payment_currencies', $data)) {
            $store->payment_currencies = $data['payment_currencies'];
        }

        foreach ([
            'notify_merchant_new_order',
            'notify_customer_order_confirmation',
            'notify_customer_payment_confirmation',
            'notify_merchant_low_stock',
            'notification_email',
            'customer_order_note',
            'sms_sender_name',
            'store_perks',
            'allow_local_delivery',
            'allow_pickup',
            'default_delivery_fee',
            'fulfilment_promise',
            'shipping_policy',
            'return_policy',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $store->{$field} = $data[$field];
            }
        }

        $store->save();
        $store->load('merchant');

        $this->syncBuilderSessionsFromStore($store, $request->user()->id, $data);
        $this->invalidateStoreApiCache($store);
        $this->invalidateUserApiCache((int) $request->user()->id);

        $fresh = $store->fresh('merchant');

        return response()->json([
            'store' => array_merge($this->formatStore($fresh), $this->publishService->publishMeta($fresh)),
        ]);
    }

    /**
     * Keep active builder session profiles aligned with store settings so
     * later builder turns do not re-apply stale name/industry values.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncBuilderSessionsFromStore(Store $store, int $userId, array $data): void
    {
        $sessions = StorefrontBuilderSession::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['published'])
            ->get();

        foreach ($sessions as $session) {
            $profile = is_array($session->business_profile) ? $session->business_profile : [];
            $changed = false;

            if (array_key_exists('business_name', $data)) {
                $profile['business_name'] = $store->name;
                $changed = true;
            }
            if (array_key_exists('industry', $data)) {
                $profile['industry'] = $store->merchant?->industry ?? $data['industry'];
                $changed = true;
            }
            if (array_key_exists('description', $data)) {
                $profile['description'] = $store->description;
                $changed = true;
            }
            if (array_key_exists('brand_color', $data)) {
                $profile['brand_color'] = $store->brand_color;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $session->business_profile = $profile;
            $session->save();
        }
    }

    public function uploadStorefrontImage(Request $request, int $storeId): JsonResponse
    {
        $store = $this->findOwnedStore($request, $storeId);

        $data = $request->validate([
            'image' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,video/quicktime',
            ],
        ]);

        $file = $data['image'];

        // Map MIME type to safe extension
        $mime = $file->getMimeType();
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
        ];

        $extension = $extensionMap[$mime] ?? 'bin';
        $directory = public_path("storehause/uploads/{$store->id}");

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = Str::uuid().'.'.$extension;
        $file->move($directory, $filename);

        return response()->json([
            'url' => url("storehause/uploads/{$store->id}/{$filename}"),
        ], 201);
    }

    public function generateStorefront(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => 'required|integer',
            'storefront_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'storefront' => 'nullable|array',
        ]);

        $store = $this->findOwnedStore($request, (int) $data['store_id']);
        $store->loadMissing('merchant');
        if ($store->merchant && empty($data['storefront'])) {
            $this->enforcement->assertCanUseAi($store->merchant);
            $this->enforcement->consumeAiCredit($store->merchant);
        }

        if (isset($data['storefront_template_id'])) {
            $store->storefront_template_id = $data['storefront_template_id'];
        }

        if (! empty($data['storefront'])) {
            $storefront = $data['storefront'];
        } else {
            $storefront = $this->builderService->synthesizeStorefront($store);
        }

        $generationId = (string) Str::uuid();

        $storefront = $this->productService->extractEmbeddedProducts($store, $storefront);
        $store->storefront_generation_id = $generationId;
        $this->publishService->persistDraft($store, $storefront);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'generation_id' => $generationId,
            'storefront' => $this->productService->mergeIntoStorefront($store->draft_json, $store),
            'publish' => $this->publishService->publishMeta($store),
        ]);
    }

    public function getStorefront(Request $request, int $storeId): JsonResponse
    {
        $store = $this->findOwnedStore($request, $storeId);
        $draft = $this->publishService->resolveEditorDraft($store);

        return response()->json([
            'storefront' => $draft
                ? $this->productService->mergeIntoStorefront($draft, $store)
                : null,
            'publish' => $this->publishService->publishMeta($store),
        ]);
    }

    public function updateStorefront(Request $request, int $storeId): JsonResponse
    {
        $data = $request->validate([
            'storefront' => 'required|array',
            'storefront.template' => 'nullable|array',
            'storefront.template.id' => ['nullable', 'string', Rule::in(StorefrontTemplate::concreteIds())],
            'storefront.template.source' => 'nullable|string|in:merchant_selected,ai_selected',
            'storefront.palette' => 'nullable|array',
            'storefront.palette.primary' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.accent' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.background' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.surface' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.text' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.muted' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.palette.border' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'storefront.display_font' => 'nullable|string|max:80',
            'storefront.theme_overrides' => 'nullable|array',
            'storefront.theme_overrides.button_style' => 'nullable|string|in:rounded,square,pill',
            'storefront.theme_overrides.button_radius' => 'nullable|string|in:none,md,full',
            'storefront.theme_overrides.density' => 'nullable|string|in:compact,default,airy',
            'storefront.theme_overrides.body_font' => 'nullable|string|in:clean-sans,modern-sans,elegant-serif',
            'storefront.data_plugs' => 'nullable|array',
            'storefront.data_plugs.home_products_source' => 'nullable|string|in:merchant_products,theme_products',
            'storefront.media' => 'nullable|array',
            'storefront.media.hero_image_url' => 'nullable|string|max:2000000',
            'storefront.media.hero_video_url' => 'nullable|string|max:2000000',
            'storefront.media.about_image_url' => 'nullable|string|max:2000000',
            'storefront.media.category_images' => 'nullable|array',
            'storefront.media.category_images.*' => 'nullable|string|max:2000000',
            'storefront.hero' => 'required|array',
            'storefront.hero.headline' => 'required|string|max:180',
            'storefront.hero.subheadline' => 'required|string|max:500',
            'storefront.hero.cta_label' => 'required|string|max:80',
            'storefront.hero.eyebrow' => 'nullable|string|max:80',
            'storefront.about' => 'required|array',
            'storefront.about.title' => 'required|string|max:160',
            'storefront.about.body' => 'required|string|max:2000',
            'storefront.value_props' => 'required|array|min:1|max:6',
            'storefront.value_props.*.title' => 'required|string|max:120',
            'storefront.value_props.*.body' => 'required|string|max:500',
            'storefront.pages' => 'nullable|array',
            'storefront.seo' => 'required|array',
            'storefront.seo.title' => 'required|string|max:160',
            'storefront.seo.description' => 'required|string|max:300',
            'storefront_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
        ]);

        $store = $this->findOwnedStore($request, $storeId);

        if (isset($data['storefront_template_id'])) {
            $store->storefront_template_id = $data['storefront_template_id'];
            $data['storefront']['template'] = [
                'id' => $data['storefront_template_id'],
                'source' => 'merchant_selected',
            ];
        }

        if (! $store->storefront_generation_id) {
            $store->storefront_generation_id = (string) Str::uuid();
        }

        unset($data['storefront']['products']);
        $this->publishService->persistDraft($store, $data['storefront']);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'generation_id' => $store->storefront_generation_id,
            'storefront' => $this->productService->mergeIntoStorefront($store->draft_json, $store),
            'publish' => $this->publishService->publishMeta($store),
        ]);
    }

    public function publishStorefront(Request $request, int $storeId): JsonResponse
    {
        $this->assertEmailVerified($request, 'Verify your email before publishing your storefront.');

        $store = $this->findOwnedStore($request, $storeId);
        $store = $this->publishService->publish($store);
        $published = $this->publishService->resolvePublished($store);

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
            'storefront' => $published
                ? $this->productService->mergeIntoStorefront($published, $store, activeOnly: true)
                : null,
            'publish' => $this->publishService->publishMeta($store),
            'message' => 'Your storefront is live.',
        ]);
    }
}
