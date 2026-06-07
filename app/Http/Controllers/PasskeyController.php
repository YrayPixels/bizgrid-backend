<?php

namespace App\Http\Controllers;

use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use lbuchs\WebAuthn\WebAuthnException;

class PasskeyController extends Controller
{
    public function __construct(private readonly PasskeyService $passkeys) {}

    public function registerOptions(Request $request): JsonResponse
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

        try {
            $options = $this->passkeys->getRegistrationOptions($walletAddress, $username);

            return response()->json([
                'options' => $options,
                'rp_id' => $this->passkeys->rpId(),
            ]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function registerVerify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'username' => 'required|string|max:255',
            'id' => 'required|string',
            'raw_id' => 'required|string',
            'client_data_json' => 'required|string',
            'attestation_object' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $username = trim($request->input('username'));

        try {
            $data = $this->passkeys->verifyRegistration(
                $walletAddress,
                $request->input('client_data_json'),
                $request->input('attestation_object')
            );

            $credentialPublicKey = base64_encode($data->credentialPublicKey);

            DB::table('passkey_credentials')->updateOrInsert(
                ['wallet_address' => $walletAddress],
                [
                    'username' => $username,
                    'credential_id' => $request->input('id'),
                    'credential_public_key' => $credentialPublicKey,
                    'sign_count' => $data->signatureCounter ?? 0,
                    'user_handle_hex' => bin2hex($this->userIdBinary($walletAddress)),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return response()->json([
                'ok' => true,
                'credential_id' => $request->input('id'),
            ]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function authOptions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $row = DB::table('passkey_credentials')->where('wallet_address', $walletAddress)->first();
        if (! $row || empty($row->credential_public_key)) {
            return response()->json(['message' => 'Passkey not registered'], 404);
        }

        try {
            $options = $this->passkeys->getAuthenticationOptions(
                $walletAddress,
                [(string) $row->credential_id]
            );

            return response()->json([
                'options' => $options,
                'rp_id' => $this->passkeys->rpId(),
            ]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function authVerify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string|max:64',
            'id' => 'required|string',
            'client_data_json' => 'required|string',
            'authenticator_data' => 'required|string',
            'signature' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $walletAddress = trim($request->input('wallet_address'));
        $row = DB::table('passkey_credentials')->where('wallet_address', $walletAddress)->first();
        if (! $row) {
            return response()->json(['message' => 'Passkey not found'], 404);
        }

        try {
            $newSignCount = $this->passkeys->verifyAuthentication(
                $walletAddress,
                (string) $row->credential_id,
                (string) $row->credential_public_key,
                (int) ($row->sign_count ?? 0),
                $request->input('client_data_json'),
                $request->input('authenticator_data'),
                $request->input('signature')
            );

            DB::table('passkey_credentials')
                ->where('wallet_address', $walletAddress)
                ->update([
                    'sign_count' => $newSignCount,
                    'updated_at' => now(),
                ]);

            $mpcSessionToken = $this->passkeys->issueMpcSessionToken($walletAddress);

            return response()->json([
                'ok' => true,
                'mpc_session_token' => $mpcSessionToken,
                'expires_in' => (int) config('passkey.mpc_session_ttl_seconds', 300),
            ]);
        } catch (WebAuthnException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
    }

    private function userIdBinary(string $walletAddress): string
    {
        return hash('sha256', $walletAddress, true);
    }
}
