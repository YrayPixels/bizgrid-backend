<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BizfestApplication extends Model
{
    protected $fillable = [
        'programme',
        'owner_name',
        'business_name',
        'email',
        'phone',
        'category',
        'city',
        'what_you_sell',
        'sell_channels',
        'unique_value',
        'online_presence_url',
        'how_heard',
        'team_type',
        'followed_social',
        'status',
        'user_id',
        'merchant_id',
        'store_id',
        'has_store',
        'store_published',
        'matched_at',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'has_store' => 'boolean',
            'store_published' => 'boolean',
            'followed_social' => 'boolean',
            'matched_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
