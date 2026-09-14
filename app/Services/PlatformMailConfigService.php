<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformMailConfigService
{
    private const CACHE_KEY = 'platform.mail.config';

    private const PROVIDERS_KEY = 'mail.providers';

    private const ACTIVE_KEY = 'mail.active_provider_id';

    /** @var list<string> Legacy flat keys kept for migration / fallback. */
    private const LEGACY_KEYS = [
        'mail.mailer',
        'mail.scheme',
        'mail.host',
        'mail.port',
        'mail.username',
        'mail.password',
        'mail.from_address',
        'mail.from_name',
    ];

    /** @var list<string> */
    public const ALLOWED_DRIVERS = ['smtp', 'log', 'ses', 'resend', 'sendmail', 'array'];

    /** @var list<string> */
    public const ALLOWED_MAILERS = self::ALLOWED_DRIVERS;

    /**
     * Built-in provider templates shown in admin.
     *
     * @return list<array{id: string, label: string, driver: string, scheme: string|null, host: string|null, port: int|null, hint: string}>
     */
    public static function presets(): array
    {
        return [
            [
                'id' => 'custom_smtp',
                'label' => 'Custom SMTP',
                'driver' => 'smtp',
                'scheme' => 'smtps',
                'host' => null,
                'port' => 465,
                'hint' => 'cPanel, private mail server, or any SMTP host.',
            ],
            [
                'id' => 'gmail',
                'label' => 'Gmail / Google Workspace',
                'driver' => 'smtp',
                'scheme' => 'smtps',
                'host' => 'smtp.gmail.com',
                'port' => 465,
                'hint' => 'Use an App Password, not your normal Google password.',
            ],
            [
                'id' => 'outlook',
                'label' => 'Outlook / Microsoft 365',
                'driver' => 'smtp',
                'scheme' => 'smtp',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'hint' => 'STARTTLS on port 587.',
            ],
            [
                'id' => 'sendgrid',
                'label' => 'SendGrid SMTP',
                'driver' => 'smtp',
                'scheme' => 'smtps',
                'host' => 'smtp.sendgrid.net',
                'port' => 465,
                'hint' => 'Username is usually "apikey"; password is the API key.',
            ],
            [
                'id' => 'mailgun',
                'label' => 'Mailgun SMTP',
                'driver' => 'smtp',
                'scheme' => 'smtps',
                'host' => 'smtp.mailgun.org',
                'port' => 465,
                'hint' => 'Use the SMTP credentials from your Mailgun domain.',
            ],
            [
                'id' => 'amazon_ses',
                'label' => 'Amazon SES',
                'driver' => 'ses',
                'scheme' => null,
                'host' => null,
                'port' => null,
                'hint' => 'Uses AWS_* credentials from the server .env.',
            ],
            [
                'id' => 'resend',
                'label' => 'Resend',
                'driver' => 'resend',
                'scheme' => null,
                'host' => null,
                'port' => null,
                'hint' => 'Needs RESEND_KEY in the server .env.',
            ],
            [
                'id' => 'log',
                'label' => 'Log only',
                'driver' => 'log',
                'scheme' => null,
                'host' => null,
                'port' => null,
                'hint' => 'Writes to the app log — local development only.',
            ],
        ];
    }

    public function configured(): bool
    {
        $driver = $this->mailer();
        if ($driver === 'log' || $driver === 'array') {
            return true;
        }

        if ($driver === 'smtp') {
            return filled($this->host())
                && filled($this->fromAddress())
                && $this->port() > 0;
        }

        return filled($this->fromAddress());
    }

    public function mailer(): string
    {
        return $this->activeProvider()['driver'] ?? $this->envMailer();
    }

    public function scheme(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            $scheme = $this->filledValue($active['scheme'] ?? null);

            return $scheme !== null ? strtolower($scheme) : null;
        }

        $fromEnv = $this->filledValue(config('mail.mailers.smtp.scheme'));

        return $fromEnv !== null ? strtolower($fromEnv) : null;
    }

    public function host(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            return $this->filledValue($active['host'] ?? null) ?? $this->filledValue(config('mail.mailers.smtp.host'));
        }

        return $this->filledValue(config('mail.mailers.smtp.host'));
    }

    public function port(): int
    {
        $active = $this->activeProvider();
        if ($active !== null && isset($active['port']) && is_numeric($active['port'])) {
            return (int) $active['port'];
        }

        return (int) config('mail.mailers.smtp.port', 2525);
    }

    public function username(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            return $this->filledValue($active['username'] ?? null)
                ?? $this->filledValue(config('mail.mailers.smtp.username'));
        }

        return $this->filledValue(config('mail.mailers.smtp.username'));
    }

    public function password(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            $password = $this->filledValue($active['password'] ?? null);
            if ($password !== null) {
                return $password;
            }
        }

        $fromEnv = config('mail.mailers.smtp.password');

        return $this->filledValue(is_string($fromEnv) ? $fromEnv : null);
    }

    public function fromAddress(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            return $this->filledValue($active['from_address'] ?? null)
                ?? $this->filledValue(config('mail.from.address'));
        }

        return $this->filledValue(config('mail.from.address'));
    }

    public function fromName(): ?string
    {
        $active = $this->activeProvider();
        if ($active !== null) {
            return $this->filledValue($active['from_name'] ?? null)
                ?? $this->filledValue(config('mail.from.name'));
        }

        return $this->filledValue(config('mail.from.name'));
    }

    public function activeProviderId(): ?string
    {
        return $this->activeProvider()['id'] ?? null;
    }

    /**
     * Push resolved mail settings into Laravel config so existing Mail:: / mailable callers pick them up.
     */
    public function applyToRuntime(): void
    {
        if (! $this->settingsTableReady()) {
            return;
        }

        $mailer = $this->mailer();

        Config::set('mail.default', $mailer);
        Config::set('mail.mailers.smtp.scheme', $this->scheme());
        Config::set('mail.mailers.smtp.host', $this->host());
        Config::set('mail.mailers.smtp.port', $this->port());
        Config::set('mail.mailers.smtp.username', $this->username());
        Config::set('mail.mailers.smtp.password', $this->password());
        Config::set('mail.from.address', $this->fromAddress());
        Config::set('mail.from.name', $this->fromName());

        try {
            Mail::purge($mailer);
            if ($mailer !== 'smtp') {
                Mail::purge('smtp');
            }
        } catch (\Throwable) {
            // Mail manager may not be resolved yet during early boot.
        }
    }

    /**
     * @return array{
     *     configured: bool,
     *     active_provider_id: string|null,
     *     providers: list<array<string, mixed>>,
     *     presets: list<array<string, mixed>>,
     *     mailer: string,
     *     scheme: string|null,
     *     host: string|null,
     *     port: int,
     *     username: string|null,
     *     password_configured: bool,
     *     password_preview: string|null,
     *     from_address: string|null,
     *     from_name: string|null,
     *     source: string
     * }
     */
    public function adminConfig(): array
    {
        $password = $this->password();
        $providers = $this->providersForAdmin();
        $hasDbOverride = $providers !== [] || $this->hasLegacyStoredKeys();

        return [
            'configured' => $this->configured(),
            'active_provider_id' => $this->activeProviderId(),
            'providers' => $providers,
            'presets' => self::presets(),
            'mailer' => $this->mailer(),
            'scheme' => $this->scheme(),
            'host' => $this->host(),
            'port' => $this->port(),
            'username' => $this->username(),
            'password_configured' => filled($password),
            'password_preview' => $this->maskSecret($password),
            'from_address' => $this->fromAddress(),
            'from_name' => $this->fromName(),
            'source' => $hasDbOverride ? 'admin' : 'env',
        ];
    }

    /**
     * @param  array{
     *     active_provider_id?: string|null,
     *     upsert_provider?: array<string, mixed>|null,
     *     delete_provider_id?: string|null,
     *     mailer?: string|null,
     *     scheme?: string|null,
     *     host?: string|null,
     *     port?: int|string|null,
     *     username?: string|null,
     *     password?: string|null,
     *     from_address?: string|null,
     *     from_name?: string|null,
     *     name?: string|null,
     *     preset?: string|null
     * }  $input
     */
    public function update(array $input): array
    {
        $providers = $this->rawProviders();

        if (array_key_exists('delete_provider_id', $input) && filled($input['delete_provider_id'])) {
            $deleteId = (string) $input['delete_provider_id'];
            $providers = array_values(array_filter(
                $providers,
                fn (array $provider): bool => ($provider['id'] ?? null) !== $deleteId
            ));

            $activeId = $this->stored(self::ACTIVE_KEY);
            if ($activeId === $deleteId) {
                $activeId = $providers[0]['id'] ?? null;
                if ($activeId !== null) {
                    $this->persist(self::ACTIVE_KEY, $activeId);
                } else {
                    PlatformSetting::query()->where('key', self::ACTIVE_KEY)->delete();
                }
            }

            $this->persistProviders($providers);
            $this->clearLegacyFlatKeys();
            $this->clearCache();
            $this->applyToRuntime();

            return $this->adminConfig();
        }

        if (is_array($input['upsert_provider'] ?? null)) {
            $beforeCount = count($providers);
            $providers = $this->upsertProvider($providers, $input['upsert_provider']);
            $this->persistProviders($providers);

            $requestedId = $this->filledValue($input['upsert_provider']['id'] ?? null);
            $upsertedId = null;
            if ($requestedId !== null && $requestedId !== 'legacy-default') {
                foreach ($providers as $provider) {
                    if (($provider['id'] ?? null) === $requestedId) {
                        $upsertedId = $provider['id'];
                        break;
                    }
                }
            }
            if ($upsertedId === null) {
                $upsertedId = $providers[array_key_last($providers)]['id'] ?? null;
            }

            $shouldActivate = ($input['activate'] ?? true) || $beforeCount === 0;
            if ($upsertedId !== null && $shouldActivate) {
                $this->persist(self::ACTIVE_KEY, (string) $upsertedId);
            }

            $this->clearLegacyFlatKeys();
            $this->clearCache();
            $this->applyToRuntime();

            return $this->adminConfig();
        }

        // Legacy / single-form flat payload: create or update the active provider.
        if ($this->hasFlatMailFields($input)) {
            $active = $this->activeProvider();
            $existingEncrypted = null;
            if ($active !== null) {
                foreach ($this->rawProviders() as $raw) {
                    if (($raw['id'] ?? null) === ($active['id'] ?? null)) {
                        $existingEncrypted = $raw;
                        break;
                    }
                }
                if ($existingEncrypted === null && ($active['id'] ?? null) === 'legacy-default') {
                    $existingEncrypted = $this->withEncryptedPassword($active);
                }
            }

            $isLegacy = ($active['id'] ?? null) === 'legacy-default';
            $flat = [
                'id' => $isLegacy ? null : ($active['id'] ?? null),
                'name' => $this->filledValue($input['name'] ?? null) ?? ($active['name'] ?? 'Default'),
                'preset' => $this->filledValue($input['preset'] ?? null) ?? ($active['preset'] ?? 'custom_smtp'),
                'driver' => $input['mailer'] ?? ($active['driver'] ?? 'smtp'),
                'scheme' => array_key_exists('scheme', $input) ? $input['scheme'] : ($active['scheme'] ?? null),
                'host' => array_key_exists('host', $input) ? $input['host'] : ($active['host'] ?? null),
                'port' => array_key_exists('port', $input) ? $input['port'] : ($active['port'] ?? null),
                'username' => array_key_exists('username', $input) ? $input['username'] : ($active['username'] ?? null),
                'password' => $input['password'] ?? null,
                'from_address' => array_key_exists('from_address', $input) ? $input['from_address'] : ($active['from_address'] ?? null),
                'from_name' => array_key_exists('from_name', $input) ? $input['from_name'] : ($active['from_name'] ?? null),
            ];

            if ($isLegacy || ($providers === [] && $existingEncrypted !== null)) {
                $normalized = $this->normalizeProviderForStorage($flat, $existingEncrypted ?? []);
                $normalized['id'] = (string) Str::uuid();
                $providers = [$normalized];
                $this->persistProviders($providers);
                $this->persist(self::ACTIVE_KEY, $normalized['id']);
                $this->clearLegacyFlatKeys();
                $this->clearCache();
                $this->applyToRuntime();

                return $this->adminConfig();
            }

            $providers = $this->upsertProvider($providers, $flat);
            $savedId = null;
            if (filled($flat['id'])) {
                foreach ($providers as $provider) {
                    if (($provider['id'] ?? null) === $flat['id']) {
                        $savedId = $provider['id'];
                        break;
                    }
                }
            }
            $savedId ??= $providers[array_key_last($providers)]['id'] ?? null;

            $this->persistProviders($providers);
            if ($savedId !== null) {
                $this->persist(self::ACTIVE_KEY, (string) $savedId);
            }
            $this->clearLegacyFlatKeys();
            $this->clearCache();
            $this->applyToRuntime();

            return $this->adminConfig();
        }

        if (array_key_exists('active_provider_id', $input) && filled($input['active_provider_id'])) {
            $activeId = (string) $input['active_provider_id'];
            $exists = collect($providers)->contains(fn (array $provider): bool => ($provider['id'] ?? null) === $activeId);
            if (! $exists) {
                throw new \InvalidArgumentException('Unknown mail provider.');
            }
            $this->persist(self::ACTIVE_KEY, $activeId);
        }

        $this->clearCache();
        $this->applyToRuntime();

        return $this->adminConfig();
    }

    /**
     * @return array{ok: bool, message: string, error: string|null, config: array<string, mixed>}
     */
    public function probe(?string $to = null): array
    {
        $this->applyToRuntime();

        $recipient = $this->filledValue($to) ?? $this->fromAddress();
        $active = $this->activeProvider();
        $config = [
            'provider_id' => $active['id'] ?? null,
            'provider_name' => $active['name'] ?? null,
            'mailer' => $this->mailer(),
            'scheme' => $this->scheme(),
            'host' => $this->host(),
            'port' => $this->port(),
            'username_set' => filled($this->username()),
            'from_address' => $this->fromAddress(),
            'from_name' => $this->fromName(),
            'to' => $recipient,
            'source' => ($this->providersForAdmin() !== [] || $this->hasLegacyStoredKeys()) ? 'admin' : 'env',
        ];

        if ($recipient === null || ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'message' => 'Provide a valid recipient email address.',
                'error' => 'invalid_recipient',
                'config' => $config,
            ];
        }

        try {
            Mail::raw(
                'Bizgrid mail test at '.now()->toIso8601String()."\n\nIf you received this, mail delivery is working.",
                function ($message) use ($recipient): void {
                    $message->to($recipient)->subject('Bizgrid mail test');
                }
            );

            return [
                'ok' => true,
                'message' => 'Test email accepted by the mailer.',
                'error' => null,
                'config' => $config,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Test email failed.',
                'error' => $e->getMessage(),
                'config' => $config,
            ];
        }
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeProvider(): ?array
    {
        $providers = $this->decryptedProviders();
        if ($providers === []) {
            return $this->legacyProvider();
        }

        $activeId = $this->stored(self::ACTIVE_KEY);
        if (filled($activeId)) {
            foreach ($providers as $provider) {
                if (($provider['id'] ?? null) === $activeId) {
                    return $provider;
                }
            }
        }

        return $providers[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function providersForAdmin(): array
    {
        $providers = $this->decryptedProviders();
        if ($providers === []) {
            $legacy = $this->legacyProvider();
            if ($legacy === null) {
                return [];
            }
            $providers = [$legacy];
        }

        return array_map(function (array $provider): array {
            $password = $this->filledValue($provider['password'] ?? null);

            return [
                'id' => $provider['id'],
                'name' => $provider['name'] ?? 'Provider',
                'preset' => $provider['preset'] ?? 'custom_smtp',
                'driver' => $provider['driver'] ?? 'smtp',
                'scheme' => $provider['scheme'] ?? null,
                'host' => $provider['host'] ?? null,
                'port' => isset($provider['port']) && is_numeric($provider['port']) ? (int) $provider['port'] : null,
                'username' => $provider['username'] ?? null,
                'password_configured' => filled($password),
                'password_preview' => $this->maskSecret($password),
                'from_address' => $provider['from_address'] ?? null,
                'from_name' => $provider['from_name'] ?? null,
            ];
        }, $providers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decryptedProviders(): array
    {
        return array_map(function (array $provider): array {
            if (filled($provider['password'] ?? null)) {
                try {
                    $provider['password'] = Crypt::decryptString((string) $provider['password']);
                } catch (\Throwable) {
                    // Already plaintext (should be rare).
                }
            }

            return $provider;
        }, $this->rawProviders());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawProviders(): array
    {
        $raw = $this->stored(self::PROVIDERS_KEY);
        if (! filled($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $providers = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || ! filled($item['id'] ?? null)) {
                continue;
            }
            $providers[] = $item;
        }

        return $providers;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function legacyProvider(): ?array
    {
        if (! $this->hasLegacyStoredKeys()) {
            return null;
        }

        $mailer = $this->normalizeDriver($this->plainLegacy('mail.mailer')) ?? $this->envMailer();
        $password = $this->secretLegacy('mail.password');

        return [
            'id' => 'legacy-default',
            'name' => 'Default',
            'preset' => 'custom_smtp',
            'driver' => $mailer,
            'scheme' => $this->plainLegacy('mail.scheme'),
            'host' => $this->plainLegacy('mail.host'),
            'port' => $this->plainLegacy('mail.port'),
            'username' => $this->plainLegacy('mail.username'),
            'password' => $password,
            'from_address' => $this->plainLegacy('mail.from_address'),
            'from_name' => $this->plainLegacy('mail.from_name'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $providers
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function upsertProvider(array $providers, array $input): array
    {
        $id = $this->filledValue($input['id'] ?? null);
        $existing = null;
        $index = null;

        if ($id !== null) {
            foreach ($providers as $i => $provider) {
                if (($provider['id'] ?? null) === $id) {
                    $existing = $provider;
                    $index = $i;
                    break;
                }
            }
        }

        // Migrating legacy-default into a real uuid provider.
        if ($id === 'legacy-default') {
            $id = (string) Str::uuid();
            $existing = $this->withEncryptedPassword($this->legacyProvider() ?? []);
            $index = null;
        }

        if ($id === null) {
            $id = (string) Str::uuid();
        }

        $normalized = $this->normalizeProviderForStorage($input, $existing ?? []);
        $normalized['id'] = $id;

        if ($index !== null) {
            $providers[$index] = $normalized;
        } else {
            $providers[] = $normalized;
        }

        return array_values($providers);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing Encrypted or decrypted existing row
     * @return array<string, mixed>
     */
    private function normalizeProviderForStorage(array $input, array $existing): array
    {
        $driver = $this->normalizeDriver($input['driver'] ?? $input['mailer'] ?? ($existing['driver'] ?? null)) ?? 'smtp';
        $preset = $this->filledValue($input['preset'] ?? null) ?? ($existing['preset'] ?? 'custom_smtp');
        $name = $this->filledValue($input['name'] ?? null) ?? ($existing['name'] ?? 'Provider');

        $scheme = array_key_exists('scheme', $input)
            ? $this->filledValue(is_string($input['scheme'] ?? null) ? $input['scheme'] : null)
            : ($existing['scheme'] ?? null);

        $host = array_key_exists('host', $input)
            ? $this->filledValue(is_string($input['host'] ?? null) ? $input['host'] : null)
            : ($existing['host'] ?? null);

        $port = null;
        if (array_key_exists('port', $input)) {
            if (is_numeric($input['port'])) {
                $port = (int) $input['port'];
            }
        } elseif (isset($existing['port']) && is_numeric($existing['port'])) {
            $port = (int) $existing['port'];
        }

        $username = array_key_exists('username', $input)
            ? $this->filledValue(is_string($input['username'] ?? null) ? $input['username'] : null)
            : ($existing['username'] ?? null);

        $fromAddress = array_key_exists('from_address', $input)
            ? $this->filledValue(is_string($input['from_address'] ?? null) ? $input['from_address'] : null)
            : ($existing['from_address'] ?? null);

        $fromName = array_key_exists('from_name', $input)
            ? $this->filledValue(is_string($input['from_name'] ?? null) ? $input['from_name'] : null)
            : ($existing['from_name'] ?? null);

        $password = $existing['password'] ?? null;
        if (array_key_exists('password', $input)) {
            $incoming = is_string($input['password']) ? trim($input['password']) : '';
            if ($incoming !== '') {
                $password = Crypt::encryptString($incoming);
            } elseif (filled($password) && ! $this->looksEncrypted((string) $password)) {
                $password = Crypt::encryptString((string) $password);
            }
        } elseif (filled($password) && ! $this->looksEncrypted((string) $password)) {
            $password = Crypt::encryptString((string) $password);
        }

        return [
            'id' => $existing['id'] ?? (string) Str::uuid(),
            'name' => $name,
            'preset' => $preset,
            'driver' => $driver,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
        ];
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function withEncryptedPassword(array $provider): array
    {
        if (filled($provider['password'] ?? null) && ! $this->looksEncrypted((string) $provider['password'])) {
            $provider['password'] = Crypt::encryptString((string) $provider['password']);
        }

        return $provider;
    }

    /**
     * @param  list<array<string, mixed>>  $providers
     */
    private function persistProviders(array $providers): void
    {
        if ($providers === []) {
            PlatformSetting::query()->where('key', self::PROVIDERS_KEY)->delete();
            PlatformSetting::query()->where('key', self::ACTIVE_KEY)->delete();
            $this->clearCache();

            return;
        }

        $this->persist(self::PROVIDERS_KEY, json_encode(array_values($providers), JSON_THROW_ON_ERROR));
    }

    private function clearLegacyFlatKeys(): void
    {
        PlatformSetting::query()->whereIn('key', self::LEGACY_KEYS)->delete();
        $this->clearCache();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasFlatMailFields(array $input): bool
    {
        foreach (['mailer', 'scheme', 'host', 'port', 'username', 'password', 'from_address', 'from_name', 'name', 'preset'] as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    private function hasLegacyStoredKeys(): bool
    {
        foreach (self::LEGACY_KEYS as $key) {
            if ($this->stored($key) !== null) {
                return true;
            }
        }

        return false;
    }

    private function settingsTableReady(): bool
    {
        try {
            return Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    private function envMailer(): string
    {
        return $this->normalizeDriver(config('mail.default')) ?? 'log';
    }

    private function persist(string $key, string $value): void
    {
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->clearCache();
    }

    private function plainLegacy(string $key): ?string
    {
        $value = $this->stored($key);

        return filled($value) ? $value : null;
    }

    private function secretLegacy(string $key): ?string
    {
        $stored = $this->stored($key);
        if (! filled($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    private function stored(string $key): ?string
    {
        if (! $this->settingsTableReady()) {
            return null;
        }

        $settings = Cache::remember(self::CACHE_KEY, 300, function () {
            $keys = array_merge(self::LEGACY_KEYS, [self::PROVIDERS_KEY, self::ACTIVE_KEY]);

            return PlatformSetting::query()
                ->whereIn('key', $keys)
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

        return in_array($driver, self::ALLOWED_DRIVERS, true) ? $driver : null;
    }

    private function looksEncrypted(string $value): bool
    {
        // Laravel Crypt payloads are base64 of JSON starting with eyJ ( {" )
        return str_starts_with($value, 'eyJpdiI6') || str_starts_with($value, 'eyJ');
    }

    private function maskSecret(?string $secret): ?string
    {
        if (! filled($secret)) {
            return null;
        }

        $length = strlen($secret);
        if ($length <= 8) {
            return Str::repeat('*', $length);
        }

        return substr($secret, 0, 2).Str::repeat('*', max(4, $length - 4)).substr($secret, -2);
    }
}
