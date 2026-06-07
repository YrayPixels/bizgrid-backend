<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

class PasskeyService
{
    private WebAuthn $webauthn;

    public function __construct()
    {
        $rpName = config('passkey.rp_name', 'Hey Solana');
        $rpId = config('passkey.rp_id', 'localhost');
        $this->webauthn = new WebAuthn($rpName, $rpId, null, true);

        // Android native passkeys use origin android:apk-key-hash:<base64url>; lbuchs requires this list.
        $this->webauthn->addAndroidKeyHashes(self::androidApkKeyHashesFromConfig());
    }

    /**
     * Convert PASSKEY_ANDROID_SHA256_CERT (colon hex) to android:apk-key-hash suffixes.
     *
     * @return array<int, string>
     */
    private static function androidApkKeyHashesFromConfig(): array
    {
        $raw = (string) config('passkey.android_sha256_cert', '');
        $fingerprints = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $hashes = [];

        foreach ($fingerprints as $fingerprint) {
            $hex = str_replace(':', '', $fingerprint);
            if (! preg_match('/^[0-9A-Fa-f]{64}$/', $hex)) {
                continue;
            }
            $binary = hex2bin($hex);
            if ($binary === false) {
                continue;
            }
            $hashes[] = rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
        }

        return array_values(array_unique($hashes));
    }

    public function rpId(): string
    {
        return config('passkey.rp_id', 'localhost');
    }

    private function userIdBinary(string $walletAddress): string
    {
        return hash('sha256', $walletAddress, true);
    }

    private function regChallengeKey(string $walletAddress): string
    {
        return 'passkey_reg_challenge:'.hash('sha256', $walletAddress);
    }

    private function authChallengeKey(string $walletAddress): string
    {
        return 'passkey_auth_challenge:'.hash('sha256', $walletAddress);
    }

    public function getRegistrationOptions(string $walletAddress, string $username): array
    {
        $args = $this->webauthn->getCreateArgs(
            $this->userIdBinary($walletAddress),
            $username,
            'Hey Solana Wallet',
            120,
            true,
            'required',
            null
        );

        Cache::put(
            $this->regChallengeKey($walletAddress),
            base64_encode($this->webauthn->getChallenge()->getBinaryString()),
            now()->addMinutes(10)
        );

        $options = json_decode(json_encode($args), true);
        if (isset($options['publicKey']) && is_array($options['publicKey'])) {
            $options['publicKey']['attestation'] = 'none';
        } else {
            $options['attestation'] = 'none';
        }

        return $options;
    }

    /**
     * @return object{credentialId: string, credentialPublicKey: string, signCount: int}
     *
     * @throws WebAuthnException
     */
    public function verifyRegistration(
        string $walletAddress,
        string $clientDataJSON,
        string $attestationObject
    ): object {
        $challengeB64 = Cache::pull($this->regChallengeKey($walletAddress));
        if (! $challengeB64) {
            throw new WebAuthnException('Registration challenge expired');
        }

        $challenge = new ByteBuffer(base64_decode($challengeB64, true) ?: '');

        $clientData = $this->decodeClientPayload($clientDataJSON);
        $attestation = $this->decodeClientPayload($attestationObject);

        $data = $this->webauthn->processCreate(
            $clientData,
            $attestation,
            $challenge,
            false,
            true,
            false,
            false
        );

        return $data;
    }

    /**
     * @param  array<int, string>  $credentialIdsBase64
     */
    public function getAuthenticationOptions(string $walletAddress, array $credentialIdsBase64Url): array
    {
        $ids = [];
        foreach ($credentialIdsBase64Url as $id) {
            $ids[] = ByteBuffer::fromBase64Url($id)->getBinaryString();
        }

        $args = $this->webauthn->getGetArgs($ids, 120, true, true, true, true, true, 'preferred');

        Cache::put(
            $this->authChallengeKey($walletAddress),
            base64_encode($this->webauthn->getChallenge()->getBinaryString()),
            now()->addMinutes(10)
        );

        return json_decode(json_encode($args), true);
    }

    public function verifyAuthentication(
        string $walletAddress,
        string $credentialId,
        string $credentialPublicKey,
        int $signCount,
        string $clientDataJSON,
        string $authenticatorData,
        string $signature
    ): int {
        $challengeB64 = Cache::pull($this->authChallengeKey($walletAddress));
        if (! $challengeB64) {
            throw new WebAuthnException('Authentication challenge expired');
        }

        $challenge = new ByteBuffer(base64_decode($challengeB64, true) ?: '');

        $clientData = $this->decodeClientPayload($clientDataJSON);
        $authData = $this->decodeClientPayload($authenticatorData);
        $sig = $this->decodeClientPayload($signature);
        $credPk = base64_decode($credentialPublicKey, true) ?: '';

        return $this->webauthn->processGet(
            $clientData,
            $authData,
            $sig,
            $credPk,
            $challenge,
            $signCount,
            false,
            false
        );
    }

    public function issueMpcSessionToken(string $walletAddress): string
    {
        $token = Str::random(64);
        $ttl = (int) config('passkey.mpc_session_ttl_seconds', 300);
        Cache::put('mpc_session:'.$token, [
            'wallet_address' => $walletAddress,
        ], now()->addSeconds($ttl));

        return $token;
    }

    public function walletAddressForMpcSession(?string $token): ?string
    {
        if (! $token) {
            return null;
        }
        $data = Cache::get('mpc_session:'.$token);

        return is_array($data) ? ($data['wallet_address'] ?? null) : null;
    }

    private function decodeClientPayload(string $payload): string
    {
        $payload = trim($payload);
        if ($payload === '') {
            return '';
        }

        $b64 = strtr($payload, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($b64, true) ?: '';
    }
}
