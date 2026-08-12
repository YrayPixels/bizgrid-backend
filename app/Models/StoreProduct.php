<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreProduct extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'store_id',
        'slug',
        'name',
        'description',
        'price',
        'sale_price',
        'currency',
        'image_url',
        'images',
        'sku',
        'barcode',
        'brand',
        'category',
        'category_id',
        'stock_quantity',
        'status',
        'variants',
        'perks',
        'try_on',
        'style_profile',
        'sort_order',
        'id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'variants' => 'array',
            'perks' => 'array',
            'try_on' => 'array',
            'style_profile' => 'array',
            'images' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(StoreCategory::class, 'category_id');
    }
}
