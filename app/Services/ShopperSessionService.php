<?php

namespace App\Services;

use App\Models\ShopperSession;
use App\Models\Store;
use Illuminate\Support\Str;

class ShopperSessionService
{
    /**
     * @param  array<string, mixed>  $shopper
     */
    public function findOrCreate(Store $store, ?string $clientKey, array $shopper): ShopperSession
    {
        $clientKey = $this->normalizeClientKey($clientKey);

        $session = ShopperSession::query()
            ->where('store_id', $store->id)
            ->where('client_key', $clientKey)
            ->first();

        if ($session && $session->expires_at !== null && $session->expires_at->isPast()) {
            $session->delete();
            $session = null;
        }

        if ($session) {
            $session->touchExpiry();
            $session->save();

            return $session;
        }

        $session = new ShopperSession([
            'store_id' => $store->id,
            'client_key' => $clientKey,
            'messages' => [[
                'role' => 'assistant',
                'content' => (string) ($shopper['welcome_message'] ?? 'What can I help you find today?'),
            ]],
            'last_recommendation' => null,
            'last_intent' => null,
            'suggestions' => is_array($shopper['default_suggestions'] ?? null)
                ? $shopper['default_suggestions']
                : [],
        ]);
        $session->touchExpiry();
        $session->save();

        return $session;
    }

    public function append(ShopperSession $session, string $role, string $content): void
    {
        $content = trim($content);
        if ($content === '' || ! in_array($role, ['user', 'assistant'], true)) {
            return;
        }

        $messages = is_array($session->messages) ? $session->messages : [];
        $messages[] = [
            'role' => $role,
            'content' => $content,
        ];

        if (count($messages) > ShopperSession::MAX_MESSAGES) {
            $messages = array_slice($messages, -ShopperSession::MAX_MESSAGES);
            if (($messages[0]['role'] ?? null) === 'user') {
                array_unshift($messages, [
                    'role' => 'assistant',
                    'content' => 'Let’s pick up where we left off.',
                ]);
            }
        }

        $session->messages = $messages;
        $session->touchExpiry();
        $session->save();
    }

    /**
     * @param  array<string, mixed>|null  $recommendation
     * @param  array<string, mixed>|null  $intent
     * @param  list<string>  $suggestions
     */
    public function persistTurn(
        ShopperSession $session,
        ?array $recommendation,
        ?array $intent,
        array $suggestions,
    ): void {
        $session->last_recommendation = $recommendation;
        if ($intent !== null) {
            $session->last_intent = $intent;
        }
        $session->suggestions = $suggestions;
        $session->touchExpiry();
        $session->save();
    }

    private function normalizeClientKey(?string $clientKey): string
    {
        $clientKey = is_string($clientKey) ? trim($clientKey) : '';
        if ($clientKey === '') {
            return (string) Str::uuid();
        }

        return Str::limit($clientKey, 64, '');
    }
}
