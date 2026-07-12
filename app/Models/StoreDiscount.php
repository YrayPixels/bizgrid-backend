<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDiscount extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'store_id',
        'name',
        'type',
        'discount_type',
        'discount_value',
        'min_subtotal',
        'product_ids',
        'starts_at',
        'ends_at',
        'status',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_subtotal' => 'decimal:2',
            'product_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
