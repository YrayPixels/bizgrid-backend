<?php

namespace App\Mail;

use App\Models\JumiaOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class JumiaOrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public JumiaOrder $order
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Jumia order: ' . $this->order->order_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.jumia-order-placed',
            with: ['order' => $this->order],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
