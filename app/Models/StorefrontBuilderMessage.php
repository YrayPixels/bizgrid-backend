<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontBuilderMessage extends Model
{
    protected $fillable = [
        'session_id',
        'role',
        'content',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StorefrontBuilderSession::class, 'session_id');
    }
}
