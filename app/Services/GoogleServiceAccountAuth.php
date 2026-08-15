<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleServiceAccountAuth
{
    public const CLOUD_PLATFORM_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    public const STORAGE_READ_WRITE_SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

    public const STORAGE_FULL_CONTROL_SCOPE = 'https://www.googleapis.com/auth/devstorage.full_control';

    public const VERTEX_TOKEN_CACHE_KEY = 'vertex.access_token';

    /**
     * @param  array{client_email: string, private_key: string, project_id?: string}  $account
     */
    public function accessToken(array $account, string $scope, string $cacheKey): string
    {
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $account['client_email'],
            'scope' => $scope,
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = $header.'.'.$claims;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Could not sign Google service-account JWT.');
        }

        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()
            ->timeout(20)
            ->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        $token = $response->json('access_token');
        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException(
                'Google service-account auth failed: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        Cache::put($cacheKey, $token, max(60, $expiresIn - 120));

        return $token;
    }

    public function forget(string $cacheKey): void
    {
        Cache::forget($cacheKey);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
