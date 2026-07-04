<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class DodoWebhookVerifier
{
    public function verify(string $payload, array $headers, string $secret): void
    {
        $webhookId = $headers['webhook-id'] ?? $headers['Webhook-Id'] ?? null;
        $timestamp = $headers['webhook-timestamp'] ?? $headers['Webhook-Timestamp'] ?? null;
        $signature = $headers['webhook-signature'] ?? $headers['Webhook-Signature'] ?? null;

        if (! is_string($webhookId) || ! is_string($timestamp) || ! is_string($signature)) {
            throw new RuntimeException('Missing webhook signature headers.');
        }

        $signedContent = "{$webhookId}.{$timestamp}.{$payload}";
        $secretBytes = $this->decodeSecret($secret);
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

        foreach (preg_split('/\s+/', trim($signature)) ?: [] as $part) {
            $value = str_contains($part, ',') ? explode(',', $part, 2)[1] : $part;
            if (hash_equals($expected, $value)) {
                return;
            }
        }

        throw new RuntimeException('Invalid webhook signature.');
    }

    private function decodeSecret(string $secret): string
    {
        if (str_contains($secret, '_')) {
            $parts = explode('_', $secret, 2);
            if (isset($parts[1]) && $parts[1] !== '') {
                $decoded = base64_decode($parts[1], true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }
        }

        return $secret;
    }
}
