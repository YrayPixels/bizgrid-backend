<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Merchant;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads and debits purchased SMS / WhatsApp / AI balances in Dodo credit entitlements.
 *
 * Kept separate from DodoPaymentsService so MerchantUsageService can call it without
 * creating a circular dependency (DodoPaymentsService already depends on usage).
 */
class DodoCreditBalanceService
{
    public function isConfigured(): bool
    {
        return filled(config('dodopayments.api_key'));
    }

    public function entitlementId(string $type): ?string
    {
        $key = match ($type) {
            'sms' => 'sms',
            'whatsapp' => 'whatsapp',
            'ai', 'ai_credits' => 'ai',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $id = config("dodopayments.credits.{$key}");

        return filled($id) ? (string) $id : null;
    }

    public function getBalance(Merchant $merchant, string $type): int
    {
        if (! $this->isConfigured() || ! filled($merchant->dodo_customer_id)) {
            return 0;
        }

        $entitlementId = $this->entitlementId($type);
        if ($entitlementId === null) {
            return 0;
        }

        $path = "/credit-entitlements/{$entitlementId}/balances/{$merchant->dodo_customer_id}";

        try {
            $response = Http::withToken(config('dodopayments.api_key'))
                ->acceptJson()
                ->timeout(15)
                ->get($this->baseUrl().$path);

            if ($response->status() === 404) {
                return 0;
            }

            $response->throw();
            $body = $response->json() ?? [];
            $balance = $body['balance'] ?? 0;

            return max(0, (int) floor((float) $balance));
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 404) {
                return 0;
            }

            throw new RuntimeException(
                (string) ($exception->response?->json('message')
                    ?? $exception->response?->json('error')
                    ?? 'Failed to fetch Dodo credit balance.'),
                previous: $exception,
            );
        }
    }

    public function debit(
        Merchant $merchant,
        string $type,
        int $units,
        string $idempotencyKey,
        string $reason,
    ): void {
        if ($units <= 0) {
            return;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Dodo Payments is not configured on the server.');
        }

        if (! filled($merchant->dodo_customer_id)) {
            throw new RuntimeException('No billing customer found for this merchant.');
        }

        $entitlementId = $this->entitlementId($type);
        if ($entitlementId === null) {
            throw new RuntimeException('Credit entitlement is not configured for this usage type.');
        }

        $path = "/credit-entitlements/{$entitlementId}/balances/{$merchant->dodo_customer_id}/ledger-entries";

        try {
            $response = Http::withToken(config('dodopayments.api_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($this->baseUrl().$path, [
                    'amount' => (string) $units,
                    'entry_type' => 'debit',
                    'idempotency_key' => $idempotencyKey,
                    'reason' => $reason,
                ]);

            // Same debit already applied — treat as success.
            if ($response->status() === 409) {
                return;
            }

            $response->throw();
        } catch (RequestException $exception) {
            if ($exception->response?->status() === 409) {
                return;
            }

            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error')
                ?? 'Insufficient purchased credits.';

            throw new RuntimeException((string) $message, previous: $exception);
        }
    }

    private function baseUrl(): string
    {
        return config('dodopayments.environment') === 'live_mode'
            ? 'https://live.dodopayments.com'
            : 'https://test.dodopayments.com';
    }
}
