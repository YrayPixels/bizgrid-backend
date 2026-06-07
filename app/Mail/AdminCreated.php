<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;
    public $password;
    /**
     * Create a new message instance.
     */
    public function __construct($admin, $password)
    {
        $this->admin = $admin;
        $this->password = $password;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Admin Created',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        // send the admin details to the admin email
        return new Content(
            view: 'emails.admin-created',
            with: [
                'admin' => $this->admin,
                'password' => $this->password,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
