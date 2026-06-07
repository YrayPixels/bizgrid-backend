<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminPasswordReset extends Mailable
{
    use Queueable, SerializesModels;

    public $admin;
    public $password;

    public function __construct($admin, $password)
    {
        $this->admin = $admin;
        $this->password = $password;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your admin password was reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-password-reset',
            with: [
                'admin' => $this->admin,
                'password' => $this->password,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}

