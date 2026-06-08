<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorefrontTemplate;
use App\Models\StoreVisit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StorehauseController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:128',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('storehause')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:128',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('storehause')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }

    public function createStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => 'required|string|max:160',
            'industry' => 'required|string|max:80',
            'description' => 'required|string|max:1000',
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => 'nullable|url|max:2048',
            'storefront_template_id' => ['nullable', 'string', Rule::in(array_merge(['ai_pick'], StorefrontTemplate::activeConcreteIds()))],
        ]);

        $user = $request->user();
        $existingStore = Store::whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))->first();

        if ($existingStore) {
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

        $slug = $this->uniqueStoreSlug($data['business_name']);
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');

        $store = Store::create([
            'merchant_id' => $merchant->id,
            'name' => $data['business_name'],
            'slug' => $slug,
            'status' => 'draft',
            'primary_domain' => "{$slug}.{$platformDomain}",
            'description' => $data['description'],
            'brand_color' => $data['brand_color'],
            'logo_url' => $data['logo_url'] ?? null,
            'storefront_template_id' => $data['storefront_template_id'] ?? 'ai_pick',
        ])->load('merchant');

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
            'store' => $this->formatStore($store),
        ]);
    }

    public function updateMyStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->firstOrFail();

        if (isset($data['brand_color'])) {
            $store->brand_color = $data['brand_color'];
        }

        $store->save();

        return response()->json([
            'store' => $this->formatStore($store),
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
        ]);

        $store = $this->findOwnedStore($request, (int) $data['store_id']);
        if (isset($data['storefront_template_id'])) {
            $store->storefront_template_id = $data['storefront_template_id'];
        }
        $storefront = $this->synthesizeStorefront($store);
        $generationId = (string) Str::uuid();

        $store->storefront_content = $storefront;
        $store->storefront_generation_id = $generationId;
        $store->save();

        return response()->json([
            'generation_id' => $generationId,
            'storefront' => $storefront,
        ]);
    }

    public function getStorefront(Request $request, int $storeId): JsonResponse
    {
        $store = $this->findOwnedStore($request, $storeId);

        return response()->json([
            'storefront' => $store->storefront_content,
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
            'storefront.products' => 'nullable|array',
            'storefront.products.*.id' => 'nullable|string|max:120',
            'storefront.products.*.slug' => 'nullable|string|max:180',
            'storefront.products.*.name' => 'nullable|string|max:180',
            'storefront.products.*.description' => 'nullable|string|max:1000',
            'storefront.products.*.price' => 'nullable|numeric',
            'storefront.products.*.currency' => 'nullable|string|max:10',
            'storefront.products.*.image_url' => 'nullable|string|max:2000000',
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

        $store->storefront_content = $data['storefront'];
        $store->save();

        return response()->json([
            'generation_id' => $store->storefront_generation_id,
            'storefront' => $store->storefront_content,
        ]);
    }

    public function publicStorefrontByHost(Request $request): JsonResponse
    {
        $host = strtolower((string) $request->query('host', ''));
        $host = explode(':', $host)[0];

        if ($host === '') {
            return response()->json([
                'message' => 'Host is required.',
            ], 422);
        }

        $store = $this->findStoreByHost($host);

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        return response()->json($this->formatPublicPayload($store));
    }

    public function publicStorefront(string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        return response()->json($this->formatPublicPayload($store));
    }

    public function publicGeneration(string $generationId): JsonResponse
    {
        $store = Store::with('merchant')->where('storefront_generation_id', $generationId)->first();

        if (! $store) {
            return response()->json([
                'message' => 'Generation not found.',
            ], 404);
        }

        return response()->json($this->formatPublicPayload($store));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $since = now()->subDays(13)->startOfDay();
        $orderQuery = StoreOrder::where('store_id', $store->id);
        $salesQuery = (clone $orderQuery)->whereNotIn('status', ['cancelled', 'refunded']);
        $totalVisits = StoreVisit::where('store_id', $store->id)->count();
        $totalOrders = (clone $orderQuery)->count();
        $totalSales = (float) (clone $salesQuery)->sum('total_amount');
        $salesByDate = (clone $salesQuery)
            ->where('placed_at', '>=', $since)
            ->selectRaw('DATE(placed_at) as date, COUNT(*) as orders, SUM(total_amount) as sales')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $salesByDay = collect(range(0, 13))->map(function (int $offset) use ($since, $salesByDate) {
            $date = $since->copy()->addDays($offset)->toDateString();
            $row = $salesByDate->get($date);

            return [
                'date' => $date,
                'orders' => (int) ($row->orders ?? 0),
                'sales' => (float) ($row->sales ?? 0),
            ];
        })->values();

        return response()->json([
            'metrics' => [
                'total_orders' => $totalOrders,
                'pending_orders' => (clone $orderQuery)->where('status', 'pending')->count(),
                'fulfilled_orders' => (clone $orderQuery)->where('status', 'fulfilled')->count(),
                'total_sales' => $totalSales,
                'average_order_value' => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
                'total_visits' => $totalVisits,
                'visits_today' => StoreVisit::where('store_id', $store->id)
                    ->where('visited_at', '>=', now()->startOfDay())
                    ->count(),
                'conversion_rate' => $totalVisits > 0 ? round(($totalOrders / $totalVisits) * 100, 2) : 0,
                'products_count' => $this->productCount($store),
            ],
            'sales_by_day' => $salesByDay,
            'recent_orders' => StoreOrder::where('store_id', $store->id)
                ->latest('placed_at')
                ->limit(5)
                ->get()
                ->map(fn (StoreOrder $order) => $this->formatOrder($order))
                ->values(),
        ]);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $query = StoreOrder::where('store_id', $store->id)->latest('placed_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => $orders->getCollection()->map(fn (StoreOrder $order) => $this->formatOrder($order)),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function updateMyOrderStatus(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:pending,processing,fulfilled,cancelled,refunded',
            'notes' => 'nullable|string|max:1000',
        ]);

        $store = $this->findOwnedStoreForUser($request);
        $order = StoreOrder::where('store_id', $store->id)->findOrFail($orderId);
        $order->fill($data)->save();

        return response()->json([
            'message' => 'Order updated.',
            'order' => $this->formatOrder($order->fresh()),
        ]);
    }

    public function placeOrder(Request $request, string $slug): JsonResponse
    {
        $store = Store::with('merchant')->where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        $data = $request->validate([
            'customer.first_name' => 'required|string|max:80',
            'customer.last_name' => 'required|string|max:80',
            'customer.email' => 'required|email|max:255',
            'customer.phone' => 'required|string|max:40',
            'delivery_address' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|string|max:120',
            'items.*.quantity' => 'required|integer|min:1|max:999',
        ]);

        $products = collect($store->storefront_content['products'] ?? [])
            ->keyBy(fn (array $product) => (string) ($product['id'] ?? ''));
        $currency = 'NGN';
        $items = [];
        $subtotal = 0;

        foreach ($data['items'] as $line) {
            $product = $products->get((string) $line['product_id']);
            if (! $product) {
                return response()->json([
                    'message' => "Product {$line['product_id']} is no longer available.",
                ], 422);
            }

            $quantity = (int) $line['quantity'];
            $unitPrice = (float) ($product['price'] ?? 0);
            $lineTotal = $unitPrice * $quantity;
            $currency = (string) ($product['currency'] ?? $currency);
            $subtotal += $lineTotal;
            $items[] = [
                'product_id' => (string) ($product['id'] ?? $line['product_id']),
                'name' => (string) ($product['name'] ?? 'Product'),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $lineTotal,
                'currency' => $currency,
            ];
        }

        $order = DB::transaction(function () use ($store, $data, $items, $subtotal, $currency) {
            $order = StoreOrder::create([
                'store_id' => $store->id,
                'order_number' => $this->uniqueOrderNumber(),
                'customer_name' => trim($data['customer']['first_name'].' '.$data['customer']['last_name']),
                'customer_email' => strtolower($data['customer']['email']),
                'customer_phone' => $data['customer']['phone'],
                'delivery_address' => $data['delivery_address'],
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => $currency,
                'subtotal' => $subtotal,
                'total_amount' => $subtotal,
                'items' => $items,
                'notes' => $data['notes'] ?? null,
                'placed_at' => now(),
            ]);

            $store->increment('orders_count');
            $store->increment('gross_revenue', $subtotal);

            return $order;
        });

        return response()->json([
            'order' => $this->formatOrder($order),
        ], 201);
    }

    public function recordVisit(Request $request, string $slug): JsonResponse
    {
        $store = Store::where('slug', Str::slug($slug))->first();

        if (! $store) {
            return response()->json([
                'message' => 'Storefront not found.',
            ], 404);
        }

        $data = $request->validate([
            'session_id' => 'nullable|string|max:120',
            'path' => 'nullable|string|max:2048',
            'referrer' => 'nullable|string|max:2048',
        ]);

        StoreVisit::create([
            'store_id' => $store->id,
            'session_id' => $data['session_id'] ?? null,
            'path' => $data['path'] ?? null,
            'referrer' => $data['referrer'] ?? null,
            'user_agent' => Str::limit((string) $request->userAgent(), 512, ''),
            'ip_hash' => $request->ip() ? hash('sha256', $request->ip()) : null,
            'visited_at' => now(),
        ]);

        return response()->json([
            'message' => 'Visit recorded.',
        ], 201);
    }

    private function findOwnedStore(Request $request, int $storeId): Store
    {
        $store = Store::with('merchant')
            ->where('id', $storeId)
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->first();

        if (! $store) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    private function findOwnedStoreForUser(Request $request): Store
    {
        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $request->user()->id))
            ->latest()
            ->first();

        if (! $store) {
            abort(404, 'Store not found.');
        }

        return $store;
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'has_store' => Merchant::where('owner_user_id', $user->id)->whereHas('stores')->exists(),
        ];
    }

    private function formatStore(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $subdomainHost = "{$store->slug}.{$platformDomain}";

        return [
            'id' => (string) $store->id,
            'slug' => $store->slug,
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description ?? '',
            'brand_color' => $store->brand_color ?? '#0E7C66',
            'logo_url' => $store->logo_url,
            'storefront_template_id' => $store->storefront_template_id ?? 'ai_pick',
            'subdomain' => $store->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
        ];
    }

    private function formatOrder(StoreOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'order_number' => $order->order_number,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'total_amount' => (float) $order->total_amount,
            'items' => $order->items ?? [],
            'notes' => $order->notes,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];
    }

    private function productCount(Store $store): int
    {
        $products = $store->storefront_content['products'] ?? [];

        return is_array($products) ? count($products) : 0;
    }

    private function synthesizeStorefront(Store $store): array
    {
        $businessName = $store->name;
        $industry = $store->merchant?->industry ?? 'other';
        $industryLabel = Str::headline(str_replace('_', ' ', $industry));
        $description = $store->description ?: "{$businessName} helps customers discover quality {$industryLabel} products and services.";
        $contactEmail = $store->merchant?->email;
        $templateId = $this->resolveStorefrontTemplate($store);
        $isBeauty = $templateId === 'beauty';
        $isCosmetics = $templateId === 'cosmetics';
        $hero = $isCosmetics
            ? [
                'headline' => 'Discover the nature with cosmetics.',
                'subheadline' => 'Botanical skincare, clean formulas, and real glow rituals for everyday skin.',
                'cta_label' => 'Discover the line',
            ]
            : ($isBeauty
            ? [
                'headline' => 'Be beautiful, be you.',
                'subheadline' => 'Premium virgin hair extensions created exclusively for natural textures.',
                'cta_label' => 'Shop now',
            ]
            : [
                'headline' => "Shop {$businessName} online",
                'subheadline' => $description,
                'cta_label' => 'Shop now',
            ]);
        $about = $isCosmetics
            ? [
                'title' => 'Best skin cleanser',
                'body' => "{$businessName} creates gentle cleansers, serums, moisturisers, and routine kits with botanical ingredients and a clean daily skincare point of view.",
            ]
            : ($isBeauty
            ? [
                'title' => 'The heatfree hair difference',
                'body' => "{$businessName} curates natural-texture extensions, closures, ponytails, and care essentials designed to blend beautifully and last longer.",
            ]
            : [
                'title' => "About {$businessName}",
                'body' => $description,
            ]);
        $valueProps = $isCosmetics
            ? [
                ['title' => '100% organic', 'body' => 'Botanical ingredients chosen for gentle daily care.'],
                ['title' => 'Clinical feel', 'body' => 'Simple formulas that support comfort, glow, and consistency.'],
                ['title' => 'Herbal products', 'body' => 'Clean textures made to layer easily in any routine.'],
            ]
            : ($isBeauty
            ? [
                ['title' => 'Undetectable closures', 'body' => 'Seamless finishes made to blend naturally with your hairline.'],
                ['title' => 'Virgin textures', 'body' => 'Soft, full bundles selected for movement, body, and longevity.'],
                ['title' => 'Ready-to-style', 'body' => 'Curated textures, ponytails, and kits for salon-level looks.'],
            ]
            : [
                ['title' => 'Curated for your customers', 'body' => "A focused {$industryLabel} storefront built around what your buyers need most."],
                ['title' => 'Fast local delivery', 'body' => 'Most orders ship within 2-4 business days across Nigeria.'],
                ['title' => 'Built for trust', 'body' => 'Clear messaging, consistent branding, and a simple shopping experience from the first visit.'],
            ]);
        $products = $isCosmetics
            ? [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-botanical-gel-cleanser"), 'name' => 'Botanical Gel Cleanser', 'description' => "A gentle daily cleanser curated by {$businessName}.", 'price' => 18500, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Cleansers'],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-glow-repair-serum"), 'name' => 'Glow Repair Serum', 'description' => 'Lightweight botanical actives for visible radiance and hydration.', 'price' => 24000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Serums'],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-daily-radiance-kit"), 'name' => 'Daily Radiance Kit', 'description' => 'Customer favourites packed for a full skincare routine.', 'price' => 52000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Routine kits'],
            ]
            : ($isBeauty
            ? [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-kurl-wefted-hair"), 'name' => 'The Kurl Wefted Hair', 'description' => "Premium virgin curls from {$businessName}.", 'price' => 68000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Wefted hair'],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-kurl-closure"), 'name' => 'The Kurl Closure', 'description' => 'A seamless closure for fuller protective styling.', 'price' => 42000, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Closures'],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-extensions-care-kit"), 'name' => 'Extensions Care Kit', 'description' => 'Cleanse, condition, and protect every install.', 'price' => 14200, 'currency' => 'NGN', 'image_url' => null, 'category' => 'Care kits'],
            ]
            : [
                ['id' => '1', 'slug' => Str::slug("{$businessName}-signature-item"), 'name' => "{$businessName} Signature Item", 'description' => "A customer favourite from {$businessName}.", 'price' => 8500, 'currency' => 'NGN', 'image_url' => null],
                ['id' => '2', 'slug' => Str::slug("{$businessName}-starter-pack"), 'name' => "{$businessName} Starter Pack", 'description' => "A great way to try {$businessName} for the first time.", 'price' => 12500, 'currency' => 'NGN', 'image_url' => null],
                ['id' => '3', 'slug' => Str::slug("{$businessName}-premium-bundle"), 'name' => "{$businessName} Premium Bundle", 'description' => 'Our best-value bundle for repeat customers.', 'price' => 19900, 'currency' => 'NGN', 'image_url' => null],
            ]);

        return [
            'template' => [
                'id' => $templateId,
                'source' => ($store->storefront_template_id ?? 'ai_pick') === 'ai_pick' ? 'ai_selected' : 'merchant_selected',
            ],
            'palette' => $this->defaultStorefrontPalette($templateId, $store->brand_color ?? null),
            'data_plugs' => [
                'home_products_source' => 'merchant_products',
            ],
            'hero' => $hero,
            'about' => $about,
            'value_props' => $valueProps,
            'pages' => [
                'about' => [
                    'title' => $about['title'],
                    'body' => $about['body'],
                    'source' => 'ai_generated',
                ],
                'contact' => [
                    'title' => 'Contact us',
                    'body' => "Have a question about an order or product? Reach out and our team will get back to you shortly.",
                    'email' => $contactEmail,
                    'phone' => $store->merchant?->phone,
                    'source' => 'ai_generated',
                ],
                'faq' => [
                    'title' => 'Frequently asked questions',
                    'source' => 'ai_generated',
                    'items' => [
                        [
                            'question' => 'How do I place an order?',
                            'answer' => 'Browse products, add items to your cart, and complete checkout with your delivery details.',
                        ],
                        [
                            'question' => 'What payment methods do you accept?',
                            'answer' => 'We accept card payments and bank transfers through our secure checkout.',
                        ],
                        [
                            'question' => 'How long does delivery take?',
                            'answer' => 'Most orders arrive within 2-4 business days depending on your location.',
                        ],
                        [
                            'question' => 'Can I return an item?',
                            'answer' => 'Yes. Contact us within 7 days of delivery if something is not right with your order.',
                        ],
                    ],
                ],
                'privacy_policy' => [
                    'title' => 'Privacy policy',
                    'source' => 'platform_default',
                    'body' => "This privacy policy explains how {$businessName} and Storehaus collect, use, and protect your personal information when you shop on this storefront.\n\nWe collect information you provide at checkout such as your name, email, phone number, and delivery address. We use this information to process orders, communicate about your purchase, and improve our service.\n\nPayment details are processed securely by our payment partners. We do not store full card numbers on our servers.\n\nYou may contact us to request access to or correction of your personal data.".($contactEmail ? " Email: {$contactEmail}." : ''),
                ],
            ],
            'products' => $products,
            'seo' => [
                'title' => "{$businessName} | Online Store",
                'description' => Str::limit($description, 150, ''),
            ],
        ];
    }

    private function resolveStorefrontTemplate(Store $store): string
    {
        $templateId = $store->storefront_template_id ?? 'ai_pick';

        if (in_array($templateId, StorefrontTemplate::concreteIds(), true)) {
            return $templateId;
        }

        $industry = $store->merchant?->industry ?? 'other';
        $activeTemplateIds = StorefrontTemplate::activeConcreteIds();
        $firstActive = fn (array $ids, string $fallback): string => collect($ids)
            ->first(fn (string $id): bool => in_array($id, $activeTemplateIds, true), $fallback);

        if ($industry === 'beauty_and_skincare') {
            return $firstActive(['cosmetics', 'beauty', 'minimalistic'], 'minimalistic');
        }

        if ($industry === 'fashion_and_apparel') {
            return $firstActive(['fashion_lookbook', 'minimalistic'], 'minimalistic');
        }

        if (in_array($industry, ['electronics', 'food_and_beverage', 'home_and_living'], true)) {
            return $firstActive(['minimalistic', 'cosmetics'], 'minimalistic');
        }

        return in_array('minimalistic', $activeTemplateIds, true) ? 'minimalistic' : ($activeTemplateIds[0] ?? 'minimalistic');
    }

    private function defaultStorefrontPalette(string $templateId, ?string $brandColor = null): array
    {
        return match ($templateId) {
            'cosmetics' => [
                'primary' => $brandColor ?: '#82934C',
                'accent' => '#F7E7D3',
                'background' => '#FFFFFF',
                'surface' => '#F4F6F1',
                'text' => '#172012',
                'muted' => '#6E7564',
                'border' => '#E2E6D9',
            ],
            'beauty' => [
                'primary' => $brandColor ?: '#6F2F2B',
                'accent' => '#E6A79F',
                'background' => '#FFF7F3',
                'surface' => '#FFFFFF',
                'text' => '#211313',
                'muted' => '#80615C',
                'border' => '#F0D6D0',
            ],
            'minimalistic' => [
                'primary' => $brandColor ?: '#073E3F',
                'accent' => '#D99359',
                'background' => '#FBFBDC',
                'surface' => '#FFFFFF',
                'text' => '#073E3F',
                'muted' => '#5F7A6F',
                'border' => '#D8DEC1',
            ],
            'fashion_lookbook' => [
                'primary' => $brandColor ?: '#111111',
                'accent' => '#80131B',
                'background' => '#FFFFFF',
                'surface' => '#EEF0EF',
                'text' => '#111111',
                'muted' => '#6E6E6E',
                'border' => '#E3E3E3',
            ],
            'editorial' => [
                'primary' => $brandColor ?: '#7C3A2D',
                'accent' => '#D8A48F',
                'background' => '#FFFFFF',
                'surface' => '#F8F3F0',
                'text' => '#241613',
                'muted' => '#75615B',
                'border' => '#E8DAD5',
            ],
            'bold_grid' => [
                'primary' => $brandColor ?: '#0F4C81',
                'accent' => '#F59E0B',
                'background' => '#FFFFFF',
                'surface' => '#F3F7FB',
                'text' => '#102033',
                'muted' => '#607085',
                'border' => '#DCE7F2',
            ],
            default => [
                'primary' => $brandColor ?: '#1F6F5B',
                'accent' => '#F4B860',
                'background' => '#FFFFFF',
                'surface' => '#F7FAF8',
                'text' => '#10201B',
                'muted' => '#64736E',
                'border' => '#DCE7E1',
            ],
        };
    }

    private function findStoreByHost(string $host): ?Store
    {
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');

        if (str_ends_with($host, '.'.$platformDomain)) {
            $prefix = substr($host, 0, -strlen($platformDomain) - 1);
            $subdomain = explode('.', $prefix)[0] ?? '';

            if ($subdomain === '' || in_array($subdomain, $this->reservedSubdomains(), true)) {
                return null;
            }

            return Store::with('merchant')->where('slug', $subdomain)->first();
        }

        if (str_ends_with($host, '.localhost')) {
            $subdomain = explode('.', substr($host, 0, -strlen('.localhost')))[0] ?? '';

            if ($subdomain === '' || in_array($subdomain, $this->reservedSubdomains(), true)) {
                return null;
            }

            return Store::with('merchant')->where('slug', $subdomain)->first();
        }

        return Store::with('merchant')->where('primary_domain', $host)->first();
    }

    /** @return list<string> */
    private function reservedSubdomains(): array
    {
        return [
            'www',
            'app',
            'api',
            'admin',
            'dashboard',
            'portal',
            'docs',
            'help',
            'status',
            'blog',
            'mail',
            'static',
            'assets',
            'cdn',
        ];
    }

    private function formatPublicPayload(Store $store): array
    {
        return [
            'store' => $this->formatStore($store),
            'storefront' => $store->storefront_content ?? $this->synthesizeStorefront($store),
            'generation_id' => $store->storefront_generation_id,
        ];
    }

    private function uniqueMerchantSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Merchant::where('slug', $slug)->exists());
    }

    private function uniqueStoreSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Store::where('slug', $slug)->exists());
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $orderNumber = 'SH-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        } while (StoreOrder::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function uniqueSlug(string $name, callable $exists): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
