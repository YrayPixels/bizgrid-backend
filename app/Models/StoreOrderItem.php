<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreOrderItem extends Model
{
    protected $fillable = [
        'store_order_id',
        'store_id',
        'product_id',
        'name',
        'quantity',
        'unit_price',
        'compare_at_price',
        'discount_label',
        'line_total',
        'currency',
        'image_url',
        'selected_options',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'selected_options' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class, 'store_order_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLineArray(): array
    {
        return [
            'product_id' => (string) ($this->product_id ?? ''),
            'name' => $this->name,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'discount_label' => $this->discount_label,
            'total' => (float) $this->line_total,
            'currency' => $this->currency,
            'image_url' => $this->image_url,
            'selected_options' => $this->selected_options ?? [],
        ];
    }
}
