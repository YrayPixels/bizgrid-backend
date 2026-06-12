<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorefrontBuilderSession extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'status',
        'business_profile',
        'selected_template_id',
        'storefront_snapshot',
        'last_intent',
    ];

    protected function casts(): array
    {
        return [
            'business_profile' => 'array',
            'storefront_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(StorefrontBuilderMessage::class, 'session_id');
    }
}
