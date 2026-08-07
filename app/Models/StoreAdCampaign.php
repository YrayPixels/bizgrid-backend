<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAdCampaign extends Model
{
    protected $fillable = [
        'store_id',
        'social_connection_id',
        'provider',
        'name',
        'objective',
        'status',
        'daily_budget_minor',
        'currency',
        'start_at',
        'end_at',
        'targeting',
        'creative',
        'external_campaign_id',
        'external_adset_id',
        'external_creative_id',
        'external_ad_id',
        'metrics',
        'metrics_synced_at',
        'error_message',
        'approved_at',
        'approved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'targeting' => 'array',
            'creative' => 'array',
            'metrics' => 'array',
            'metrics_synced_at' => 'datetime',
            'approved_at' => 'datetime',
            'daily_budget_minor' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreSocialConnection::class, 'social_connection_id');
    }
}
