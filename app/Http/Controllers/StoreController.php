<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Merchant;
use App\Models\Store;
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
        $existingStore = Store::whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))->first();

        if ($existingStore) {
            $merchant = $existingStore->merchant;
            if ($merchant) {
                $this->enforcement->assertCanCreateStore($merchant);
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
                'contact_name' => $user->name,
                'email' => $user->email,
                'industry' => $data['industry'],
                'status' => 'pending',
                'subscription_plan' => 'starter',
                'subscription_status' => 'trialing',
            ],
        );

        $merchant->fill([
            'business_name' => $data['business_name'],
            'contact_name' => $user->name,
            'email' => $user->email,
            'industry' => $data['industry'],
        ])->save();

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
            'storefront_template_id' => $data['storefront_template_id'] ?? 'ai_pick',
        ])->load('merchant');

        $this->notifications->notify(
            'merchant.signup',
            'New merchant: '.$merchant->business_name,
            $merchant->email,
            ['merchant_id' => $merchant->id],
        );

        $this->invalidateUserApiCache($user->id);
        $this->invalidateStoreApiCache($store);

        return response()->json([
            'store' => $this->formatStore($store),
        ], 201);
    }

    public function myStore(Request $request): JsonResponse
    {
        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->first();

        if (! $store) {
            return response()->json([
                'message' => 'Store not found.',
            ], 404);
        }

        return response()->json([
            'store' => array_merge($this->formatStore($store), $this->publishService->publishMeta($store)),
        ]);
    }

    public function updateMyStore(Request $request): JsonResponse
    {
        $data = $request->validate(array_merge([
            'business_name' => 'sometimes|string|max:160',
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
        ], $this->businessProfileRules(required: false)));

        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->firstOrFail();

        if (array_key_exists('business_name', $data)) {
            $store->name = trim($data['business_name']);
            $store->merchant?->update(['business_name' => $store->name]);
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
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $store->{$field} = $data[$field];
            }
        }

        $store->save();

        $this->invalidateStoreApiCache($store);

        return response()->json([
            'store' => array_merge($this->formatStore($store->fresh('merchant')), $this->publishService->publishMeta($store->fresh('merchant'))),
        ]);
    }

    public function uploadStorefrontImage(Request $request, int $storeId): JsonResponse
    {
        $store = $this->findOwnedStore($request, $storeId);

        $data = $request->validate([
            'image' => 'required|image|max:5120',
        ]);

        $file = $data['image'];
        $directory = public_path("storehause/uploads/{$store->id}");

        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
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

        $store->draft_json = $this->productService->extractEmbeddedProducts($store, $storefront);
        $store->storefront_generation_id = $generationId;
        $store->save();

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
        $draft = $this->publishService->resolveDraft($store);

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
            'storefront.media.about_image_url' => 'nullable|string|max:2000000',
            'storefront.media.category_images' => 'nullable|array',
            'storefront.media.category_images.*' => 'nullable|string|max:2000000',
            'storefront.hero' => 'required|array',
            'storefront.hero.headline' => 'required|string|max:180',
            'storefront.hero.subheadline' => 'required|string|max:500',
            'storefront.hero.cta_label' => 'required|string|max:80',
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
