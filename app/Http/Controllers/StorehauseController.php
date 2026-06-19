<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StorefrontTemplate;
use App\Models\StoreVisit;
use App\Models\User;
use App\Services\StorefrontBuilderService;
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
    public function __construct(
        private readonly StorefrontBuilderService $builderService,
    ) {}

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
            'storefront' => 'nullable|array',
        ]);

        $store = $this->findOwnedStore($request, (int) $data['store_id']);
        if (isset($data['storefront_template_id'])) {
            $store->storefront_template_id = $data['storefront_template_id'];
        }

        if (! empty($data['storefront'])) {
            $storefront = $data['storefront'];
        } else {
            $storefront = $this->builderService->synthesizeStorefront($store);
        }

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
            'storefront' => $store->storefront_content ?? $this->builderService->synthesizeStorefront($store),
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
