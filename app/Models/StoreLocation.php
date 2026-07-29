<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'city',
        'state',
        'area',
        'delivery_fee',
        'free_shipping_enabled',
        'free_shipping_min_subtotal',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'free_shipping_enabled' => 'boolean',
            'delivery_fee' => 'decimal:2',
            'free_shipping_min_subtotal' => 'decimal:2',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(MerchantStaff::class, 'default_location_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class, 'location_id');
    }
}
