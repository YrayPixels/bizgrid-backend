<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'store_customer_id',
        'order_number',
        'invoice_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'delivery_address',
        'delivery_method',
        'delivery_fee',
        'status',
        'payment_status',
        'paystack_reference',
        'currency',
        'subtotal',
        'discount_amount',
        'discount_label',
        'total_amount',
        'items',
        'notes',
        'tracking_number',
        'placed_at',
        'paid_at',
        'stock_restored_at',
        'shipped_at',
        'settlement_status',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'items' => 'array',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'stock_restored_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(StoreCustomer::class, 'store_customer_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }
}
