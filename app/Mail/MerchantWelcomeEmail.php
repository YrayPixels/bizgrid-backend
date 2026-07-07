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

class MerchantWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) config('storehause.brand_name', 'Bizgrid');
        $cc = config('storehause.welcome_cc_email');

        return new Envelope(
            subject: "Welcome to {$brand}",
            cc: filled($cc) ? [(string) $cc] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-welcome',
            with: [
                'user' => $this->user,
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
