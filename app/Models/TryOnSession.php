<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TryOnSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'store_id',
        'product_id',
        'mode',
        'status',
        'provider',
        'provider_task_id',
        'src_image_url',
        'ref_image_url',
        'result_url',
        'gender',
        'style',
        'garment_category',
        'error_code',
        'error_message',
        'poll_attempts',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'poll_attempts' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'product_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['success', 'error'], true);
    }
}
