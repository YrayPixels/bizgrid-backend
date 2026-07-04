<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'direction',
        'body',
        'provider_message_id',
        'ai_generated',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'ai_generated' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CustomerConversation::class, 'conversation_id');
    }
}
