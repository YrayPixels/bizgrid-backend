<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BizfestApplication;
use App\Support\MailBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BizfestApplicationReceivedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public BizfestApplication $application,
    ) {}

    public function envelope(): Envelope
    {
        $cc = config('storehause.welcome_cc_email');

        return new Envelope(
            subject: 'BizFest application received — welcome aboard',
            cc: filled($cc) ? [(string) $cc] : [],
        );
    }

    public function content(): Content
    {
        $brand = MailBranding::platform();
        $appUrl = rtrim((string) ($brand['app_url'] ?? config('storehause.app_url')), '/');
        $platformDomain = (string) config('storehause.platform_domain', 'bizgrid.shop');
        $grantsUrl = str_contains($appUrl, 'localhost')
            ? preg_replace('#://#', '://grants.', $appUrl, 1).'/grants'
            : 'https://grants.'.$platformDomain;

        return new Content(
            view: 'emails.bizfest-application-received',
            with: [
                'application' => $this->application,
                'brand' => $brand,
                'grantsUrl' => $grantsUrl,
                'signupUrl' => $appUrl.'/signup?from=bizfest',
                'socialLinks' => config('storehause.bizfest_social_links', []),
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
