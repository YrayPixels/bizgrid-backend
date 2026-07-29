<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Store;
use App\Models\User;
use App\Services\MerchantMembershipService;

class StorePolicy
{
    public function __construct(private MerchantMembershipService $membership) {}

    public function view(User $user, Store $store): bool
    {
        return $this->belongs($user, $store);
    }

    public function update(User $user, Store $store): bool
    {
        return $this->membership->canAccessAdmin($user) && $this->belongs($user, $store);
    }

    public function uploadImage(User $user, Store $store): bool
    {
        return $this->update($user, $store);
    }

    public function publish(User $user, Store $store): bool
    {
        return $this->update($user, $store);
    }

    public function sell(User $user, Store $store): bool
    {
        return $this->membership->canSell($user) && $this->belongs($user, $store);
    }

    private function belongs(User $user, Store $store): bool
    {
        $store->loadMissing('merchant');
        if (! $store->merchant) {
            return false;
        }

        return $this->membership->belongsToMerchant($user, $store->merchant);
    }
}
