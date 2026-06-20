<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreContactInquiry extends Model
{
    protected $fillable = [
        'store_id',
        'block_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'message',
        'fields',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
