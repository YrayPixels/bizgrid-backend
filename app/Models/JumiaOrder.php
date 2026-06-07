<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JumiaOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'delivery_address_id',
        'order_number',
        'jumia_order_id',
        'status',
        'total_amount',
        'currency',
        'payment_method',
        'payment_status',
        'order_date',
        'estimated_delivery_date',
        'notes',
        'tracking_number',
        'delivery_fee',
        'tax_amount',
        'subtotal',
        'discount_amount',
        'coupon_code',
        'is_express_delivery',
        'delivery_instructions'
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'estimated_delivery_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'is_express_delivery' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(JumiaDeliveryAddress::class, 'delivery_address_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(JumiaOrderItem::class);
    }

    public function orderHistory(): HasMany
    {
        return $this->hasMany(JumiaOrderHistory::class);
    }
}
