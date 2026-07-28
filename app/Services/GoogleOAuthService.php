<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

class GoogleOAuthService
{
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function redirectUri(): string
    {
        $configured = config('services.google.redirect');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/api/storehause/auth/google/callback';
    }

    public function redirect(string $intent): RedirectResponse
    {
        $state = Str::random(48);
        Cache::put($this->stateCacheKey($state), [
            'intent' => $intent,
        ], now()->addMinutes(15));

        return Socialite::driver('google')
            ->redirectUrl($this->redirectUri())
            ->stateless()
            ->with(['state' => $state])
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function fetchUser(string $state): SocialiteUser
    {
        // Validate state before exchanging the auth code, then consume it.
        $cachedData = Cache::get($this->stateCacheKey($state));
        if (! is_array($cachedData)) {
            throw new \RuntimeException('Invalid or expired OAuth state parameter.');
        }

        $user = Socialite::driver('google')
            ->redirectUrl($this->redirectUri())
            ->stateless()
            ->user();

        Cache::forget($this->stateCacheKey($state));

        return $user;
    }

    /**
     * Read OAuth intent without consuming state (fetchUser consumes after success).
     */
    public function peekIntent(string $state): ?string
    {
        $payload = Cache::get($this->stateCacheKey($state));

        if (! is_array($payload)) {
            return null;
        }

        $intent = $payload['intent'] ?? null;

        return is_string($intent) && in_array($intent, ['merchant', 'admin'], true)
            ? $intent
            : null;
    }

    /** @deprecated Use peekIntent + fetchUser (which consumes state). */
    public function consumeIntent(string $state): ?string
    {
        return $this->peekIntent($state);
    }

    private function stateCacheKey(string $state): string
    {
        return 'google_oauth:'.$state;
    }
}
