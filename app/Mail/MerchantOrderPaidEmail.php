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

class MerchantOrderPaidEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public StoreOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received: '.$this->order->order_number.' — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-order-paid',
            with: [
                'brand' => MailBranding::platform(),
                'store' => $this->store,
                'order' => $this->order,
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
