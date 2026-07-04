<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Store;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AbandonedRecoveryEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public string $body,
        public string $subjectLine,
        public ?string $recoveryUrl = null,
        public ?string $customerName = null,
    ) {}

    public function envelope(): Envelope
    {
        $fromEmail = filled($this->store->contact_email)
            ? (string) $this->store->contact_email
            : (string) config('mail.from.address');
        $fromName = $this->store->name;

        return new Envelope(
            from: new Address($fromEmail, $fromName),
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.abandoned-recovery',
            with: [
                'body' => $this->body,
                'subjectLine' => $this->subjectLine,
                'recoveryUrl' => $this->recoveryUrl,
                'customerName' => $this->customerName,
                'brand' => MailBranding::store($this->store),
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
