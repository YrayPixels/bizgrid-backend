<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $base = "https://storage.googleapis.com/upload/storage/v1/b/{$bucket}/o?uploadType=media&name={$encodedName}&contentType={$encodedType}";

        $response = Http::withToken($token)
            ->withBody($contents, $contentType)
            ->timeout(60)
            ->post($base.'&predefinedAcl=publicRead');

        if (! $response->successful()) {
            $response = Http::withToken($token)
                ->withBody($contents, $contentType)
                ->timeout(60)
                ->post($base);
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'Google Cloud Storage upload failed: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        $this->ensurePublicRead();
        $this->makeObjectPublic($objectName);

        return $this->publicUrl($objectName);
    }

    /**
     * Grant public read on the storefront prefix so product and try-on URLs work in the browser forever.
     */
    public function ensurePublicRead(): bool
    {
        $bucket = $this->config->bucket();
        if ($bucket === null || ! $this->configured()) {
            return false;
        }

        $cacheKey = 'gcs.public-iam.'.$bucket.'.'.$this->config->pathPrefix();
        if (Cache::get($cacheKey) === true) {
            return true;
        }

        try {
            $this->grantAllUsersObjectViewer();
            Cache::put($cacheKey, true, 86400);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Could not make Google Cloud Storage prefix public', [
                'bucket' => $bucket,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
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

    public function browserUrl(?string $url): ?string
    {
        return $url;
    }

    /**
     * Upload a tiny object, make the prefix public, and check that the URL is readable.
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
            'hint' => $this->publicAccessHint(),
        ];
    }

    private function grantAllUsersObjectViewer(): void
    {
        $policy = $this->getIamPolicy();
        $bindings = is_array($policy['bindings'] ?? null) ? $policy['bindings'] : [];
        $prefix = $this->config->pathPrefix();
        $bucket = $this->config->bucket() ?? '';
        $conditionExpression = $prefix !== ''
            ? 'resource.name.startsWith("projects/_/buckets/'.$bucket.'/objects/'.$prefix.'/")'
            : null;

        if ($this->hasPublicObjectViewer($bindings, $conditionExpression)
            || $this->hasPublicObjectViewer($bindings, null)) {
            return;
        }

        $binding = [
            'role' => 'roles/storage.objectViewer',
            'members' => ['allUsers'],
        ];
        if ($conditionExpression !== null) {
            $binding['condition'] = [
                'title' => 'Public storefront media',
                'description' => 'Allow anyone to load storefront and try-on images.',
                'expression' => $conditionExpression,
            ];
        }

        $attempt = $policy;
        $attempt['version'] = 3;
        $attempt['bindings'] = array_values([...$bindings, $binding]);
        $put = $this->putIamPolicy($attempt);
        if ($put->successful()) {
            return;
        }

        $body = mb_substr($put->body(), 0, 400);
        if ($conditionExpression === null || ! $this->shouldRetryWithoutCondition($body)) {
            throw new RuntimeException('Could not grant public read on Cloud Storage: '.$put->status().' '.$body);
        }

        $fresh = $this->getIamPolicy();
        $freshBindings = is_array($fresh['bindings'] ?? null) ? $fresh['bindings'] : [];
        if ($this->hasPublicObjectViewer($freshBindings, null)) {
            return;
        }

        $fresh['version'] = 3;
        $fresh['bindings'] = array_values([...$freshBindings, [
            'role' => 'roles/storage.objectViewer',
            'members' => ['allUsers'],
        ]]);
        $retry = $this->putIamPolicy($fresh);
        if ($retry->successful()) {
            return;
        }

        throw new RuntimeException(
            'Could not grant public read on Cloud Storage: '.$retry->status().' '.mb_substr($retry->body(), 0, 400)
        );
    }

    /** @return array<string, mixed> */
    private function getIamPolicy(): array
    {
        $bucket = $this->config->bucket();
        if ($bucket === null) {
            throw new RuntimeException('Google Cloud Storage bucket is not configured.');
        }

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->get("https://storage.googleapis.com/storage/v1/b/{$bucket}/iam?optionsRequestedPolicyVersion=3");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Could not read Cloud Storage IAM: '.$response->status().' '.mb_substr($response->body(), 0, 300)
            );
        }

        $policy = $response->json();
        if (! is_array($policy)) {
            throw new RuntimeException('Cloud Storage IAM policy was unreadable.');
        }

        return $policy;
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function putIamPolicy(array $policy): \Illuminate\Http\Client\Response
    {
        $bucket = $this->config->bucket();

        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->put("https://storage.googleapis.com/storage/v1/b/{$bucket}/iam?optionsRequestedPolicyVersion=3", $policy);
    }

    /**
     * @param  list<mixed>  $bindings
     */
    private function hasPublicObjectViewer(array $bindings, ?string $conditionExpression): bool
    {
        foreach ($bindings as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $role = (string) ($binding['role'] ?? '');
            $members = $binding['members'] ?? [];
            if (! in_array($role, ['roles/storage.objectViewer', 'roles/storage.legacyObjectReader'], true)) {
                continue;
            }
            if (! is_array($members) || ! in_array('allUsers', $members, true)) {
                continue;
            }
            $expression = is_array($binding['condition'] ?? null)
                ? ($binding['condition']['expression'] ?? null)
                : null;
            if ($conditionExpression === null && $expression === null) {
                return true;
            }
            if ($conditionExpression !== null && $expression === $conditionExpression) {
                return true;
            }
            if ($conditionExpression !== null && $expression === null) {
                return true;
            }
        }

        return false;
    }

    private function shouldRetryWithoutCondition(string $body): bool
    {
        $lower = strtolower($body);

        return str_contains($lower, 'condition')
            || str_contains($lower, 'uniform')
            || str_contains($lower, 'fine-grained');
    }

    private function makeObjectPublic(string $objectName): void
    {
        $bucket = $this->config->bucket();
        if ($bucket === null) {
            return;
        }

        try {
            $encodedName = rawurlencode($objectName);
            Http::withToken($this->accessToken())
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post("https://storage.googleapis.com/storage/v1/b/{$bucket}/o/{$encodedName}/acl", [
                    'entity' => 'allUsers',
                    'role' => 'READER',
                ]);
        } catch (\Throwable) {
            // Uniform buckets reject ACLs; IAM on the prefix covers those.
        }
    }

    private function publicAccessHint(): string
    {
        $prefix = $this->config->pathPrefix();
        $target = $prefix !== '' ? $prefix.'/' : 'the bucket';

        return 'The service account can write, but public access is blocked. In Cloud Storage → bucket → Configuration, set Public access prevention to Inherited. Then grant allUsers the Storage Object Viewer role on '.$target.' (Permissions). The service account also needs Storage Admin if you want Bizgrid to do this automatically.';
    }

    private function hintForFailure(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'billing') || str_contains($lower, 'delinquent')) {
            return 'Google Cloud billing is disabled on the project that owns this bucket (unpaid or failed card). Open console.cloud.google.com/billing, pay or update the payment method, then link that billing account to the GCS project. IAM on the bucket cannot fix this.';
        }

        if (str_contains($lower, 'public access prevention')) {
            return $this->publicAccessHint();
        }

        if (str_contains($lower, 'auth failed') || str_contains($lower, 'invalid_grant') || str_contains($lower, 'jwt')) {
            return 'The JSON was not accepted as a service account. Use Keys → Add key → JSON from IAM → Service accounts, not a Gemini AIza key.';
        }

        if (str_contains($lower, '403') || str_contains($lower, 'forbidden') || str_contains($lower, 'access denied')) {
            return 'The account cannot write to this bucket. Give it Storage Object Admin on the bucket (Storage Admin if you want Bizgrid to make images public), and confirm the bucket name is exact.';
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
            GoogleServiceAccountAuth::STORAGE_FULL_CONTROL_SCOPE,
            PlatformGcsConfigService::TOKEN_CACHE_KEY,
        );
    }
}
