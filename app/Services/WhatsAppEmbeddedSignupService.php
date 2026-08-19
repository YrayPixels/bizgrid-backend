<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreSocialConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppEmbeddedSignupService
{
    public function __construct(
        private readonly PlatformWhatsAppConfigService $platform,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * @return array{configured: bool, app_id: string|null, config_id: string|null, graph_version: string}
     */
    public function clientConfig(): array
    {
        return [
            'configured' => $this->platform->embeddedSignupConfigured(),
            'app_id' => $this->platform->facebookAppId(),
            'config_id' => $this->platform->embeddedSignupConfigId(),
            'graph_version' => $this->platform->graphVersion(),
        ];
    }

    /**
     * Exchange the Embedded Signup code, persist the store number, subscribe webhooks,
     * and request coexistence history sync. Do not register the phone — it is already
     * on the WhatsApp Business app.
     *
     * @param  array{code: string, waba_id?: string|null, phone_number_id?: string|null}  $input
     */
    public function complete(Store $store, array $input): StoreSocialConnection
    {
        if (! $this->platform->embeddedSignupConfigured()) {
            throw new RuntimeException('WhatsApp Embedded Signup is not configured on this platform.');
        }

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('Embedded Signup authorization code is missing.');
        }

        $token = $this->exchangeCode($code);
        $wabaId = trim((string) ($input['waba_id'] ?? ''));
        if ($wabaId === '') {
            $wabaId = $this->discoverWabaId($token);
        }
        if ($wabaId === '') {
            throw new RuntimeException('Could not determine the WhatsApp Business Account from Embedded Signup.');
        }

        $phone = $this->resolvePhoneNumber($wabaId, $token, trim((string) ($input['phone_number_id'] ?? '')));

        $this->whatsapp->disconnect($store->id);

        $connection = $this->whatsapp->connectStoreChannel($store->id, [
            'phone_number_id' => $phone['id'],
            'display_phone_number' => $phone['display_phone_number'],
            'access_token' => $token,
            'waba_id' => $wabaId,
            'coexistence' => true,
            'onboarding' => 'embedded_signup',
            'is_on_biz_app' => (bool) ($phone['is_on_biz_app'] ?? true),
        ]);

        $this->requestCoexistenceSync((string) $phone['id'], $token);

        return $connection;
    }

    private function exchangeCode(string $code): string
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->get($this->graphUrl('/oauth/access_token'), [
                'client_id' => $this->platform->facebookAppId(),
                'client_secret' => $this->platform->appSecret(),
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->whatsappError($response->json(), 'Failed to exchange WhatsApp Embedded Signup code.'));
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Embedded Signup did not return an access token.');
        }

        return $token;
    }

    private function discoverWabaId(string $token): string
    {
        $appId = (string) $this->platform->facebookAppId();
        $appSecret = (string) ($this->platform->appSecret() ?? '');

        $response = Http::acceptJson()
            ->timeout(20)
            ->withToken($appId.'|'.$appSecret)
            ->get($this->graphUrl('/debug_token'), [
                'input_token' => $token,
            ]);

        if (! $response->successful()) {
            return '';
        }

        $scopes = $response->json('data.granular_scopes');
        if (! is_array($scopes)) {
            return '';
        }

        foreach ($scopes as $scope) {
            if (! is_array($scope)) {
                continue;
            }
            $name = (string) ($scope['scope'] ?? '');
            if (! in_array($name, ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                continue;
            }
            $ids = $scope['target_ids'] ?? [];
            if (is_array($ids) && isset($ids[0]) && is_string($ids[0]) && $ids[0] !== '') {
                return $ids[0];
            }
        }

        return '';
    }

    /**
     * @return array{id: string, display_phone_number: string, is_on_biz_app?: bool}
     */
    private function resolvePhoneNumber(string $wabaId, string $token, string $preferredPhoneNumberId): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(20)
            ->get($this->graphUrl('/'.$wabaId.'/phone_numbers'), [
                'fields' => 'id,display_phone_number,verified_name,is_on_biz_app',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->whatsappError($response->json(), 'Failed to load WhatsApp phone numbers.'));
        }

        $numbers = $response->json('data');
        $numbers = is_array($numbers) ? $numbers : [];
        $selected = null;

        foreach ($numbers as $number) {
            if (! is_array($number)) {
                continue;
            }
            $id = (string) ($number['id'] ?? '');
            if ($preferredPhoneNumberId !== '' && $id === $preferredPhoneNumberId) {
                $selected = $number;
                break;
            }
        }

        if ($selected === null) {
            foreach ($numbers as $number) {
                if (is_array($number) && ($number['is_on_biz_app'] ?? false)) {
                    $selected = $number;
                    break;
                }
            }
        }

        if ($selected === null) {
            $first = $numbers[0] ?? null;
            $selected = is_array($first) ? $first : null;
        }

        $id = (string) ($selected['id'] ?? '');
        $display = (string) ($selected['display_phone_number'] ?? '');
        if ($id === '' || $display === '') {
            throw new RuntimeException('Embedded Signup did not return a WhatsApp phone number.');
        }

        return [
            'id' => $id,
            'display_phone_number' => $display,
            'is_on_biz_app' => (bool) ($selected['is_on_biz_app'] ?? false),
        ];
    }

    private function requestCoexistenceSync(string $phoneNumberId, string $token): void
    {
        foreach (['smb_app_state_sync', 'history'] as $syncType) {
            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(30)
                    ->post($this->graphUrl('/'.$phoneNumberId.'/smb_app_data'), [
                        'messaging_product' => 'whatsapp',
                        'sync_type' => $syncType,
                    ]);

                if (! $response->successful()) {
                    Log::info('WhatsApp coexistence sync request failed.', [
                        'phone_number_id' => $phoneNumberId,
                        'sync_type' => $syncType,
                        'error' => $this->whatsappError($response->json(), 'sync failed'),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::info('WhatsApp coexistence sync request failed.', [
                    'phone_number_id' => $phoneNumberId,
                    'sync_type' => $syncType,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->platform->graphVersion().'/'.ltrim($path, '/');
    }

    private function whatsappError(mixed $payload, string $fallback): string
    {
        if (! is_array($payload)) {
            return $fallback;
        }

        $message = $payload['error']['message'] ?? $payload['error']['error_user_msg'] ?? null;

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
