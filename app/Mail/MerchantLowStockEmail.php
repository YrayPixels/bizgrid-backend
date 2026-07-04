<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Store;
use App\Models\StoreProduct;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MerchantLowStockEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Store $store,
        public StoreProduct $product,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Low stock: '.$this->product->name.' — '.$this->store->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.merchant-low-stock',
            with: [
                'brand' => MailBranding::platform(),
                'store' => $this->store,
                'product' => $this->product,
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
