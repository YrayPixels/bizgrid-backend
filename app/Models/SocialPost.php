<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPost extends Model
{
    protected $fillable = [
        'store_id',
        'social_connection_id',
        'provider',
        'post_type',
        'status',
        'message',
        'link_url',
        'video_url',
        'image_url',
        'external_post_id',
        'publish_id',
        'error_message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
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
