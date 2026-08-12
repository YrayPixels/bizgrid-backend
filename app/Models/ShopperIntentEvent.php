<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopperIntentEvent extends Model
{
    protected $fillable = [
        'store_id',
        'merchant_id',
        'session_id',
        'message',
        'chips',
        'action',
        'product_query',
        'categories',
        'attributes',
        'budget_max',
        'use_case',
        'occasion',
        'interpretation_summary',
        'had_recommendation',
        'within_budget',
        'recommended_product_ids',
        'recommended_product_names',
        'needs_clarification',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'chips' => 'array',
            'categories' => 'array',
            'attributes' => 'array',
            'budget_max' => 'float',
            'had_recommendation' => 'boolean',
            'within_budget' => 'boolean',
            'recommended_product_ids' => 'array',
            'recommended_product_names' => 'array',
            'needs_clarification' => 'boolean',
            'logged_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
