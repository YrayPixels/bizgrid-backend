<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMerchantMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $table = 'whatsapp_merchant_messages';

    protected $fillable = [
        'whatsapp_merchant_session_id',
        'phone',
        'direction',
        'message_type',
        'body',
        'provider_message_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMerchantSession::class, 'whatsapp_merchant_session_id');
    }
}
