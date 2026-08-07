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
        'status',
        'last_checked_at',
        'invalid_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Tokens inside this window still work but the merchant needs to reconnect
     * before they lapse, so the UI can nudge ahead of the first failed publish.
     */
    public function isExpiringSoon(int $days = 7): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isFuture()
            && $this->token_expires_at->diffInDays(now()) <= $days;
    }

    public function isUsable(): bool
    {
        if ($this->status === 'invalid') {
            return false;
        }

        return $this->token_expires_at === null || $this->token_expires_at->isFuture();
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
