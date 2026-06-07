<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'school_id',
        'channel',
        'audience',
        'title',
        'body',
        'recipient_count',
        'recipients',
        'status',
        'sent_at',
        'created_by',
    ];

    protected $casts = [
        'recipients' => 'array',
        'sent_at' => 'datetime',
    ];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
