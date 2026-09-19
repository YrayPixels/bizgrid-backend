<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendAdminEmailMessageJob;
use App\Models\AdminEmailMessage;
use App\Models\AdminEmailThread;
use App\Models\User;
use App\Support\MailBranding;
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
     *     cc?: string|list<string>|null,
     *     bcc?: string|list<string>|null,
     *     merchant_ids?: list<int>|null,
     *     cc_merchant_ids?: list<int>|null,
     *     bcc_merchant_ids?: list<int>|null,
     *     subject: string,
     *     body_text: string,
     *     body_html?: string|null,
     *     variables?: array<string, mixed>|null,
     *     provider_id?: string|null
     * }  $input
     */
    public function compose(array $input, ?User $admin = null): AdminEmailMessage
    {
        $buckets = $this->resolveRecipientBuckets($input);
        if ($buckets['to'] === [] && $buckets['cc'] === [] && $buckets['bcc'] === []) {
            throw new \InvalidArgumentException('Add at least one recipient email or merchant.');
        }

        $subject = trim($input['subject']);
        $bodyText = trim((string) ($input['body_text'] ?? ''));
        $bodyHtml = isset($input['body_html']) ? trim((string) $input['body_html']) : '';
        $bodyHtml = $bodyHtml !== '' ? $bodyHtml : null;
        $customVars = $this->parseCustomVariables($input['variables'] ?? null);

        if ($subject === '' || ($bodyText === '' && $bodyHtml === null)) {
            throw new \InvalidArgumentException('Subject and message body are required.');
        }

        $this->mailConfig->applyToRuntime();
        $fromAddress = $this->mailConfig->fromAddress();
        $fromName = $this->mailConfig->fromName();
        $providerId = $this->filled($input['provider_id'] ?? null) ?? $this->mailConfig->activeProviderId();

        $messages = DB::transaction(function () use (
            $buckets,
            $subject,
            $bodyText,
            $bodyHtml,
            $customVars,
            $fromAddress,
            $fromName,
            $providerId,
            $admin
        ) {
            $participants = array_merge($buckets['to'], $buckets['cc'], $buckets['bcc']);
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

            $created = [];
            $combineCopy = $buckets['cc'] !== [] || ($buckets['to'] !== [] && $buckets['bcc'] !== []);

            if ($combineCopy) {
                [$personalizedSubject, $personalizedText, $personalizedHtml] = $this->personalize(
                    $subject,
                    $bodyText,
                    $bodyHtml,
                    $buckets['to'],
                    $buckets['cc'],
                    $buckets['bcc'],
                    $customVars,
                );
                $created[] = $this->createOutboundMessage(
                    $thread->id,
                    $fromAddress,
                    $fromName,
                    $buckets['to'],
                    $buckets['cc'],
                    $buckets['bcc'],
                    $personalizedSubject,
                    $personalizedText,
                    $personalizedHtml,
                    $providerId,
                    $admin?->id,
                );
            } elseif ($buckets['to'] !== []) {
                foreach ($buckets['to'] as $recipient) {
                    [$personalizedSubject, $personalizedText, $personalizedHtml] = $this->personalize(
                        $subject,
                        $bodyText,
                        $bodyHtml,
                        [$recipient],
                        [],
                        [],
                        $customVars,
                    );
                    $created[] = $this->createOutboundMessage(
                        $thread->id,
                        $fromAddress,
                        $fromName,
                        [$recipient],
                        [],
                        [],
                        $personalizedSubject,
                        $personalizedText,
                        $personalizedHtml,
                        $providerId,
                        $admin?->id,
                    );
                }
            } else {
                foreach ($buckets['bcc'] as $recipient) {
                    [$personalizedSubject, $personalizedText, $personalizedHtml] = $this->personalize(
                        $subject,
                        $bodyText,
                        $bodyHtml,
                        [],
                        [],
                        [$recipient],
                        $customVars,
                    );
                    $created[] = $this->createOutboundMessage(
                        $thread->id,
                        $fromAddress,
                        $fromName,
                        [],
                        [],
                        [$recipient],
                        $personalizedSubject,
                        $personalizedText,
                        $personalizedHtml,
                        $providerId,
                        $admin?->id,
                    );
                }
            }

            $thread->update(['message_count' => count($created)]);

            return $created;
        });

        $this->queueOutboundMessages($messages);

        $first = $messages[0];

        return $first->fresh(['thread']) ?? $first;
    }

    /**
     * @param  list<array{email: string, name: string|null}>  $to
     * @param  list<array{email: string, name: string|null}>  $cc
     * @param  list<array{email: string, name: string|null}>  $bcc
     */
    private function createOutboundMessage(
        int $threadId,
        ?string $fromAddress,
        ?string $fromName,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $bodyText,
        ?string $bodyHtml,
        ?string $providerId,
        ?int $adminId,
        ?string $inReplyTo = null,
        ?string $references = null,
    ): AdminEmailMessage {
        return AdminEmailMessage::query()->create([
            'thread_id' => $threadId,
            'direction' => 'outbound',
            'from_email' => $fromAddress,
            'from_name' => $fromName,
            'to_emails' => $to,
            'cc_emails' => $cc,
            'bcc_emails' => $bcc,
            'subject' => $subject,
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'message_id' => $this->makeMessageId($fromAddress),
            'in_reply_to' => $inReplyTo,
            'references_header' => $references,
            'provider_id' => $providerId,
            'sent_by_admin_id' => $adminId,
            'status' => 'queued',
        ]);
    }

    /**
     * @param  array{
     *     to?: mixed,
     *     cc?: mixed,
     *     bcc?: mixed,
     *     merchant_ids?: mixed,
     *     cc_merchant_ids?: mixed,
     *     bcc_merchant_ids?: mixed
     * }  $input
     * @return array{
     *     to: list<array{email: string, name: string|null}>,
     *     cc: list<array{email: string, name: string|null}>,
     *     bcc: list<array{email: string, name: string|null}>
     * }
     */
    private function resolveRecipientBuckets(array $input): array
    {
        $to = $this->parseEmailList($input['to'] ?? null) + $this->recipientsFromMerchantIds($input['merchant_ids'] ?? null);
        $cc = $this->parseEmailList($input['cc'] ?? null) + $this->recipientsFromMerchantIds($input['cc_merchant_ids'] ?? null);
        $bcc = $this->parseEmailList($input['bcc'] ?? null) + $this->recipientsFromMerchantIds($input['bcc_merchant_ids'] ?? null);

        foreach (array_keys($to) as $email) {
            unset($cc[$email], $bcc[$email]);
        }
        foreach (array_keys($cc) as $email) {
            unset($bcc[$email]);
        }

        return [
            'to' => array_values($to),
            'cc' => array_values($cc),
            'bcc' => array_values($bcc),
        ];
    }

    /**
     * @return array<string, array{email: string, name: string|null}>
     */
    private function parseEmailList(mixed $value): array
    {
        $raw = [];
        if (is_string($value) && trim($value) !== '') {
            $raw = preg_split('/[\s,;]+/', $value) ?: [];
        } elseif (is_array($value)) {
            $raw = $value;
        }

        $map = [];
        foreach ($raw as $item) {
            $email = strtolower(trim((string) $item));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $map[$email] = ['email' => $email, 'name' => null];
        }

        return $map;
    }

    /**
     * @return array<string, array{email: string, name: string|null}>
     */
    private function recipientsFromMerchantIds(mixed $merchantIds): array
    {
        if (! is_array($merchantIds) || $merchantIds === []) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $merchantIds), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $merchants = \App\Models\Merchant::query()
            ->with('owner:id,name,email')
            ->whereIn('id', $ids)
            ->get(['id', 'business_name', 'owner_user_id']);

        $map = [];
        foreach ($merchants as $merchant) {
            $email = strtolower(trim((string) ($merchant->owner?->email ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name = $merchant->owner?->name ?: $merchant->business_name;
            $map[$email] = [
                'email' => $email,
                'name' => filled($name) ? (string) $name : null,
                'merchant_id' => $merchant->id,
                'business_name' => filled($merchant->business_name) ? (string) $merchant->business_name : null,
            ];
        }

        return $map;
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
        $inReplyTo = $lastInbound?->message_id ?? $lastAny?->message_id;
        $references = trim(implode(' ', array_filter([
            $lastAny?->references_header,
            $inReplyTo,
        ])));

        $message = DB::transaction(function () use (
            $thread,
            $to,
            $subject,
            $bodyText,
            $bodyHtml,
            $fromAddress,
            $fromName,
            $inReplyTo,
            $references,
            $admin
        ) {
            $message = $this->createOutboundMessage(
                $thread->id,
                $fromAddress,
                $fromName,
                [['email' => strtolower((string) $to), 'name' => null]],
                [],
                [],
                $subject,
                $bodyText,
                $bodyHtml,
                $thread->mailbox_provider_id ?? $this->mailConfig->activeProviderId(),
                $admin?->id,
                $inReplyTo,
                $references !== '' ? $references : null,
            );

            $thread->update([
                'last_message_at' => now(),
                'last_direction' => 'outbound',
                'message_count' => $thread->messages()->count(),
                'status' => 'open',
            ]);

            return $message;
        });

        $this->queueOutboundMessages([$message]);

        return $message->fresh() ?? $message;
    }

    public function sendQueued(int $messageId): void
    {
        $message = AdminEmailMessage::query()->with('thread')->find($messageId);
        if (! $message || $message->direction !== 'outbound') {
            return;
        }
        if ($message->status === 'sent') {
            return;
        }

        $to = $this->normalizeAddressRows($message->to_emails ?? []);
        $cc = $this->normalizeAddressRows($message->cc_emails ?? []);
        $bcc = $this->normalizeAddressRows($message->bcc_emails ?? []);

        if ($to === [] && ($cc !== [] || $bcc !== [])) {
            $fromEmail = strtolower(trim((string) ($message->from_email ?? '')));
            if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $to = [[
                    'email' => $fromEmail,
                    'name' => filled($message->from_name) ? (string) $message->from_name : null,
                ]];
            }
        }

        if ($to === [] && $cc === [] && $bcc === []) {
            $message->update(['status' => 'failed', 'error' => 'No valid recipient.']);

            return;
        }

        $this->mailConfig->applyToRuntime();

        $this->dispatchMail(
            $to,
            $cc,
            $bcc,
            (string) ($message->subject ?? ''),
            (string) ($message->body_text ?? ''),
            $message->body_html,
            (string) ($message->message_id ?: $this->makeMessageId($message->from_email)),
            $message->in_reply_to,
            $message->references_header,
        );

        $message->update(['status' => 'sent', 'error' => null]);
        $message->thread?->update([
            'last_message_at' => now(),
            'last_direction' => 'outbound',
        ]);
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{email: string, name: string|null}>
     */
    private function normalizeAddressRows(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $email = strtolower(trim((string) (is_array($row) ? ($row['email'] ?? '') : $row)));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $map[$email] = [
                'email' => $email,
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
            ];
        }

        return array_values($map);
    }

    /**
     * @param  list<AdminEmailMessage>  $messages
     */
    private function queueOutboundMessages(array $messages): void
    {
        $delaySeconds = max(0, (int) config('storehause.admin_mail.send_delay_seconds', 15));

        foreach (array_values($messages) as $index => $message) {
            $pending = SendAdminEmailMessageJob::dispatch($message->id);
            if ($index > 0 && $delaySeconds > 0) {
                $pending->delay(now()->addSeconds($index * $delaySeconds));
            }
        }
    }

    /**
     * @param  list<array{email: string, name: string|null}>  $to
     * @param  list<array{email: string, name: string|null}>  $cc
     * @param  list<array{email: string, name: string|null}>  $bcc
     */
    private function dispatchMail(
        array $to,
        array $cc,
        array $bcc,
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

        Mail::html($html, function ($mail) use ($to, $cc, $bcc, $subject, $messageId, $inReplyTo, $references) {
            if ($to !== []) {
                $mail->to(array_column($to, 'email'));
            }
            if ($cc !== []) {
                $mail->cc(array_column($cc, 'email'));
            }
            if ($bcc !== []) {
                $mail->bcc(array_column($bcc, 'email'));
            }
            $mail->subject($subject);
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

    /**
     * @param  list<array{email: string, name: string|null, merchant_id?: int|null, business_name?: string|null}>  $to
     * @param  list<array{email: string, name: string|null, merchant_id?: int|null, business_name?: string|null}>  $cc
     * @param  list<array{email: string, name: string|null, merchant_id?: int|null, business_name?: string|null}>  $bcc
     * @param  array<string, string>  $customVars
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function personalize(
        string $subject,
        string $bodyText,
        ?string $bodyHtml,
        array $to,
        array $cc,
        array $bcc,
        array $customVars,
    ): array {
        $primary = $to[0] ?? $cc[0] ?? $bcc[0] ?? null;
        $vars = $this->variablesFor($primary, $customVars);

        $subject = $this->interpolate($subject, $vars);
        $bodyText = $this->interpolate($bodyText, $vars);

        if ($bodyHtml !== null) {
            $bodyHtml = $this->finalizeHtml($this->interpolate($bodyHtml, $this->escapeVariables($vars)), $subject);
            if (trim($bodyText) === '') {
                $bodyText = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $bodyText = preg_replace('/[ \t]+/', ' ', $bodyText) ?? $bodyText;
                $bodyText = preg_replace('/\n{3,}/', "\n\n", $bodyText) ?? $bodyText;
                $bodyText = Str::limit($bodyText, 19000, '');
            }
        }

        return [$subject, $bodyText, $bodyHtml];
    }

    /**
     * @param  array{email?: string|null, name?: string|null, business_name?: string|null}|null  $recipient
     * @param  array<string, string>  $customVars
     * @return array<string, string>
     */
    private function variablesFor(?array $recipient, array $customVars): array
    {
        $brand = MailBranding::platform();
        $email = strtolower(trim((string) ($recipient['email'] ?? '')));
        $name = trim((string) ($recipient['name'] ?? ''));
        if ($name === '' && $email !== '') {
            $name = Str::before($email, '@');
        }
        $firstName = trim(Str::before($name, ' '));
        $businessName = trim((string) ($recipient['business_name'] ?? ''));

        $builtins = [
            'name' => $name,
            'first_name' => $firstName !== '' ? $firstName : $name,
            'email' => $email,
            'business_name' => $businessName !== '' ? $businessName : $name,
            'brand_name' => (string) ($brand['name'] ?? ''),
            'support_email' => (string) ($brand['support_email'] ?? ''),
            'app_url' => (string) ($brand['app_url'] ?? ''),
        ];

        return array_merge($builtins, $customVars);
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    private function escapeVariables(array $vars): array
    {
        $escaped = [];
        foreach ($vars as $key => $value) {
            $escaped[$key] = e($value);
        }

        return $escaped;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        $rendered = preg_replace_callback(
            '/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/',
            function (array $matches) use ($vars): string {
                $key = $matches[1];

                return array_key_exists($key, $vars) ? $vars[$key] : $matches[0];
            },
            $template
        );

        return is_string($rendered) ? $rendered : $template;
    }

    private function finalizeHtml(string $html, string $subject): string
    {
        if ($this->isFullHtmlDocument($html)) {
            return $html;
        }

        return view('emails.admin-compose', [
            'subject' => $subject,
            'html' => $html,
            'brand' => MailBranding::platform(),
        ])->render();
    }

    private function isFullHtmlDocument(string $html): bool
    {
        $start = ltrim(substr($html, 0, 600));

        return (bool) preg_match('/^(<!doctype\s+html|<html[\s>])/i', $start);
    }

    /**
     * @return array<string, string>
     */
    private function parseCustomVariables(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $vars = [];
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $key = $value['key'] ?? $key;
                $value = $value['value'] ?? '';
            }
            $name = is_string($key) ? trim($key) : '';
            if ($name === '' || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $name)) {
                continue;
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $vars[$name] = Str::limit(trim((string) $value), 2000, '');
            if (count($vars) >= 40) {
                break;
            }
        }

        return $vars;
    }
}
