<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreOrder;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOrderCancelledEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public StoreOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        $fromEmail = filled($this->store->contact_email)
            ? (string) $this->store->contact_email
            : (string) config('mail.from.address');

        return new Envelope(
            from: new Address($fromEmail, $this->store->name),
            subject: 'Order '.$this->order->order_number.' cancelled — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-order-cancelled',
            with: [
                'brand' => MailBranding::store($this->store),
                'order' => $this->order,
                'customerName' => $this->order->customer_name,
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
