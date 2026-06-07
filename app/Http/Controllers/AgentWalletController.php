<?php

namespace App\Http\Controllers;

use App\Helpers\Base58;
use App\Models\AgentWallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Agent wallets: create and store keypairs in backend; sign messages when agent-node requests.
 * Protected by X-Agent-Wallet-Secret (AGENT_WALLET_SECRET). Only agent-node should call these.
 */
class AgentWalletController extends Controller
{
    private function checkSecret(Request $request): ?JsonResponse
    {
        $secret = config('services.agent_wallet.secret');
        if (empty($secret)) {
            Log::warning('Agent wallet endpoints enabled but AGENT_WALLET_SECRET not set');
            return response()->json(['success' => false, 'message' => 'Agent wallet signing not configured'], 503);
        }
        $header = $request->header('X-Agent-Wallet-Secret');
        if ($header !== $secret) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        return null;
    }

    /**
     * Create or get agent wallet. Generates Ed25519 keypair (sodium), stores encrypted secret, returns public_key (base58).
     */
    public function create(Request $request): JsonResponse
    {
        if ($err = $this->checkSecret($request)) {
            return $err;
        }
        $agentId = $request->input('agent_id');
        if (empty($agentId) || ! is_string($agentId)) {
            return response()->json(['success' => false, 'message' => 'agent_id required'], 400);
        }
        $agentId = trim($agentId);
        if ($agentId === '') {
            return response()->json(['success' => false, 'message' => 'agent_id required'], 400);
        }

        $existing = AgentWallet::where('agent_id', $agentId)->first();
        if ($existing) {
            return response()->json([
                'success' => true,
                'agent_id' => $existing->agent_id,
                'public_key' => $existing->public_key,
            ], 200);
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicKeyBinary = sodium_crypto_sign_publickey($keypair);
        $secretKeyBinary = sodium_crypto_sign_secretkey($keypair);
        $publicKeyBase58 = Base58::encode($publicKeyBinary);

        $encrypted = Crypt::encryptString($secretKeyBinary);

        AgentWallet::create([
            'agent_id' => $agentId,
            'public_key' => $publicKeyBase58,
            'encrypted_secret' => $encrypted,
        ]);

        return response()->json([
            'success' => true,
            'agent_id' => $agentId,
            'public_key' => $publicKeyBase58,
        ], 201);
    }

    /**
     * List all agent wallets (agent_id, public_key).
     */
    public function list(Request $request): JsonResponse
    {
        if ($err = $this->checkSecret($request)) {
            return $err;
        }
        $wallets = AgentWallet::select('agent_id', 'public_key')->orderBy('agent_id')->get();
        return response()->json([
            'success' => true,
            'wallets' => $wallets->map(fn ($w) => ['agent_id' => $w->agent_id, 'public_key' => $w->public_key]),
        ]);
    }

    /**
     * Sign a message (serialized Solana tx message). Returns signature base64.
     */
    public function sign(Request $request): JsonResponse
    {
        if ($err = $this->checkSecret($request)) {
            return $err;
        }
        $agentId = $request->input('agent_id');
        $messageB64 = $request->input('message');
        if (empty($agentId) || empty($messageB64)) {
            return response()->json(['success' => false, 'message' => 'agent_id and message (base64) required'], 400);
        }

        $wallet = AgentWallet::where('agent_id', $agentId)->first();
        if (! $wallet) {
            return response()->json(['success' => false, 'message' => 'Agent wallet not found'], 404);
        }

        $message = base64_decode($messageB64, true);
        if ($message === false || strlen($message) === 0) {
            return response()->json(['success' => false, 'message' => 'Invalid message base64'], 400);
        }

        try {
            $secretKey = $wallet->getDecryptedSecret();
            $signature = sodium_crypto_sign_detached($message, $secretKey);
            return response()->json([
                'success' => true,
                'signature' => base64_encode($signature),
            ]);
        } catch (\Throwable $e) {
            Log::error('Agent wallet sign failed', ['agent_id' => $agentId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Sign failed'], 500);
        }
    }
}
