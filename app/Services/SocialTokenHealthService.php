<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreSocialConnection;

/**
 * Keeps merchants ahead of expiring social logins. Meta's long-lived tokens
 * last ~60 days; without this the first sign of trouble is a failed publish.
 */
class SocialTokenHealthService
{
    private const WARN_WITHIN_DAYS = 7;

    public function __construct(
        private readonly FacebookService $facebook,
    ) {}

    /**
     * Re-check every Meta connection and update its stored status.
     *
     * @return array{checked: int, expiring: int, invalid: int}
     */
    public function refreshAll(int $limit = 200): array
    {
        $connections = StoreSocialConnection::query()
            ->whereIn('provider', ['facebook', FacebookService::USER_PROVIDER, InstagramService::PROVIDER, MetaAdsService::PROVIDER])
            ->where(function ($query) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<=', now()->subHours(12));
            })
            ->limit($limit)
            ->get();

        $expiring = 0;
        $invalid = 0;

        foreach ($connections as $connection) {
            $status = $this->refresh($connection);

            if ($status === 'invalid') {
                $invalid++;
            } elseif ($status === 'expiring') {
                $expiring++;
            }
        }

        return ['checked' => $connections->count(), 'expiring' => $expiring, 'invalid' => $invalid];
    }

    public function refresh(StoreSocialConnection $connection): string
    {
        $inspection = $this->facebook->inspectToken((string) $connection->page_access_token);

        if (! $inspection['valid']) {
            $connection->update([
                'status' => 'invalid',
                'invalid_reason' => $inspection['reason']
                    ?: 'Reconnect this account — the saved login is no longer valid.',
                'last_checked_at' => now(),
            ]);

            return 'invalid';
        }

        $expiresAt = $inspection['expires_at'] ?? $connection->token_expires_at;
        $expiring = $expiresAt !== null
            && $expiresAt->isFuture()
            && $expiresAt->diffInDays(now()) <= self::WARN_WITHIN_DAYS;

        $connection->update([
            'status' => $expiring ? 'expiring' : 'active',
            'token_expires_at' => $expiresAt,
            'invalid_reason' => null,
            'last_checked_at' => now(),
        ]);

        return $expiring ? 'expiring' : 'active';
    }

    /**
     * Connection warnings for a single store, shaped for the marketing UI.
     *
     * @return list<array<string, mixed>>
     */
    public function warningsForStore(Store $store): array
    {
        return $store->socialConnections()
            ->whereIn('status', ['expiring', 'invalid'])
            ->get()
            ->map(fn (StoreSocialConnection $connection): array => [
                'connection_id' => (string) $connection->id,
                'provider' => $connection->provider,
                'account_name' => $connection->page_name,
                'status' => $connection->status,
                'expires_at' => $connection->token_expires_at?->toIso8601String(),
                'message' => $connection->status === 'invalid'
                    ? ($connection->invalid_reason ?: 'Reconnect this account to keep publishing.')
                    : 'This connection expires soon. Reconnect to avoid interrupted posting.',
            ])
            ->values()
            ->all();
    }
}
