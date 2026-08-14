<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCloudStorageClient
{
    private const TOKEN_CACHE_KEY = 'gcs.access_token';

    public function configured(): bool
    {
        return filled(config('services.gcs.bucket')) && $this->serviceAccount() !== null;
    }

    public function put(string $objectName, string $contents, string $contentType): string
    {
        $bucket = (string) config('services.gcs.bucket');
        $token = $this->accessToken();
        $encodedName = rawurlencode($objectName);

        $response = Http::withToken($token)
            ->withBody($contents, $contentType)
            ->timeout(60)
            ->post("https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=media&name={$encodedName}");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Google Cloud Storage upload failed: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        return $this->publicUrl($objectName);
    }

    public function publicUrl(string $objectName): string
    {
        $custom = rtrim((string) config('services.gcs.public_url'), '/');
        if ($custom !== '') {
            return $custom.'/'.ltrim($objectName, '/');
        }

        $bucket = (string) config('services.gcs.bucket');

        return 'https://storage.googleapis.com/'.$bucket.'/'.ltrim($objectName, '/');
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = $this->serviceAccount();
        if ($account === null) {
            throw new RuntimeException('Google Cloud Storage credentials are not configured.');
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = $header.'.'.$claims;
        $signature = '';
        $ok = openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Could not sign Google Cloud Storage JWT.');
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
                'Google Cloud Storage auth failed: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $expiresIn - 120));

        return $token;
    }

    /**
     * @return array{client_email: string, private_key: string, project_id?: string}|null
     */
    private function serviceAccount(): ?array
    {
        $raw = config('services.gcs.credentials');
        if (filled($raw) && is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->normalizeServiceAccount($decoded);
            }
        }

        $path = config('services.gcs.key_file') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if (is_string($path) && $path !== '' && is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                return $this->normalizeServiceAccount($decoded);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{client_email: string, private_key: string, project_id?: string}|null
     */
    private function normalizeServiceAccount(array $decoded): ?array
    {
        $email = $decoded['client_email'] ?? null;
        $key = $decoded['private_key'] ?? null;
        if (! is_string($email) || $email === '' || ! is_string($key) || $key === '') {
            return null;
        }

        $account = [
            'client_email' => $email,
            'private_key' => str_replace('\\n', "\n", $key),
        ];

        if (is_string($decoded['project_id'] ?? null) && $decoded['project_id'] !== '') {
            $account['project_id'] = $decoded['project_id'];
        }

        return $account;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
