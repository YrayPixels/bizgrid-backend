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
        return Socialite::driver('google')
            ->redirectUrl($this->redirectUri())
            ->stateless()
            ->user();
    }

    public function consumeIntent(string $state): ?string
    {
        $payload = Cache::pull($this->stateCacheKey($state));

        if (! is_array($payload)) {
            return null;
        }

        $intent = $payload['intent'] ?? null;

        return is_string($intent) && in_array($intent, ['merchant', 'admin'], true)
            ? $intent
            : null;
    }

    private function stateCacheKey(string $state): string
    {
        return 'google_oauth:'.$state;
    }
}
