<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'business_name',
        'slug',
        'contact_name',
        'email',
        'phone',
        'industry',
        'status',
        'subscription_plan',
        'subscription_status',
        'activated_at',
        'suspended_at',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
