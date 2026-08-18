<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessInboundMerchantMessage;
use App\Jobs\ProcessInboundMerchantMessageBatch;
use Illuminate\Support\Facades\Cache;

class MerchantWhatsAppInboundBuffer
{
    private const DEBOUNCE_SECONDS = 2;

    private const CACHE_TTL_SECONDS = 120;

    /**
     * @param  array{
     *     phone_number_id: string,
     *     from: string,
     *     message_id: string,
     *     type: string,
     *     text: string,
     *     media_id: ?string,
     *     profile_name: ?string,
     *     profile_username?: ?string,
     *     from_user_id?: ?string,
     *     timestamp?: ?string,
     *     display_phone_number?: ?string
     * }  $message
     */
    public function push(array $message): void
    {
        $phone = preg_replace('/\D+/', '', (string) ($message['from'] ?? '')) ?? '';
        if ($phone === '') {
            return;
        }

        $batchKey = $this->messagesKey($phone);
        $activeBatch = Cache::has($batchKey) || ($message['type'] ?? '') === 'image';

        if (! $activeBatch) {
            ProcessInboundMerchantMessage::dispatch($message)->onConnection('database');

            return;
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = Cache::get($batchKey, []);
        $messages[] = $message;
        Cache::put($batchKey, $messages, self::CACHE_TTL_SECONDS);

        $version = (int) Cache::get($this->versionKey($phone), 0) + 1;
        Cache::put($this->versionKey($phone), $version, self::CACHE_TTL_SECONDS);

        ProcessInboundMerchantMessageBatch::dispatch($phone, $version)
            ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS))
            ->onConnection('database');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pull(string $phone): array
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalized === '') {
            return [];
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = Cache::pull($this->messagesKey($normalized), []);

        return is_array($messages) ? $messages : [];
    }

    public function isCurrentVersion(string $phone, int $version): bool
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalized === '') {
            return false;
        }

        return (int) Cache::get($this->versionKey($normalized), 0) === $version;
    }

    private function messagesKey(string $phone): string
    {
        return 'wa:merchant:batch:'.$phone;
    }

    private function versionKey(string $phone): string
    {
        return 'wa:merchant:batch:version:'.$phone;
    }
}
