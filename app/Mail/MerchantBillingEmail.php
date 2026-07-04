<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Merchant;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantBillingEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Merchant $merchant,
        public string $event,
        public array $context = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectForEvent(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-billing',
            with: [
                'brand' => MailBranding::platform(),
                'merchant' => $this->merchant,
                'event' => $this->event,
                'context' => $this->context,
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

    private function subjectForEvent(): string
    {
        $brand = config('storehause.brand_name', 'Bizgrid');

        return match ($this->event) {
            'subscription_active' => "Your {$brand} subscription is active",
            'subscription_on_hold' => "Your {$brand} subscription is on hold",
            'subscription_cancelled' => "Your {$brand} subscription was cancelled",
            'add_on_purchased' => "Your {$brand} add-on purchase was successful",
            default => "{$brand} billing update",
        };
    }
}
