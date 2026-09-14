<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminEmailMessage;
use App\Models\AdminEmailThread;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminMailboxImapService
{
    public function __construct(
        private readonly PlatformMailConfigService $mailConfig,
    ) {}

    /**
     * @return array{polled: int, imported: int, providers: list<array{id: string, name: string, imported: int, error: string|null}>}
     */
    public function pollAll(?int $limitPerMailbox = 50): array
    {
        if (! function_exists('imap_open')) {
            throw new \RuntimeException('PHP imap extension is required for the email center.');
        }

        $summary = [
            'polled' => 0,
            'imported' => 0,
            'providers' => [],
        ];

        foreach ($this->mailConfig->imapEnabledProviders() as $provider) {
            $providerId = (string) ($provider['id'] ?? '');
            if ($providerId === '') {
                continue;
            }

            $summary['polled']++;

            try {
                $imported = $this->pollProvider($providerId, $limitPerMailbox);
                $summary['imported'] += $imported;
                $summary['providers'][] = [
                    'id' => $providerId,
                    'name' => (string) ($provider['name'] ?? 'Mailbox'),
                    'imported' => $imported,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                Log::warning('admin.email.imap_poll_failed', [
                    'provider_id' => $providerId,
                    'error' => $e->getMessage(),
                ]);
                $summary['providers'][] = [
                    'id' => $providerId,
                    'name' => (string) ($provider['name'] ?? 'Mailbox'),
                    'imported' => 0,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    public function pollProvider(string $providerId, ?int $limit = 50): int
    {
        $connection = $this->mailConfig->imapConnection($providerId);
        if ($connection === null) {
            throw new \InvalidArgumentException('IMAP is not fully configured for this provider.');
        }

        $mailbox = $this->mailboxString($connection);
        $inbox = @imap_open($mailbox, $connection['username'], $connection['password'], 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($inbox === false) {
            throw new \RuntimeException(imap_last_error() ?: 'Unable to open IMAP mailbox.');
        }

        try {
            $lastUid = (int) $connection['last_uid'];
            // imap_search() needs IMAP SEARCH keys (ALL / UID n:*), not bare sequence sets.
            $criterion = $lastUid > 0
                ? 'UID '.($lastUid + 1).':*'
                : 'ALL';
            $uids = @imap_search($inbox, $criterion, SE_UID);
            // Clear the IMAP error stack so a soft warning cannot become a second JSON
            // payload during request shutdown (breaks response.json() in the admin app).
            imap_errors();
            imap_alerts();

            if ($uids === false) {
                $uids = [];
            }

            $uids = array_values(array_filter(
                array_map('intval', $uids),
                fn (int $uid): bool => $uid > $lastUid
            ));
            sort($uids, SORT_NUMERIC);
            if ($limit !== null && $limit > 0) {
                $uids = array_slice($uids, 0, $limit);
            }

            $imported = 0;
            $maxUid = $lastUid;

            foreach ($uids as $uid) {
                try {
                    if ($this->importUid($inbox, $connection, $uid)) {
                        $imported++;
                    }
                    $maxUid = max($maxUid, $uid);
                } catch (\Throwable $e) {
                    Log::warning('admin.email.imap_import_failed', [
                        'provider_id' => $providerId,
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            if ($maxUid > $lastUid) {
                $this->mailConfig->updateImapLastUid($providerId, $maxUid);
            }

            return $imported;
        } finally {
            imap_errors();
            imap_alerts();
            imap_close($inbox);
        }
    }

    /**
     * @param  resource  $inbox
     * @param  array{id: string, folder: string, from_address: string|null}  $connection
     */
    private function importUid($inbox, array $connection, int $uid): bool
    {
        $exists = AdminEmailMessage::query()
            ->where('provider_id', $connection['id'])
            ->where('imap_folder', $connection['folder'])
            ->where('imap_uid', $uid)
            ->exists();

        if ($exists) {
            return false;
        }

        $overview = imap_fetch_overview($inbox, (string) $uid, FT_UID);
        $header = $overview[0] ?? null;
        if ($header === null) {
            return false;
        }

        $rawHeaders = imap_fetchheader($inbox, $uid, FT_UID) ?: '';
        $messageId = $this->normalizeMessageId($header->message_id ?? $this->headerValue($rawHeaders, 'Message-ID'));
        if ($messageId !== null) {
            $byMessageId = AdminEmailMessage::query()->where('message_id', $messageId)->first();
            if ($byMessageId) {
                if ($byMessageId->imap_uid === null) {
                    $byMessageId->update([
                        'imap_uid' => $uid,
                        'imap_folder' => $connection['folder'],
                        'provider_id' => $connection['id'],
                    ]);
                }

                return false;
            }
        }

        $from = $this->parseAddress($header->from ?? '');
        $to = $this->parseAddressList($header->to ?? '');
        $cc = $this->parseAddressList($header->cc ?? '');
        $subject = $this->decodeMime((string) ($header->subject ?? ''));
        $inReplyTo = $this->normalizeMessageId($this->headerValue($rawHeaders, 'In-Reply-To'));
        $references = $this->headerValue($rawHeaders, 'References');
        [$bodyText, $bodyHtml] = $this->extractBodies($inbox, $uid);

        $mailboxAddress = strtolower((string) ($connection['from_address'] ?? ''));
        $fromEmail = strtolower((string) ($from['email'] ?? ''));
        $direction = ($mailboxAddress !== '' && $fromEmail === $mailboxAddress) ? 'outbound' : 'inbound';

        return DB::transaction(function () use (
            $connection,
            $uid,
            $direction,
            $from,
            $to,
            $cc,
            $subject,
            $bodyText,
            $bodyHtml,
            $messageId,
            $inReplyTo,
            $references,
            $header
        ) {
            $thread = $this->resolveThread(
                providerId: $connection['id'],
                subject: $subject,
                messageId: $messageId,
                inReplyTo: $inReplyTo,
                references: $references,
                fromParticipants: $this->participants($from, $to, $cc),
            );

            AdminEmailMessage::query()->create([
                'thread_id' => $thread->id,
                'direction' => $direction,
                'from_email' => $from['email'] ?? null,
                'from_name' => $from['name'] ?? null,
                'to_emails' => $to,
                'cc_emails' => $cc,
                'subject' => $subject !== '' ? $subject : null,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'references_header' => $references,
                'imap_uid' => $uid,
                'imap_folder' => $connection['folder'],
                'provider_id' => $connection['id'],
                'status' => 'received',
                'metadata' => [
                    'date' => $header->date ?? null,
                    'size' => isset($header->size) ? (int) $header->size : null,
                ],
            ]);

            $thread->update([
                'last_message_at' => now(),
                'last_direction' => $direction,
                'message_count' => $thread->messages()->count(),
                'status' => $thread->status === 'archived' && $direction === 'inbound' ? 'open' : $thread->status,
                'participants' => $this->mergeParticipants($thread->participants ?? [], $this->participants($from, $to, $cc)),
            ]);

            return true;
        });
    }

    /**
     * @param  list<array{email: string, name: string|null}>  $fromParticipants
     */
    private function resolveThread(
        string $providerId,
        string $subject,
        ?string $messageId,
        ?string $inReplyTo,
        ?string $references,
        array $fromParticipants,
    ): AdminEmailThread {
        $referenceIds = [];
        if ($inReplyTo) {
            $referenceIds[] = $inReplyTo;
        }
        if (filled($references)) {
            preg_match_all('/<[^>]+>/', $references, $matches);
            foreach ($matches[0] ?? [] as $match) {
                $normalized = $this->normalizeMessageId($match);
                if ($normalized) {
                    $referenceIds[] = $normalized;
                }
            }
        }
        $referenceIds = array_values(array_unique($referenceIds));

        if ($referenceIds !== []) {
            $parent = AdminEmailMessage::query()
                ->whereIn('message_id', $referenceIds)
                ->latest('id')
                ->first();
            if ($parent) {
                return $parent->thread;
            }
        }

        $normalizedSubject = $this->normalizeSubject($subject);
        if ($normalizedSubject !== '') {
            $existing = AdminEmailThread::query()
                ->where('mailbox_provider_id', $providerId)
                ->where('subject_normalized', $normalizedSubject)
                ->where('status', 'open')
                ->orderByDesc('last_message_at')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return AdminEmailThread::query()->create([
            'mailbox_provider_id' => $providerId,
            'subject' => $subject !== '' ? $subject : '(no subject)',
            'subject_normalized' => $normalizedSubject !== '' ? $normalizedSubject : null,
            'participants' => $fromParticipants,
            'last_message_at' => now(),
            'last_direction' => 'inbound',
            'status' => 'open',
            'message_count' => 0,
        ]);
    }

    /**
     * @param  resource  $inbox
     * @return array{0: string|null, 1: string|null}
     */
    private function extractBodies($inbox, int $uid): array
    {
        $structure = imap_fetchstructure($inbox, $uid, FT_UID);
        if (! $structure) {
            $raw = imap_body($inbox, $uid, FT_UID) ?: '';

            return [$this->stripToText($raw), null];
        }

        $text = $this->findPartBody($inbox, $uid, $structure, 'TEXT/PLAIN');
        $html = $this->findPartBody($inbox, $uid, $structure, 'TEXT/HTML');

        if ($text === null && $html !== null) {
            $text = $this->stripToText($html);
        }

        return [$text, $html];
    }

    /**
     * @param  resource  $inbox
     * @param  object  $structure
     */
    private function findPartBody($inbox, int $uid, object $structure, string $mime, string $prefix = ''): ?string
    {
        $typeMap = [0 => 'TEXT', 1 => 'MULTIPART', 2 => 'MESSAGE', 3 => 'APPLICATION', 4 => 'AUDIO', 5 => 'IMAGE', 6 => 'VIDEO', 7 => 'OTHER'];
        $type = strtoupper(($typeMap[$structure->type ?? 0] ?? 'OTHER').'/'.($structure->subtype ?? 'PLAIN'));

        if (! isset($structure->parts) || ! is_array($structure->parts) || $structure->parts === []) {
            if (strcasecmp($type, $mime) !== 0) {
                return null;
            }
            $section = $prefix !== '' ? $prefix : '1';
            $body = imap_fetchbody($inbox, $uid, $section, FT_UID) ?: '';
            $body = $this->decodePart($body, (int) ($structure->encoding ?? 0));
            if (! empty($structure->parameters)) {
                foreach ($structure->parameters as $param) {
                    if (strtoupper((string) ($param->attribute ?? '')) === 'CHARSET') {
                        $body = $this->convertCharset($body, (string) $param->value);

                        break;
                    }
                }
            }

            return trim($body) !== '' ? $body : null;
        }

        foreach ($structure->parts as $index => $part) {
            $section = $prefix === '' ? (string) ($index + 1) : $prefix.'.'.($index + 1);
            $found = $this->findPartBody($inbox, $uid, $part, $mime, $section);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function decodePart(string $body, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($body, true) ?: $body, // ENCBASE64
            4 => quoted_printable_decode($body), // ENCQUOTEDPRINTABLE
            default => $body,
        };
    }

    private function convertCharset(string $body, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
            return $body;
        }

        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);

        return is_string($converted) && $converted !== '' ? $converted : $body;
    }

    /**
     * @param  array{host: string, port: int, encryption: string, folder: string}  $connection
     */
    private function mailboxString(array $connection): string
    {
        $flags = match ($connection['encryption']) {
            'tls' => '/imap/tls',
            'none' => '/imap/notls',
            default => '/imap/ssl',
        };

        $folder = str_replace('"', '', $connection['folder']);

        return sprintf('{%s:%d%s}%s', $connection['host'], $connection['port'], $flags, $folder);
    }

    /**
     * @return array{email: string|null, name: string|null}
     */
    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['email' => null, 'name' => null];
        }

        if (preg_match('/^(?:"?([^"]*)"?\s)?<?([^>]+@[^>]+)>?$/', $raw, $matches)) {
            return [
                'name' => filled(trim($matches[1] ?? '')) ? $this->decodeMime(trim($matches[1])) : null,
                'email' => strtolower(trim($matches[2])),
            ];
        }

        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return ['email' => strtolower($raw), 'name' => null];
        }

        return ['email' => null, 'name' => $this->decodeMime($raw)];
    }

    /**
     * @return list<array{email: string, name: string|null}>
     */
    private function parseAddressList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $raw) ?: [];
        $list = [];
        foreach ($parts as $part) {
            $parsed = $this->parseAddress(trim($part));
            if (! filled($parsed['email'])) {
                continue;
            }
            $list[] = [
                'email' => (string) $parsed['email'],
                'name' => $parsed['name'],
            ];
        }

        return $list;
    }

    /**
     * @param  array{email: string|null, name: string|null}  $from
     * @param  list<array{email: string, name: string|null}>  $to
     * @param  list<array{email: string, name: string|null}>  $cc
     * @return list<array{email: string, name: string|null}>
     */
    private function participants(array $from, array $to, array $cc): array
    {
        $all = [];
        if (filled($from['email'] ?? null)) {
            $all[] = ['email' => (string) $from['email'], 'name' => $from['name'] ?? null];
        }
        foreach (array_merge($to, $cc) as $row) {
            $all[] = $row;
        }

        return $this->mergeParticipants([], $all);
    }

    /**
     * @param  list<array{email?: string, name?: string|null}>  $current
     * @param  list<array{email?: string, name?: string|null}>  $incoming
     * @return list<array{email: string, name: string|null}>
     */
    private function mergeParticipants(array $current, array $incoming): array
    {
        $map = [];
        foreach (array_merge($current, $incoming) as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $map[$email] = [
                'email' => $email,
                'name' => filled($row['name'] ?? null) ? (string) $row['name'] : ($map[$email]['name'] ?? null),
            ];
        }

        return array_values($map);
    }

    private function normalizeSubject(string $subject): string
    {
        $subject = trim($this->decodeMime($subject));
        $subject = preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        while (preg_match('/^(re|fw|fwd)\s*:\s*/i', $subject)) {
            $subject = preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $subject) ?? $subject;
        }

        return Str::lower(trim($subject));
    }

    private function normalizeMessageId(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        $value = trim($value);
        if (! str_starts_with($value, '<')) {
            $value = '<'.$value;
        }
        if (! str_ends_with($value, '>')) {
            $value .= '>';
        }

        return $value;
    }

    private function headerValue(string $rawHeaders, string $name): ?string
    {
        if (preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/im', $rawHeaders, $matches)) {
            return trim(preg_replace("/\r?\n[ \t]+/", ' ', $matches[1]) ?? $matches[1]);
        }

        return null;
    }

    private function decodeMime(string $value): string
    {
        if ($value === '' || ! function_exists('imap_mime_header_decode')) {
            return $value;
        }

        $parts = imap_mime_header_decode($value);
        if (! is_array($parts)) {
            return $value;
        }

        $out = '';
        foreach ($parts as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
            $text = (string) ($part->text ?? '');
            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                $text = is_string($converted) ? $converted : $text;
            }
            $out .= $text;
        }

        return $out;
    }

    private function stripToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/[ \t]+/", ' ', $text) ?? $text);
    }
}
