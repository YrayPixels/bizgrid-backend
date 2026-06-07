<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'slug',
        'status',
        'primary_domain',
        'description',
        'brand_color',
        'logo_url',
        'storefront_template_id',
        'storefront_content',
        'storefront_generation_id',
        'products_count',
        'orders_count',
        'gross_revenue',
    ];

    protected function casts(): array
    {
        return [
            'gross_revenue' => 'decimal:2',
            'storefront_content' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(StoreVisit::class);
    }
}
