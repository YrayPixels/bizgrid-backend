<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminEmailMessage extends Model
{
    protected $fillable = [
        'thread_id',
        'direction',
        'from_email',
        'from_name',
        'to_emails',
        'cc_emails',
        'subject',
        'body_text',
        'body_html',
        'message_id',
        'in_reply_to',
        'references_header',
        'imap_uid',
        'imap_folder',
        'provider_id',
        'sent_by_admin_id',
        'status',
        'error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'to_emails' => 'array',
            'cc_emails' => 'array',
            'metadata' => 'array',
            'imap_uid' => 'integer',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(AdminEmailThread::class, 'thread_id');
    }

    public function sentByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_admin_id');
    }
}
