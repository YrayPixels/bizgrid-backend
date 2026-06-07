<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrossmintOrder extends Model
{
    use HasFactory;

    protected $table = 'crossmint_orders';

    protected $fillable = [
        'wallet_address',
        'recipient_email',
        'shipping_address',
        'crossmint_order_id',
        'asin',
        'order_number',
        'status',
        'total_amount',
        'currency',
        'payment_status',
        'order_date',
        'raw_response',
    ];

    protected $casts = [
        'order_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'raw_response' => 'array',
        'shipping_address' => 'array',
    ];
}
