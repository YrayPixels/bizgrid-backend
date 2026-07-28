<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentExecutionLog extends Model
{
    protected $fillable = [
        'source',
        'agent',
        'phase',
        'title',
        'detail',
        'provider',
        'model',
        'prompt_version',
        'temperature',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'http_status',
        'status',
        'user_id',
        'merchant_id',
        'store_id',
        'builder_session_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'http_status' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function builderSession(): BelongsTo
    {
        return $this->belongsTo(StorefrontBuilderSession::class, 'builder_session_id');
    }
}
