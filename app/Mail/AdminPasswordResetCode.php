<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordResetCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $admin,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('storehause.brand_name', 'Bizgrid').' admin password reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-reset-code',
            with: [
                'admin' => $this->admin,
                'code' => $this->code,
                'brand' => MailBranding::platform(),
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
