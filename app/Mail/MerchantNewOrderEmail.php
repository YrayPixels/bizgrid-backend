<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantNewOrderEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public StoreOrder $order,
        public bool $awaitingPayment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New order '.$this->order->order_number.' — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-new-order',
            with: [
                'brand' => MailBranding::platform(),
                'store' => $this->store,
                'order' => $this->order,
                'awaitingPayment' => $this->awaitingPayment,
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
