<?php

namespace App\Http\Controllers;

use App\Models\PajCashBeneficiary;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PajCashBeneficiaryController extends Controller
{
    private function resolveUserByWallet(string $walletAddress): User
    {
        $walletNormalized = trim($walletAddress);
        if (empty($walletNormalized)) {
            abort(422, 'Wallet address is required');
        }

        $email = 'pajcash_wallet_' . md5($walletNormalized) . '@heysolana.local';
        $user = User::where('email', $email)->first();
        if ($user) {
            return $user;
        }

        return User::create([
            'name' => 'PajCash User',
            'email' => $email,
            'password' => bcrypt(Str::random(32)),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $walletAddress = $request->input('wallet_address') ?? $request->query('wallet_address');
        if (!$walletAddress) {
            return response()->json(['success' => false, 'message' => 'wallet_address is required'], 422);
        }

        $user = $this->resolveUserByWallet($walletAddress);
        $beneficiaries = PajCashBeneficiary::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $beneficiaries]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'wallet_address' => 'required|string',
            'bank_id' => 'required|string|max:255',
            'bank_name' => 'required|string|max:255',
            'bank_code' => 'nullable|string|max:255',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $user = $this->resolveUserByWallet($data['wallet_address']);

        $beneficiary = PajCashBeneficiary::updateOrCreate(
            [
                'user_id' => $user->id,
                'bank_id' => $data['bank_id'],
                'account_number' => $data['account_number'],
            ],
            [
                'bank_name' => $data['bank_name'],
                'bank_code' => $data['bank_code'] ?? null,
                'account_name' => $data['account_name'],
            ]
        );

        return response()->json(['success' => true, 'data' => $beneficiary->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $walletAddress = $request->input('wallet_address') ?? $request->query('wallet_address');
        if (!$walletAddress) {
            return response()->json(['success' => false, 'message' => 'wallet_address is required'], 422);
        }

        $user = $this->resolveUserByWallet($walletAddress);
        $beneficiary = PajCashBeneficiary::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$beneficiary) {
            return response()->json(['success' => false, 'message' => 'Beneficiary not found'], 404);
        }

        $beneficiary->delete();

        return response()->json(['success' => true, 'message' => 'Beneficiary deleted']);
    }
}
