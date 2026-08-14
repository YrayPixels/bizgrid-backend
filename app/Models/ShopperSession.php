<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopperSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const MAX_MESSAGES = 24;

    public const TTL_DAYS = 7;

    protected $fillable = [
        'store_id',
        'client_key',
        'messages',
        'last_recommendation',
        'last_intent',
        'suggestions',
        'last_message_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'last_recommendation' => 'array',
            'last_intent' => 'array',
            'suggestions' => 'array',
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function transcript(): array
    {
        $messages = is_array($this->messages) ? $this->messages : [];
        $out = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }
            $out[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $out;
    }

    public function touchExpiry(): void
    {
        $this->last_message_at = now();
        $this->expires_at = now()->addDays(self::TTL_DAYS);
    }
}
