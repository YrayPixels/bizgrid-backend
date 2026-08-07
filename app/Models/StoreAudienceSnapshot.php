<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAudienceSnapshot extends Model
{
    protected $fillable = [
        'store_id',
        'social_connection_id',
        'provider',
        'age_gender',
        'countries',
        'total_audience',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'age_gender' => 'array',
            'countries' => 'array',
            'total_audience' => 'integer',
            'captured_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(StoreSocialConnection::class, 'social_connection_id');
    }
}
