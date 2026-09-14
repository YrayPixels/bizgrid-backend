<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminEmailMessage;
use App\Models\AdminEmailThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminEmailSendService
{
    public function __construct(
        private readonly PlatformMailConfigService $mailConfig,
    ) {}

    /**
     * @param  array{
     *     to?: string|list<string>|null,
     *     merchant_ids?: list<int>|null,
     *     subject: string,
     *     body_text: string,
     *     body_html?: string|null,
     *     provider_id?: string|null
     * }  $input
     */
    public function compose(array $input, ?User $admin = null): AdminEmailMessage
    {
        $recipients = $this->resolveRecipients($input);
        if ($recipients === []) {
            throw new \InvalidArgumentException('Add at least one recipient email or merchant.');
        }

        $subject = trim($input['subject']);
        $bodyText = trim($input['body_text']);
        $bodyHtml = isset($input['body_html']) ? trim((string) $input['body_html']) : null;

        if ($subject === '' || $bodyText === '') {
            throw new \InvalidArgumentException('Subject and message body are required.');
        }

        $this->mailConfig->applyToRuntime();
        $fromAddress = $this->mailConfig->fromAddress();
        $fromName = $this->mailConfig->fromName();
        $providerId = $this->filled($input['provider_id'] ?? null) ?? $this->mailConfig->activeProviderId();
        $messageId = $this->makeMessageId($fromAddress);
        $toEmails = array_map(
            fn (array $row): array => ['email' => $row['email'], 'name' => $row['name']],
            $recipients
        );
        $toAddresses = array_column($recipients, 'email');

        return DB::transaction(function () use (
            $toEmails,
            $toAddresses,
            $subject,
            $bodyText,
            $bodyHtml,
            $fromAddress,
            $fromName,
            $providerId,
            $messageId,
            $admin
        ) {
            $participants = $toEmails;
            if ($fromAddress) {
                $participants[] = ['email' => strtolower($fromAddress), 'name' => $fromName];
            }

            $thread = AdminEmailThread::query()->create([
                'mailbox_provider_id' => $providerId,
                'subject' => $subject,
                'subject_normalized' => $this->normalizeSubject($subject),
                'participants' => $participants,
                'last_message_at' => now(),
                'last_direction' => 'outbound',
                'status' => 'open',
                'message_count' => 0,
            ]);

            $message = AdminEmailMessage::query()->create([
                'thread_id' => $thread->id,
                'direction' => 'outbound',
                'from_email' => $fromAddress,
                'from_name' => $fromName,
                'to_emails' => $toEmails,
                'cc_emails' => [],
                'subject' => $subject,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'message_id' => $messageId,
                'provider_id' => $providerId,
                'sent_by_admin_id' => $admin?->id,
                'status' => 'queued',
            ]);

            $this->dispatchMail($message, $toAddresses, $subject, $bodyText, $bodyHtml, $messageId, null, null);

            $message->update(['status' => 'sent', 'error' => null]);
            $thread->update([
                'last_message_at' => now(),
                'last_direction' => 'outbound',
                'message_count' => 1,
            ]);

            return $message->fresh(['thread']) ?? $message;
        });
    }

    /**
     * @param  array{to?: mixed, merchant_ids?: mixed}  $input
     * @return list<array{email: string, name: string|null}>
     */
    private function resolveRecipients(array $input): array
    {
        $map = [];

        $to = $input['to'] ?? null;
        $rawTos = [];
        if (is_string($to) && trim($to) !== '') {
            $rawTos = preg_split('/[,;]+/', $to) ?: [];
        } elseif (is_array($to)) {
            $rawTos = $to;
        }

        foreach ($rawTos as $item) {
            $email = strtolower(trim((string) $item));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $map[$email] = ['email' => $email, 'name' => null];
        }

        $merchantIds = $input['merchant_ids'] ?? null;
        if (is_array($merchantIds) && $merchantIds !== []) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $merchantIds), fn (int $id) => $id > 0)));
            if ($ids !== []) {
                $merchants = \App\Models\Merchant::query()
                    ->with('owner:id,name,email')
                    ->whereIn('id', $ids)
                    ->get(['id', 'business_name', 'owner_user_id']);

                foreach ($merchants as $merchant) {
                    $email = strtolower(trim((string) ($merchant->owner?->email ?? '')));
                    if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $name = $merchant->owner?->name ?: $merchant->business_name;
                    $map[$email] = [
                        'email' => $email,
                        'name' => filled($name) ? (string) $name : null,
                    ];
                }
            }
        }

        return array_values($map);
    }

    /**
     * @param  array{body_text: string, body_html?: string|null}  $input
     */
    public function reply(AdminEmailThread $thread, array $input, ?User $admin = null): AdminEmailMessage
    {
        $bodyText = trim($input['body_text']);
        $bodyHtml = isset($input['body_html']) ? trim((string) $input['body_html']) : null;
        if ($bodyText === '') {
            throw new \InvalidArgumentException('Reply body is required.');
        }

        $lastInbound = $thread->messages()
            ->where('direction', 'inbound')
            ->latest('id')
            ->first();
        $lastAny = $thread->messages()->latest('id')->first();

        $to = $lastInbound?->from_email;
        if (! filled($to)) {
            $participants = collect($thread->participants ?? [])
                ->pluck('email')
                ->filter()
                ->map(fn ($email) => strtolower((string) $email));
            $mailbox = strtolower((string) ($this->mailConfig->fromAddress() ?? ''));
            $to = $participants->first(fn ($email) => $email !== $mailbox);
        }

        if (! filled($to) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Could not determine a reply recipient.');
        }

        $this->mailConfig->applyToRuntime();
        $fromAddress = $this->mailConfig->fromAddress();
        $fromName = $this->mailConfig->fromName();
        $subject = $this->replySubject((string) ($thread->subject ?? $lastAny?->subject ?? ''));
        $messageId = $this->makeMessageId($fromAddress);
        $inReplyTo = $lastInbound?->message_id ?? $lastAny?->message_id;
        $references = trim(implode(' ', array_filter([
            $lastAny?->references_header,
            $inReplyTo,
        ])));

        return DB::transaction(function () use (
            $thread,
            $to,
            $subject,
            $bodyText,
            $bodyHtml,
            $fromAddress,
            $fromName,
            $messageId,
            $inReplyTo,
            $references,
            $admin
        ) {
            $message = AdminEmailMessage::query()->create([
                'thread_id' => $thread->id,
                'direction' => 'outbound',
                'from_email' => $fromAddress,
                'from_name' => $fromName,
                'to_emails' => [['email' => strtolower((string) $to), 'name' => null]],
                'cc_emails' => [],
                'subject' => $subject,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'references_header' => $references !== '' ? $references : null,
                'provider_id' => $thread->mailbox_provider_id ?? $this->mailConfig->activeProviderId(),
                'sent_by_admin_id' => $admin?->id,
                'status' => 'queued',
            ]);

            try {
                $this->dispatchMail(
                    $message,
                    [strtolower((string) $to)],
                    $subject,
                    $bodyText,
                    $bodyHtml,
                    $messageId,
                    $inReplyTo,
                    $references !== '' ? $references : null,
                );
                $message->update(['status' => 'sent', 'error' => null]);
            } catch (\Throwable $e) {
                $message->update(['status' => 'failed', 'error' => $e->getMessage()]);
                throw $e;
            }

            $thread->update([
                'last_message_at' => now(),
                'last_direction' => 'outbound',
                'message_count' => $thread->messages()->count(),
                'status' => 'open',
            ]);

            return $message->fresh() ?? $message;
        });
    }

    /**
     * @param  list<string>  $to
     */
    private function dispatchMail(
        AdminEmailMessage $message,
        array $to,
        string $subject,
        string $bodyText,
        ?string $bodyHtml,
        string $messageId,
        ?string $inReplyTo,
        ?string $references,
    ): void {
        $html = filled($bodyHtml)
            ? $bodyHtml
            : nl2br(e($bodyText));

        Mail::html($html, function ($mail) use ($to, $subject, $messageId, $inReplyTo, $references) {
            $mail->to($to)->subject($subject);
            $headers = $mail->getHeaders();
            $headers->addIdHeader('Message-ID', trim($messageId, '<>'));
            if ($inReplyTo) {
                $headers->addTextHeader('In-Reply-To', $inReplyTo);
            }
            if ($references) {
                $headers->addTextHeader('References', $references);
            }
        });
    }

    private function makeMessageId(?string $fromAddress): string
    {
        $domain = 'localhost';
        if (filled($fromAddress) && str_contains($fromAddress, '@')) {
            $domain = substr(strrchr($fromAddress, '@') ?: '@localhost', 1) ?: 'localhost';
        }

        return '<'.Str::uuid()->toString().'@'.$domain.'>';
    }

    private function replySubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return 'Re: (no subject)';
        }
        if (preg_match('/^re\s*:/i', $subject)) {
            return $subject;
        }

        return 'Re: '.$subject;
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($subject);
        while (preg_match('/^(re|fw|fwd)\s*:\s*/i', $subject)) {
            $subject = preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        }

        return Str::lower(trim($subject));
    }

    private function filled(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
