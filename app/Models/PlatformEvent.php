<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'event',
        'source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'user_agent',
        'ip_hash',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
