<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAbandonedCart extends Model
{
    protected $fillable = [
        'store_id',
        'session_token',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address',
        'items',
        'subtotal',
        'currency',
        'status',
        'converted_order_id',
        'last_activity_at',
        'recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'converted_order_id');
    }
}
