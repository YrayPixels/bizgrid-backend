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

class WhatsAppAccountLinkCodeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
        public string $whatsappHint,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) config('storehause.brand_name', 'Bizgrid');

        return new Envelope(
            subject: "Confirm WhatsApp access to your {$brand} store",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.whatsapp-account-link-code',
            with: [
                'user' => $this->user,
                'code' => $this->code,
                'whatsappHint' => $this->whatsappHint,
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
