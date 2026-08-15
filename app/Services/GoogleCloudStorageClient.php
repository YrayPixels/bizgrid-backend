<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCloudStorageClient
{
    public function __construct(
        private readonly PlatformGcsConfigService $config,
        private readonly GoogleServiceAccountAuth $auth,
    ) {}

    public function configured(): bool
    {
        return $this->config->configured();
    }

    public function put(string $objectName, string $contents, string $contentType): string
    {
        $bucket = $this->config->bucket();
        if ($bucket === null) {
            throw new RuntimeException('Google Cloud Storage bucket is not configured.');
        }

        $token = $this->accessToken();
        $encodedName = rawurlencode($objectName);

        $encodedType = rawurlencode($contentType);
        $response = Http::withToken($token)
            ->withBody($contents, $contentType)
            ->timeout(60)
            ->post("https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=media&name={$encodedName}&contentType={$encodedType}");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Google Cloud Storage upload failed: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        return $this->publicUrl($objectName);
    }

    /**
     * @return array{bytes: string, content_type: string}
     */
    public function get(string $objectName): array
    {
        $bucket = $this->config->bucket();
        if ($bucket === null) {
            throw new RuntimeException('Google Cloud Storage bucket is not configured.');
        }

        $token = $this->accessToken();
        $encodedName = rawurlencode($objectName);

        $response = Http::withToken($token)
            ->withHeaders(['Accept' => '*/*'])
            ->timeout(60)
            ->get("https://storage.googleapis.com/storage/v1/b/{$bucket}/o/{$encodedName}?alt=media");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Could not read image from Google Cloud Storage: '.$response->status()
            );
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new RuntimeException('Google Cloud Storage returned an empty file.');
        }

        $mime = strtolower((string) ($response->header('Content-Type') ?: 'application/octet-stream'));
        $mime = trim(explode(';', $mime)[0]);

        return [
            'bytes' => $bytes,
            'content_type' => $mime,
        ];
    }

    public function objectNameFromUrl(string $url): ?string
    {
        $url = trim($url);
        $url = strtok($url, '?') ?: $url;
        if ($url === '' || ! $this->configured()) {
            return null;
        }

        $bucket = $this->config->bucket();
        if ($bucket === null) {
            return null;
        }

        $custom = $this->config->publicUrl();
        if ($custom !== null && str_starts_with($url, $custom.'/')) {
            return rawurldecode(ltrim(substr($url, strlen($custom)), '/'));
        }

        $xmlHost = 'https://storage.googleapis.com/'.$bucket.'/';
        if (str_starts_with($url, $xmlHost)) {
            return rawurldecode(substr($url, strlen($xmlHost)));
        }

        $virtualHost = 'https://'.$bucket.'.storage.googleapis.com/';
        if (str_starts_with($url, $virtualHost)) {
            return rawurldecode(substr($url, strlen($virtualHost)));
        }

        return null;
    }

    public function publicUrl(string $objectName): string
    {
        $custom = $this->config->publicUrl();
        if ($custom !== null) {
            return $custom.'/'.ltrim($objectName, '/');
        }

        $bucket = $this->config->bucket() ?? '';

        return 'https://storage.googleapis.com/'.$bucket.'/'.ltrim($objectName, '/');
    }

    /**
     * Time-limited URL the browser can load when the bucket is private.
     */
    public function signedUrl(string $objectName, int $ttlSeconds = 604800): string
    {
        $objectName = ltrim($objectName, '/');
        if ($objectName === '') {
            throw new RuntimeException('Missing Cloud Storage object name.');
        }

        $ttlSeconds = max(60, min(604800, $ttlSeconds));
        $bucket = $this->config->bucket() ?? '';
        $cacheKey = 'gcs.signed-url.'.hash('sha256', $bucket.'|'.$objectName.'|'.$ttlSeconds);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $url = $this->buildSignedUrl($objectName, $ttlSeconds);
        Cache::put($cacheKey, $url, 3000);

        return $url;
    }

    public function browserUrl(?string $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return $url;
        }

        $objectName = $this->objectNameFromUrl($url);
        if ($objectName === null) {
            return $url;
        }

        try {
            return $this->signedUrl($objectName);
        } catch (\Throwable) {
            return $url;
        }
    }

    private function buildSignedUrl(string $objectName, int $ttlSeconds): string
    {
        $account = $this->config->serviceAccount();
        $bucket = $this->config->bucket();
        if ($account === null || $bucket === null) {
            throw new RuntimeException('Google Cloud Storage credentials are not configured.');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $datestamp = $now->format('Ymd');
        $timestamp = $now->format('Ymd\THis\Z');
        $scope = $datestamp.'/auto/storage/goog4_request';
        $credential = $account['client_email'].'/'.$scope;
        $encodedObject = $this->encodeObjectPath($objectName);
        $canonicalUri = '/'.$bucket.'/'.$encodedObject;

        $query = [
            'X-Goog-Algorithm' => 'GOOG4-RSA-SHA256',
            'X-Goog-Credential' => $credential,
            'X-Goog-Date' => $timestamp,
            'X-Goog-Expires' => (string) $ttlSeconds,
            'X-Goog-SignedHeaders' => 'host',
        ];
        ksort($query);

        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\nhost:storage.googleapis.com\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "GOOG4-RSA-SHA256\n{$timestamp}\n{$scope}\n".hash('sha256', $canonicalRequest);

        $signature = '';
        $ok = openssl_sign($stringToSign, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('Could not sign Google Cloud Storage URL.');
        }

        $query['X-Goog-Signature'] = bin2hex($signature);

        return 'https://storage.googleapis.com/'.$bucket.'/'.$encodedObject.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function encodeObjectPath(string $objectName): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $objectName)));
    }

    /**
     * Upload a tiny object and optionally check that the public URL is readable.
     *
     * @return array{ok: bool, message: string, url: string|null, public: bool|null, hint: string|null}
     */
    public function probe(): array
    {
        if (! $this->configured()) {
            return [
                'ok' => false,
                'message' => 'Bucket or service account JSON is missing.',
                'url' => null,
                'public' => null,
                'hint' => 'Save a bucket name and the service-account JSON first, then test. Do not use a Gemini API key here.',
            ];
        }

        $objectName = trim($this->config->pathPrefix().'/storehause/health/gcs-probe.txt', '/');

        try {
            $url = $this->put(
                $objectName,
                'bizgrid-gcs-ok '.gmdate('c'),
                'text/plain',
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            return [
                'ok' => false,
                'message' => mb_substr($message, 0, 400),
                'url' => null,
                'public' => null,
                'hint' => $this->hintForFailure($message),
            ];
        }

        $readable = null;
        try {
            $public = Http::timeout(15)->get($url);
            $readable = $public->successful();
        } catch (\Throwable) {
            $readable = false;
        }

        if ($readable === true) {
            return [
                'ok' => true,
                'message' => 'Uploaded a test file and the public URL is readable.',
                'url' => $url,
                'public' => true,
                'hint' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Uploaded a test file, but the public URL is not readable yet.',
            'url' => $url,
            'public' => false,
            'hint' => 'The service account can write. Grant allUsers Storage Object Viewer on the bucket (or on the '.$this->config->pathPrefix().'/ prefix) so storefront images load in the browser.',
        ];
    }

    private function hintForFailure(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'billing') || str_contains($lower, 'delinquent')) {
            return 'Google Cloud billing is disabled on the project that owns this bucket (unpaid or failed card). Open console.cloud.google.com/billing, pay or update the payment method, then link that billing account to the GCS project. IAM on the bucket cannot fix this.';
        }

        if (str_contains($lower, 'auth failed') || str_contains($lower, 'invalid_grant') || str_contains($lower, 'jwt')) {
            return 'The JSON was not accepted as a service account. Use Keys → Add key → JSON from IAM → Service accounts, not a Gemini AIza key.';
        }

        if (str_contains($lower, '403') || str_contains($lower, 'forbidden') || str_contains($lower, 'access denied')) {
            return 'The account cannot write to this bucket. Give it Storage Object Admin on the bucket, and confirm the bucket name is exact.';
        }

        if (str_contains($lower, '404') || str_contains($lower, 'not found')) {
            return 'The bucket name is wrong or the bucket is in another project.';
        }

        return 'Check bucket name, project, and that this server can reach oauth2.googleapis.com and storage.googleapis.com.';
    }

    private function accessToken(): string
    {
        $account = $this->config->serviceAccount();
        if ($account === null) {
            throw new RuntimeException('Google Cloud Storage credentials are not configured.');
        }

        return $this->auth->accessToken(
            $account,
            GoogleServiceAccountAuth::STORAGE_READ_WRITE_SCOPE,
            PlatformGcsConfigService::TOKEN_CACHE_KEY,
        );
    }
}
