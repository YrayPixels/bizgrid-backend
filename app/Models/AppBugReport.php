<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppBugReport extends Model
{
    protected $fillable = [
        'user_id',
        'wallet_address',
        'type',
        'severity',
        'status',
        'title',
        'summary',
        'details',
        'stack_trace',
        'source',
        'app_version',
        'platform',
        'device_info',
        'metadata',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
