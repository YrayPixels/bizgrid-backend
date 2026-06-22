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
        'contact_email',
        'contact_phone',
        'business_location',
        'weekly_orders',
        'payment_currencies',
        'staff_count',
        'physical_store_count',
        'storefront_template_id',
        'storefront_content',
        'draft_json',
        'published_json',
        'published_at',
        'storefront_generation_id',
        'products_count',
        'orders_count',
        'gross_revenue',
    ];

    protected function casts(): array
    {
        return [
            'gross_revenue' => 'decimal:2',
            'payment_currencies' => 'array',
            'storefront_content' => 'array',
            'draft_json' => 'array',
            'published_json' => 'array',
            'published_at' => 'datetime',
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

    public function products(): HasMany
    {
        return $this->hasMany(StoreProduct::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(StoreCategory::class);
    }
}
