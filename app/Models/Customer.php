<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'google_id',
        'email',
        'name',
        'avatar_url',
        'email_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'customer_stores')
            ->withPivot(['first_seen_at', 'last_seen_at'])
            ->withTimestamps();
    }

    public function tryOnSessions(): HasMany
    {
        return $this->hasMany(TryOnSession::class);
    }
}
