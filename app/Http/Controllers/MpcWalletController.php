<?php

namespace App\Http\Controllers;

use App\Helpers\Base58;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Passkey / MPC wallet upgrade: challenge ownership, store server share B.
 * Client performs same-address key split; wallet_address never changes.
 */
class MpcWalletController extends Controller
{
    private function challengeCacheKey(string $walletAddress): string
    {
        return 'mpc_upgrade_challenge:'.hash('sha256', $walletAddress);
    }

    public function upgradeChallenge(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));

        $user = DB::table('addressbook')->where('username', $username)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $challenge = 'heysolana-mpc-upgrade:'.Str::uuid()->toString();
        Cache::put($this->challengeCacheKey($walletAddress), $challenge, now()->addMinutes(10));

        return response()->json([
            'challenge' => $challenge,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function upgradeVerify(Request $request): JsonResponse
    {
        return $this->verifyChallengeSignature($request, false);
    }

    public function upgradeComplete(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'share_b_encrypted' => 'required|string',
            'share_a_encrypted' => 'nullable|string',
            'challenge' => 'required|string',
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $verify = $this->verifyChallengeSignature($request, true);
        if ($verify->getStatusCode() !== 200) {
            return $verify;
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));
        $shareB = $request->input('share_b_encrypted');

        $user = DB::table('addressbook')->where('username', $username)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $encryptedShareB = Crypt::encryptString($shareB);
        $shareAEncrypted = $request->filled('share_a_encrypted')
            ? Crypt::encryptString($request->input('share_a_encrypted'))
            : null;

        $existingShare = DB::table('mpc_wallet_shares')
            ->where('wallet_address', $walletAddress)
            ->first();

        $shareRow = [
            'encrypted_share_b' => $encryptedShareB,
            'updated_at' => now(),
        ];
        if ($shareAEncrypted !== null) {
            $shareRow['encrypted_share_a'] = $shareAEncrypted;
        }

        if ($existingShare) {
            DB::table('mpc_wallet_shares')
                ->where('wallet_address', $walletAddress)
                ->update($shareRow);
        } else {
            DB::table('mpc_wallet_shares')->insert(array_merge([
                'addressbook_user_id' => $user->id ?? null,
                'username' => $username,
                'wallet_address' => $walletAddress,
                'threshold' => 2,
                'total_parties' => 2,
                'protocol_version' => 1,
                'created_at' => now(),
            ], $shareRow, [
                'encrypted_share_a' => $shareAEncrypted,
            ]));
        }

        DB::table('addressbook')
            ->where('username', $username)
            ->update([
                'wallet_address' => $walletAddress,
                'wallet_type' => 'mpc',
                'mpc_upgraded_at' => now(),
                'updated_at' => now(),
            ]);

        Cache::forget($this->challengeCacheKey($walletAddress));

        return response()->json([
            'wallet_type' => 'mpc',
            'wallet_address' => $walletAddress,
        ]);
    }

    /**
     * New seedless wallet: client-generated address + encrypted Share B.
     */
    public function createWallet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'share_b_encrypted' => 'required|string',
            'share_a_encrypted' => 'nullable|string',
            'username' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = $request->filled('username') ? trim($request->input('username')) : null;
        $shareB = $request->input('share_b_encrypted');

        if (DB::table('mpc_wallet_shares')->where('wallet_address', $walletAddress)->exists()) {
            return response()->json(['message' => 'Wallet already registered'], 409);
        }

        $userId = null;
        $user = null;
        if ($username) {
            $user = DB::table('addressbook')->where('username', $username)->first();
            $userId = $user->id ?? null;

            if ($user && ($user->wallet_type ?? 'local') === 'mpc' && ! empty($user->wallet_address)) {
                if ($user->wallet_address !== $walletAddress) {
                    return response()->json([
                        'message' => 'This account already has a seedless wallet. Sign in and restore it instead of creating a new one.',
                        'existing_wallet_address' => $user->wallet_address,
                    ], 409);
                }
            }
        }

        $shareAEncrypted = $request->filled('share_a_encrypted')
            ? Crypt::encryptString($request->input('share_a_encrypted'))
            : null;

        DB::table('mpc_wallet_shares')->insert([
            'addressbook_user_id' => $userId,
            'username' => $username ?? '',
            'wallet_address' => $walletAddress,
            'encrypted_share_b' => Crypt::encryptString($shareB),
            'encrypted_share_a' => $shareAEncrypted,
            'threshold' => 2,
            'total_parties' => 2,
            'protocol_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($username && $userId) {
            DB::table('addressbook')
                ->where('username', $username)
                ->update([
                    'wallet_address' => $walletAddress,
                    'wallet_type' => 'mpc',
                    'mpc_upgraded_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'wallet_type' => 'mpc',
            'wallet_address' => $walletAddress,
        ], 201);
    }

    public function registerPasskey(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'credential_id' => 'required|string|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));
        $credentialId = trim($request->input('credential_id'));

        DB::table('passkey_credentials')->updateOrInsert(
            ['wallet_address' => $walletAddress],
            [
                'username' => $username,
                'credential_id' => $credentialId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Open MPC session after phone OTP login (no server passkey yet).
     * User must have verified OTP in this session; ties wallet to addressbook account.
     */
    public function openAccountRecovery(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));

        $user = DB::table('addressbook')->where('username', $username)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (($user->wallet_type ?? 'local') !== 'mpc') {
            return response()->json(['message' => 'This account does not use a seedless wallet'], 400);
        }

        if (trim((string) ($user->wallet_address ?? '')) !== $walletAddress) {
            return response()->json(['message' => 'Wallet does not match this account'], 403);
        }

        $share = DB::table('mpc_wallet_shares')->where('wallet_address', $walletAddress)->first();
        if (! $share) {
            return response()->json(['message' => 'Seedless wallet not found on server'], 404);
        }

        $token = app(PasskeyService::class)->issueMpcSessionToken($walletAddress);

        return response()->json([
            'mpc_session_token' => $token,
            'expires_in' => (int) config('passkey.mpc_session_ttl_seconds', 300),
        ]);
    }

    /**
     * Open MPC session using wallet signature (simulator / fallback when WebAuthn unavailable).
     */
    public function openSession(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'challenge' => 'required|string',
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));
        $challenge = $request->input('challenge');
        $signature = trim($request->input('signature'));

        $cached = Cache::get($this->challengeCacheKey($walletAddress));
        if (! $cached || ! hash_equals($cached, $challenge)) {
            return response()->json(['message' => 'Invalid or expired challenge'], 400);
        }

        $user = DB::table('addressbook')->where('username', $username)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (! $this->verifyEd25519Signature($walletAddress, $challenge, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        Cache::forget($this->challengeCacheKey($walletAddress));

        $token = app(PasskeyService::class)->issueMpcSessionToken($walletAddress);

        return response()->json([
            'mpc_session_token' => $token,
            'expires_in' => (int) config('passkey.mpc_session_ttl_seconds', 300),
        ]);
    }

    /**
     * Release Share B for client-side combine + sign (after passkey MPC session).
     */
    public function releaseShareB(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Mpc-Session-Token');
        $walletAddress = app(\App\Services\PasskeyService::class)->walletAddressForMpcSession($token);

        if (! $walletAddress) {
            return response()->json(['message' => 'Invalid or expired MPC session'], 401);
        }

        $requested = trim((string) $request->input('wallet_address', ''));
        if ($requested !== '' && $requested !== $walletAddress) {
            return response()->json(['message' => 'Wallet mismatch'], 403);
        }

        $row = DB::table('mpc_wallet_shares')->where('wallet_address', $walletAddress)->first();
        if (! $row) {
            return response()->json(['message' => 'MPC wallet not found'], 404);
        }

        try {
            $shareB = Crypt::decryptString($row->encrypted_share_b);
        } catch (\Throwable) {
            return response()->json(['message' => 'Failed to decrypt share'], 500);
        }

        return response()->json([
            'wallet_address' => $walletAddress,
            'share_b' => $shareB,
        ]);
    }

    /**
     * Store client-encrypted Share A (PRF + nacl secretbox). Ciphertext only — server cannot decrypt.
     */
    public function backupShareAPrf(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Mpc-Session-Token');
        $walletAddress = app(PasskeyService::class)->walletAddressForMpcSession($token);

        if (! $walletAddress) {
            return response()->json(['message' => 'Invalid or expired MPC session'], 401);
        }

        $validator = Validator::make($request->all(), [
            'share_a_prf_encrypted' => 'required|string|max:65535',
            'wallet_address' => 'nullable|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $requestedWallet = trim((string) $request->input('wallet_address', ''));
        if ($requestedWallet !== '' && $requestedWallet !== $walletAddress) {
            return response()->json(['message' => 'Wallet mismatch for MPC session'], 403);
        }

        $payload = trim($request->input('share_a_prf_encrypted'));
        if (! str_starts_with($payload, '{"v":1')) {
            return response()->json(['message' => 'Invalid Share A backup format'], 422);
        }

        $row = DB::table('mpc_wallet_shares')->where('wallet_address', $walletAddress)->first();
        if (! $row) {
            return response()->json([
                'message' => 'MPC wallet not found for this address. Complete seedless setup first.',
                'wallet_address' => $walletAddress,
            ], 404);
        }

        DB::table('mpc_wallet_shares')
            ->where('wallet_address', $walletAddress)
            ->update([
                'encrypted_share_a' => $payload,
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Return PRF-encrypted Share A blob for client decryption (requires MPC session).
     */
    public function fetchShareAPrf(Request $request): JsonResponse
    {
        $token = $request->bearerToken() ?? $request->header('X-Mpc-Session-Token');
        $walletAddress = app(PasskeyService::class)->walletAddressForMpcSession($token);

        if (! $walletAddress) {
            return response()->json(['message' => 'Invalid or expired MPC session'], 401);
        }

        $row = DB::table('mpc_wallet_shares')->where('wallet_address', $walletAddress)->first();
        if (! $row || empty($row->encrypted_share_a)) {
            return response()->json(['message' => 'No passkey backup for this wallet'], 404);
        }

        $payload = (string) $row->encrypted_share_a;
        if (! str_starts_with(trim($payload), '{"v":1')) {
            return response()->json(['message' => 'No passkey backup for this wallet'], 404);
        }

        return response()->json([
            'wallet_address' => $walletAddress,
            'share_a_prf_encrypted' => $payload,
        ]);
    }

    public function ackMnemonicRemoved(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));

        $updated = DB::table('addressbook')
            ->where('username', $username)
            ->where('wallet_address', $walletAddress)
            ->where('wallet_type', 'mpc')
            ->update(['updated_at' => now()]);

        if (! $updated) {
            return response()->json(['message' => 'MPC wallet not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function verifyChallengeSignature(Request $request, bool $asJsonOnly): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'challenge' => 'required|string',
            'signature' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));
        $challenge = $request->input('challenge');
        $signature = trim($request->input('signature'));

        $cached = Cache::get($this->challengeCacheKey($walletAddress));
        if (! $cached || ! hash_equals($cached, $challenge)) {
            return response()->json(['message' => 'Invalid or expired challenge'], 400);
        }

        $user = DB::table('addressbook')->where('username', $username)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (! $this->verifyEd25519Signature($walletAddress, $challenge, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        return response()->json(['verified' => true]);
    }

    private function verifyEd25519Signature(string $walletAddress, string $message, string $signatureBase58): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        try {
            $publicKey = Base58::decode($walletAddress);
            $signature = Base58::decode($signatureBase58);
        } catch (\Throwable) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            $signature,
            $message,
            $publicKey
        );
    }
}
