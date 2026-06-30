<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'has_store' => Merchant::where('owner_user_id', $this->id)->whereHas('stores')->exists(),
            'is_admin' => (bool) $this->is_admin,
        ];
    }
}
