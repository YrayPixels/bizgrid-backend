<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreCustomer extends Model
{
    protected $fillable = [
        'store_id',
        'email',
        'phone',
        'name',
        'orders_count',
        'total_spent',
        'first_order_at',
        'last_order_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_spent' => 'decimal:2',
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class, 'store_customer_id');
    }
}
