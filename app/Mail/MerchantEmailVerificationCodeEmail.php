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

class MerchantEmailVerificationCodeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) config('storehause.brand_name', 'Bizgrid');

        return new Envelope(
            subject: "Verify your {$brand} email",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-email-verification-code',
            with: [
                'user' => $this->user,
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
