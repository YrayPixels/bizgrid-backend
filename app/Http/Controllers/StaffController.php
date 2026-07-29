<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\MerchantStaff;
use App\Models\StoreLocation;
use App\Services\MerchantMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    use StorehauseHelpers;

    public function __construct(private readonly MerchantMembershipService $membership) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $merchant = $store->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Merchant not found.'], 404);
        }

        $staff = MerchantStaff::query()
            ->with(['user', 'defaultLocation'])
            ->where('merchant_id', $merchant->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (MerchantStaff $row) => $this->formatStaff($row))
            ->values();

        $merchant->loadMissing('owner');

        return response()->json([
            'data' => $staff,
            'owner' => [
                'id' => (string) $merchant->owner_user_id,
                'name' => $merchant->owner?->name ?? $merchant->contact_name,
                'email' => $merchant->owner?->email ?? $merchant->email,
                'role' => 'owner',
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $merchant = $store->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Merchant not found.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:120',
            'role' => ['required', 'string', Rule::in([MerchantStaff::ROLE_MANAGER, MerchantStaff::ROLE_CASHIER])],
            'location_id' => 'nullable|integer',
        ]);

        if (isset($data['location_id'])) {
            $locationOk = StoreLocation::query()
                ->where('store_id', $store->id)
                ->where('id', $data['location_id'])
                ->exists();
            if (! $locationOk) {
                throw ValidationException::withMessages([
                    'location_id' => ['Selected location was not found.'],
                ]);
            }
        }

        try {
            $staff = $this->membership->createStaffUser(
                $merchant,
                $data['name'],
                $data['email'],
                $data['password'],
                $data['role'],
                isset($data['location_id']) ? (int) $data['location_id'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'email' => [$e->getMessage()],
            ]);
        }

        $staff->load(['user', 'defaultLocation']);

        $this->invalidateMerchantApiCache((int) $merchant->id);
        $this->invalidateUserApiCache((int) $request->user()->id);

        return response()->json([
            'staff' => $this->formatStaff($staff),
        ], 201);
    }

    public function update(Request $request, string $staffId): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $merchant = $store->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Merchant not found.'], 404);
        }

        $staff = MerchantStaff::query()
            ->with(['user', 'defaultLocation'])
            ->where('merchant_id', $merchant->id)
            ->where('id', $staffId)
            ->first();

        if (! $staff) {
            return response()->json(['message' => 'Staff member not found.'], 404);
        }

        $data = $request->validate([
            'role' => ['sometimes', 'string', Rule::in([MerchantStaff::ROLE_MANAGER, MerchantStaff::ROLE_CASHIER])],
            'status' => ['sometimes', 'string', Rule::in([
                MerchantStaff::STATUS_ACTIVE,
                MerchantStaff::STATUS_DISABLED,
            ])],
            'location_id' => 'nullable|integer',
            'name' => 'sometimes|string|max:120',
        ]);

        if (array_key_exists('location_id', $data) && $data['location_id'] !== null) {
            $locationOk = StoreLocation::query()
                ->where('store_id', $store->id)
                ->where('id', $data['location_id'])
                ->exists();
            if (! $locationOk) {
                throw ValidationException::withMessages([
                    'location_id' => ['Selected location was not found.'],
                ]);
            }
            $staff->default_location_id = (int) $data['location_id'];
        } elseif (array_key_exists('location_id', $data) && $data['location_id'] === null) {
            $staff->default_location_id = null;
        }

        if (isset($data['role'])) {
            $staff->role = $data['role'];
        }
        if (isset($data['status'])) {
            $staff->status = $data['status'];
        }
        $staff->save();

        if (isset($data['name']) && $staff->user) {
            $staff->user->name = $data['name'];
            $staff->user->save();
        }

        $staff->load(['user', 'defaultLocation']);

        $this->invalidateMerchantApiCache((int) $merchant->id);
        $this->invalidateUserApiCache((int) $request->user()->id);

        return response()->json([
            'staff' => $this->formatStaff($staff),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatStaff(MerchantStaff $staff): array
    {
        return [
            'id' => (string) $staff->id,
            'user_id' => (string) $staff->user_id,
            'name' => $staff->user?->name ?? '',
            'email' => $staff->user?->email ?? '',
            'role' => $staff->role,
            'status' => $staff->status,
            'default_location_id' => $staff->default_location_id
                ? (string) $staff->default_location_id
                : null,
            'default_location_name' => $staff->defaultLocation?->name,
            'created_at' => $staff->created_at?->toIso8601String(),
        ];
    }
}
