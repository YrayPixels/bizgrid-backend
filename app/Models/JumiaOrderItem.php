<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JumiaOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'jumia_order_id',
        'product_id',
        'product_name',
        'product_sku',
        'quantity',
        'unit_price',
        'total_price',
        'product_image_url',
        'product_url',
        'category',
        'brand',
        'size',
        'color',
        'weight',
        'dimensions'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'weight' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(JumiaOrder::class, 'jumia_order_id');
    }
}
