<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppMerchantSession extends Model
{
    public const STATE_NEW = 'new';

    public const STATE_AWAITING_NAME = 'awaiting_name';

    public const STATE_AWAITING_LINK_CODE = 'awaiting_link_code';

    public const STATE_AWAITING_STORE_NAME = 'awaiting_store_name';

    public const STATE_IDLE = 'idle';

    public const STATE_ADDING_PRODUCT = 'adding_product';

    public const STATE_AWAITING_SHIP_TARGET = 'awaiting_ship_target';

    protected $table = 'whatsapp_merchant_sessions';

    protected $fillable = [
        'phone',
        'user_id',
        'state',
        'context',
        'last_provider_message_id',
        'last_inbound_at',
        'last_outbound_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMerchantMessage::class, 'whatsapp_merchant_session_id');
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeContext(array $patch): void
    {
        $this->context = array_merge($this->context ?? [], $patch);
    }

    public function clearContext(): void
    {
        $this->context = [];
    }

    public function hasOpenServiceWindow(): bool
    {
        return $this->last_inbound_at !== null
            && $this->last_inbound_at->gte(now()->subHours(24));
    }
}
