<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\BizfestApplication;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBizfestApplicationController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Refresh matches for visible rows so admin always sees current store status.
        $query = BizfestApplication::query()
            ->with([
                'store:id,name,slug,status,published_at,merchant_id,contact_email',
                'merchant:id,business_name,slug,owner_user_id',
                'user:id,name,email',
            ])
            ->orderByDesc('created_at');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('has_store')) {
            if ($request->has_store === 'yes') {
                $query->where('has_store', true);
            } elseif ($request->has_store === 'no') {
                $query->where('has_store', false);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('owner_name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $applications = $query->paginate($perPage);

        foreach ($applications->getCollection() as $application) {
            BizfestApplicationController::matchStore($application);
            $application->load([
                'store:id,name,slug,status,published_at,merchant_id,contact_email',
                'merchant:id,business_name,slug,owner_user_id',
                'user:id,name,email',
            ]);
        }

        $stats = [
            'total' => BizfestApplication::query()->count(),
            'new' => BizfestApplication::query()->where('status', 'new')->count(),
            'with_store' => BizfestApplication::query()->where('has_store', true)->count(),
            'published' => BizfestApplication::query()->where('store_published', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $applications->getCollection()->map(fn ($app) => $this->format($app)),
            'stats' => $stats,
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $application = BizfestApplication::query()->find($id);
        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Application not found'], 404);
        }

        BizfestApplicationController::matchStore($application);
        $application->load(['store', 'merchant', 'user']);

        return response()->json([
            'success' => true,
            'data' => $this->format($application, true),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:new,reviewed,shortlisted,rejected,winner',
        ]);

        $application = BizfestApplication::find($id);
        if (! $application) {
            return response()->json(['success' => false, 'message' => 'Application not found'], 404);
        }

        $application->status = $data['status'];
        $application->save();

        $this->audit->log($request, 'bizfest.status_updated', 'bizfest_application', $application->id, [
            'status' => $data['status'],
        ]);

        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Application updated',
            'data' => $this->format($application->load(['store', 'merchant', 'user']), true),
        ]);
    }

    private function format(BizfestApplication $application, bool $detailed = false): array
    {
        $store = $application->store;
        $merchant = $application->merchant;

        $base = [
            'id' => $application->id,
            'programme' => $application->programme,
            'owner_name' => $application->owner_name,
            'business_name' => $application->business_name,
            'email' => $application->email,
            'phone' => $application->phone,
            'category' => $application->category,
            'city' => $application->city,
            'status' => $application->status,
            'has_store' => (bool) $application->has_store,
            'store_published' => (bool) $application->store_published,
            'team_type' => $application->team_type,
            'how_heard' => $application->how_heard,
            'followed_social' => (bool) $application->followed_social,
            'matched_at' => $application->matched_at?->toIso8601String(),
            'created_at' => $application->created_at?->toIso8601String(),
            'store' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'published_at' => $store->published_at?->toIso8601String(),
            ] : null,
            'merchant' => $merchant ? [
                'id' => $merchant->id,
                'business_name' => $merchant->business_name,
                'slug' => $merchant->slug,
            ] : null,
        ];

        if (! $detailed) {
            return $base;
        }

        return array_merge($base, [
            'what_you_sell' => $application->what_you_sell,
            'sell_channels' => $application->sell_channels,
            'unique_value' => $application->unique_value,
            'online_presence_url' => $application->online_presence_url,
            'utm_source' => $application->utm_source,
            'utm_medium' => $application->utm_medium,
            'utm_campaign' => $application->utm_campaign,
            'user' => $application->user ? [
                'id' => $application->user->id,
                'name' => $application->user->name,
                'email' => $application->user->email,
            ] : null,
        ]);
    }
}
