<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreRecoveryOutreach extends Model
{
    protected $table = 'store_recovery_outreach';

    protected $fillable = [
        'store_id',
        'source_type',
        'source_id',
        'channel',
        'subject',
        'message',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
