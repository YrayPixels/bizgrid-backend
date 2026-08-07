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
        'scheduled_for',
        'approved_at',
        'approved_by_user_id',
        'message',
        'link_url',
        'video_url',
        'image_url',
        'external_post_id',
        'publish_id',
        'metadata',
        'insights',
        'insights_synced_at',
        'sentiment',
        'sentiment_synced_at',
        'attempts',
        'error_message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'approved_at' => 'datetime',
            'insights_synced_at' => 'datetime',
            'sentiment_synced_at' => 'datetime',
            'metadata' => 'array',
            'insights' => 'array',
            'sentiment' => 'array',
            'attempts' => 'integer',
        ];
    }

    /**
     * A post the merchant has approved and parked for a future publish run.
     */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_for !== null;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled', 'failed'], true);
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
