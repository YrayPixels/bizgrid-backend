<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    public function update(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    public function uploadImage(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    public function publish(User $user, Store $store): bool
    {
        return $this->owns($user, $store);
    }

    private function owns(User $user, Store $store): bool
    {
        $store->loadMissing('merchant');

        return (int) $store->merchant?->owner_user_id === (int) $user->id;
    }
}
