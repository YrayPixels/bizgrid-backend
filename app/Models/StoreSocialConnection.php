<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreSocialConnection extends Model
{
    protected $fillable = [
        'store_id',
        'provider',
        'provider_account_id',
        'page_id',
        'page_name',
        'page_access_token',
        'token_expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(SocialPost::class, 'social_connection_id');
    }
}
