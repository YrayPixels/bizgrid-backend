<?php

namespace App\Http\Controllers;

use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

/**
 * Link a new phone without storing device Share A on the server.
 * New device shows QR; primary device approves and sends encrypted Share A in transit only.
 */
class MpcDeviceLinkController extends Controller
{
    public function requestLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'wallet_address' => 'required|string|max:64',
            'new_device_public_key' => 'required|string|max:512',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $username = trim($request->input('username'));
        $walletAddress = trim($request->input('wallet_address'));

        $user = DB::table('addressbook')->where('username', $username)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if (($user->wallet_type ?? 'local') !== 'mpc') {
            return response()->json(['message' => 'This account does not use a seedless wallet'], 400);
        }

        if (trim((string) ($user->wallet_address ?? '')) !== $walletAddress) {
            return response()->json(['message' => 'Wallet does not match account'], 403);
        }

        $linkId = Str::uuid()->toString();
        $linkCode = strtoupper(Str::random(6));

        DB::table('mpc_device_link_sessions')->insert([
            'link_id' => $linkId,
            'link_code' => $linkCode,
            'username' => $username,
            'wallet_address' => $walletAddress,
            'new_device_public_key' => $request->input('new_device_public_key'),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'link_id' => $linkId,
            'link_code' => $linkCode,
            'wallet_address' => $walletAddress,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
            'qr_payload' => json_encode([
                'type' => 'heysolana-device-link',
                'link_id' => $linkId,
                'link_code' => $linkCode,
                'wallet_address' => $walletAddress,
            ]),
        ]);
    }

    public function linkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'link_id' => 'required|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $row = DB::table('mpc_device_link_sessions')
            ->where('link_id', trim($request->input('link_id')))
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Link session not found'], 404);
        }

        if (now()->greaterThan($row->expires_at)) {
            DB::table('mpc_device_link_sessions')->where('id', $row->id)->update(['status' => 'expired']);

            return response()->json(['message' => 'Link session expired', 'status' => 'expired'], 410);
        }

        $payload = [
            'status' => $row->status,
            'wallet_address' => $row->wallet_address,
        ];

        if ($row->status === 'approved' && $row->encrypted_share_a) {
            $payload['encrypted_share_a'] = $row->encrypted_share_a;
            $payload['mpc_session_token'] = $row->mpc_session_token;
            DB::table('mpc_device_link_sessions')->where('id', $row->id)->update(['status' => 'completed']);
        }

        return response()->json($payload);
    }

    public function approveLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'link_id' => 'required|string|max:64',
            'encrypted_share_a' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $linkId = trim($request->input('link_id'));
        $row = DB::table('mpc_device_link_sessions')->where('link_id', $linkId)->first();

        if (! $row) {
            return response()->json(['message' => 'Link session not found'], 404);
        }

        $token = $request->bearerToken() ?? $request->header('X-Mpc-Session-Token');
        $sessionWallet = app(PasskeyService::class)->walletAddressForMpcSession($token);
        if (! $sessionWallet || $sessionWallet !== $row->wallet_address) {
            return response()->json([
                'message' => 'Unlock your wallet on your primary device with Face ID first',
            ], 401);
        }

        if (now()->greaterThan($row->expires_at)) {
            return response()->json(['message' => 'Link session expired'], 410);
        }

        if ($row->status !== 'pending') {
            return response()->json(['message' => 'Link session is no longer pending'], 400);
        }

        $token = app(PasskeyService::class)->issueMpcSessionToken($row->wallet_address);

        DB::table('mpc_device_link_sessions')->where('id', $row->id)->update([
            'encrypted_share_a' => $request->input('encrypted_share_a'),
            'mpc_session_token' => $token,
            'status' => 'approved',
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'New device can finish linking']);
    }

    public function lookupByCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'link_code' => 'required|string|max:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $code = strtoupper(trim($request->input('link_code')));
        $row = DB::table('mpc_device_link_sessions')
            ->where('link_code', $code)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Invalid or expired link code'], 404);
        }

        return response()->json([
            'link_id' => $row->link_id,
            'wallet_address' => $row->wallet_address,
            'username' => $row->username,
            'new_device_public_key' => $row->new_device_public_key,
        ]);
    }
}
