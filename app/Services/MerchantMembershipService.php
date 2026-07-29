<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantStaff;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MerchantMembershipService
{
    public const ROLE_OWNER = 'owner';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_CASHIER = 'cashier';

    /**
     * @return array{
     *   merchant: Merchant,
     *   role: string,
     *   staff: ?MerchantStaff,
     *   is_owner: bool,
     * }|null
     */
    public function membershipFor(User $user): ?array
    {
        $owned = Merchant::query()->where('owner_user_id', $user->id)->first();
        $staff = MerchantStaff::query()
            ->with('merchant')
            ->where('user_id', $user->id)
            ->where('status', MerchantStaff::STATUS_ACTIVE)
            ->first();

        // Google/password flows used to create a pending merchant for staff users.
        // Prefer the employer membership when that orphan has no store yet.
        if ($owned && $staff && $staff->merchant && ! $owned->stores()->exists()) {
            return [
                'merchant' => $staff->merchant,
                'role' => $staff->role,
                'staff' => $staff,
                'is_owner' => false,
            ];
        }

        if ($owned) {
            return [
                'merchant' => $owned,
                'role' => self::ROLE_OWNER,
                'staff' => null,
                'is_owner' => true,
            ];
        }

        if (! $staff || ! $staff->merchant) {
            return null;
        }

        return [
            'merchant' => $staff->merchant,
            'role' => $staff->role,
            'staff' => $staff,
            'is_owner' => false,
        ];
    }

    /**
     * Remove a pending/empty merchant accidentally created for an active staff user
     * (e.g. Google sign-in before staff-aware auth).
     */
    public function discardOrphanOwnerMerchantForStaff(User $user): void
    {
        $isStaff = MerchantStaff::query()
            ->where('user_id', $user->id)
            ->where('status', MerchantStaff::STATUS_ACTIVE)
            ->exists();

        if (! $isStaff) {
            return;
        }

        $owned = Merchant::query()->where('owner_user_id', $user->id)->first();
        if (! $owned || $owned->stores()->exists()) {
            return;
        }

        $owned->delete();
    }

    public function merchantFor(User $user): ?Merchant
    {
        return $this->membershipFor($user)['merchant'] ?? null;
    }

    public function roleFor(User $user): ?string
    {
        return $this->membershipFor($user)['role'] ?? null;
    }

    public function isOwner(User $user): bool
    {
        return ($this->membershipFor($user)['is_owner'] ?? false) === true;
    }

    public function canAccessAdmin(User $user): bool
    {
        $role = $this->roleFor($user);

        return in_array($role, [self::ROLE_OWNER, self::ROLE_MANAGER], true);
    }

    public function canSell(User $user): bool
    {
        $role = $this->roleFor($user);

        return in_array($role, [self::ROLE_OWNER, self::ROLE_MANAGER, self::ROLE_CASHIER], true);
    }

    public function canManageStaff(User $user): bool
    {
        $membership = $this->membershipFor($user);
        if (! $membership) {
            return false;
        }

        if ($membership['is_owner']) {
            return true;
        }

        return $membership['staff']?->canManageStaff() === true;
    }

    public function belongsToMerchant(User $user, Merchant|int $merchant): bool
    {
        $merchantId = $merchant instanceof Merchant ? (int) $merchant->id : (int) $merchant;
        $membership = $this->membershipFor($user);

        return $membership !== null && (int) $membership['merchant']->id === $merchantId;
    }

    public function storeForUser(User $user): ?Store
    {
        $merchant = $this->merchantFor($user);
        if (! $merchant) {
            return null;
        }

        return Store::query()
            ->with('merchant')
            ->where('merchant_id', $merchant->id)
            ->latest()
            ->first();
    }

    public function ensureDefaultLocation(Store $store): StoreLocation
    {
        $existing = StoreLocation::query()
            ->where('store_id', $store->id)
            ->where('is_default', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $any = StoreLocation::query()->where('store_id', $store->id)->first();
        if ($any) {
            $any->is_default = true;
            $any->save();

            return $any;
        }

        return StoreLocation::query()->create([
            'store_id' => $store->id,
            'name' => 'Main',
            'is_default' => true,
        ]);
    }

    public function postLoginRedirect(User $user): string
    {
        $membership = $this->membershipFor($user);
        if (! $membership) {
            return '/admin/onboarding';
        }

        if ($membership['is_owner']) {
            $hasStore = $membership['merchant']->stores()->exists();

            return $hasStore ? '/admin' : '/admin/onboarding';
        }

        // Staff always land on the sell screen.
        return '/sell';
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMembership(User $user): array
    {
        $membership = $this->membershipFor($user);
        if (! $membership) {
            return [
                'role' => null,
                'merchant_id' => null,
                'can_access_admin' => false,
                'can_sell' => false,
                'can_manage_staff' => false,
                'default_location_id' => null,
                'redirect' => '/login',
            ];
        }

        $staff = $membership['staff'];

        return [
            'role' => $membership['role'],
            'merchant_id' => (string) $membership['merchant']->id,
            'can_access_admin' => $this->canAccessAdmin($user),
            'can_sell' => $this->canSell($user),
            'can_manage_staff' => $this->canManageStaff($user),
            'default_location_id' => $staff?->default_location_id
                ? (string) $staff->default_location_id
                : null,
            'redirect' => $this->postLoginRedirect($user),
        ];
    }

    public function createStaffUser(
        Merchant $merchant,
        string $name,
        string $email,
        string $password,
        string $role,
        ?int $locationId = null,
    ): MerchantStaff {
        return DB::transaction(function () use ($merchant, $name, $email, $password, $role, $locationId) {
            $user = User::query()->where('email', strtolower($email))->first();
            if ($user) {
                if ((int) $merchant->owner_user_id === (int) $user->id) {
                    throw new \InvalidArgumentException('The store owner cannot be added as staff.');
                }

                $existing = MerchantStaff::query()
                    ->where('user_id', $user->id)
                    ->first();
                if ($existing && (int) $existing->merchant_id !== (int) $merchant->id) {
                    throw new \InvalidArgumentException('This user already belongs to another merchant.');
                }
                if ($existing && (int) $existing->merchant_id === (int) $merchant->id) {
                    throw new \InvalidArgumentException('This user is already on your staff.');
                }
            } else {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => strtolower($email),
                    'password' => $password,
                    'email_verified_at' => now(),
                ]);
            }

            return MerchantStaff::query()->create([
                'merchant_id' => $merchant->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => MerchantStaff::STATUS_ACTIVE,
                'default_location_id' => $locationId,
            ]);
        });
    }
}
