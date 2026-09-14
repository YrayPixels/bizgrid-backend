<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminEmailThread extends Model
{
    protected $fillable = [
        'mailbox_provider_id',
        'subject',
        'subject_normalized',
        'participants',
        'last_message_at',
        'last_direction',
        'status',
        'message_count',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'metadata' => 'array',
            'last_message_at' => 'datetime',
            'message_count' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AdminEmailMessage::class, 'thread_id');
    }
}
