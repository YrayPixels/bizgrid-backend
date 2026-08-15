<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PlatformGcsConfigService
{
    private const CACHE_KEY = 'platform.gcs.config';

    public const TOKEN_CACHE_KEY = 'gcs.access_token';

    public const DRIVER_LOCAL = 'local';

    public const DRIVER_GCS = 'gcs';

    public function configured(): bool
    {
        return filled($this->bucket()) && $this->serviceAccount() !== null;
    }

    public function driver(): string
    {
        $stored = $this->normalizeDriver($this->stored('gcs.driver') ?? config('services.gcs.driver'));
        if ($stored !== null) {
            return $stored;
        }

        return $this->configured() ? self::DRIVER_GCS : self::DRIVER_LOCAL;
    }

    public function usingCloud(): bool
    {
        return $this->driver() === self::DRIVER_GCS && $this->configured();
    }

    public function bucket(): ?string
    {
        return $this->filledValue($this->stored('gcs.bucket') ?? config('services.gcs.bucket'));
    }

    public function projectId(): ?string
    {
        $stored = $this->filledValue($this->stored('gcs.project_id') ?? config('services.gcs.project_id'));
        if ($stored !== null) {
            return $stored;
        }

        return $this->serviceAccount()['project_id'] ?? null;
    }

    public function pathPrefix(): string
    {
        $prefix = $this->stored('gcs.path_prefix') ?? config('services.gcs.path_prefix', 'bizgrid');

        return trim((string) $prefix, '/');
    }

    public function publicUrl(): ?string
    {
        $url = $this->filledValue($this->stored('gcs.public_url') ?? config('services.gcs.public_url'));

        return $url !== null ? rtrim($url, '/') : null;
    }

    /**
     * @return array{client_email: string, private_key: string, project_id?: string}|null
     */
    public function serviceAccount(): ?array
    {
        $raw = $this->credentialsJson();
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $this->normalizeServiceAccount($decoded);
    }

    /**
     * @return array{
     *     driver: string,
     *     using_cloud: bool,
     *     configured: bool,
     *     project_id: string|null,
     *     bucket: string|null,
     *     path_prefix: string,
     *     public_url: string|null,
     *     credentials_configured: bool,
     *     credentials_preview: string|null
     * }
     */
    public function adminConfig(): array
    {
        $account = $this->serviceAccount();

        return [
            'driver' => $this->driver(),
            'using_cloud' => $this->usingCloud(),
            'configured' => $this->configured(),
            'project_id' => $this->projectId(),
            'bucket' => $this->bucket(),
            'path_prefix' => $this->pathPrefix(),
            'public_url' => $this->publicUrl(),
            'credentials_configured' => $account !== null,
            'credentials_preview' => $account['client_email'] ?? null,
        ];
    }

    /**
     * @param  array{
     *     driver?: string|null,
     *     bucket?: string|null,
     *     project_id?: string|null,
     *     path_prefix?: string|null,
     *     public_url?: string|null,
     *     credentials?: string|null
     * }  $input
     */
    public function update(array $input): array
    {
        if (array_key_exists('driver', $input)) {
            $driver = $this->normalizeDriver($input['driver']);
            if ($driver !== null) {
                $this->persist('gcs.driver', $driver);
            } elseif ($input['driver'] === null || trim((string) $input['driver']) === '') {
                PlatformSetting::query()->where('key', 'gcs.driver')->delete();
                $this->clearCache();
            }
        }

        if (array_key_exists('bucket', $input)) {
            $this->maybePersistValue('gcs.bucket', $input['bucket']);
        }

        if (array_key_exists('project_id', $input)) {
            $this->maybePersistValue('gcs.project_id', $input['project_id']);
        }

        if (array_key_exists('path_prefix', $input)) {
            $prefix = is_string($input['path_prefix']) ? trim($input['path_prefix'], '/') : '';
            $this->maybePersistValue('gcs.path_prefix', $prefix === '' ? 'bizgrid' : $prefix);
        }

        if (array_key_exists('public_url', $input)) {
            $this->maybePersistValue('gcs.public_url', $input['public_url']);
        }

        if (array_key_exists('credentials', $input)) {
            $this->persistCredentials($input['credentials']);
        }

        $this->clearCache();

        return $this->adminConfig();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    private function persistCredentials(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            PlatformSetting::query()->where('key', 'gcs.credentials')->delete();
            $this->clearCache();

            return;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded) || $this->normalizeServiceAccount($decoded) === null) {
            throw new \InvalidArgumentException(
                'Service account JSON must include client_email and private_key.'
            );
        }

        $this->persist('gcs.credentials', Crypt::encryptString($trimmed));
    }

    private function credentialsJson(): ?string
    {
        $stored = $this->stored('gcs.credentials');
        if (filled($stored)) {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                return $stored;
            }
        }

        $env = config('services.gcs.credentials');
        if (filled($env) && is_string($env)) {
            return $env;
        }

        $path = config('services.gcs.key_file') ?: getenv('GOOGLE_APPLICATION_CREDENTIALS');
        if (is_string($path) && $path !== '' && is_file($path)) {
            $contents = file_get_contents($path);

            return is_string($contents) && $contents !== '' ? $contents : null;
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

    private function maybePersistValue(string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            PlatformSetting::query()->where('key', $key)->delete();
            $this->clearCache();

            return;
        }

        $this->persist($key, $trimmed);
    }

    private function persist(string $key, string $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->clearCache();
    }

    private function stored(string $key): ?string
    {
        $settings = Cache::remember(self::CACHE_KEY, 300, function () {
            return PlatformSetting::query()
                ->whereIn('key', [
                    'gcs.driver',
                    'gcs.bucket',
                    'gcs.project_id',
                    'gcs.path_prefix',
                    'gcs.public_url',
                    'gcs.credentials',
                ])
                ->pluck('value', 'key')
                ->all();
        });

        $value = $settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function filledValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeDriver(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $driver = strtolower(trim($value));

        return in_array($driver, [self::DRIVER_LOCAL, self::DRIVER_GCS], true) ? $driver : null;
    }
}
