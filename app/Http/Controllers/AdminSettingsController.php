<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminSettingsController extends Controller
{
    public const TREASURY_KEY = 'treasury_wallet_address';

    /**
     * Get processing fee, treasury and other settings (admin).
     */
    public function getSettings(Request $request): JsonResponse
    {
        $keys = [
            'processing_fee_percent',
            'processing_fee_fixed_ngn',
            'processing_fee_fixed_usd',
            self::TREASURY_KEY,
            'delivery_fee_jumia_ngn',
            'delivery_fee_crossmint_usd',
            'jupiter_referral_account',
            'jupiter_referral_fee_bps',
        ];
        $defaults = [
            'processing_fee_percent' => '0',
            'processing_fee_fixed_ngn' => '0',
            'processing_fee_fixed_usd' => '0',
            self::TREASURY_KEY => '',
            'delivery_fee_jumia_ngn' => '0',
            'delivery_fee_crossmint_usd' => '0',
            'jupiter_referral_account' => '',
            'jupiter_referral_fee_bps' => '50',
        ];
        $data = [];
        foreach ($keys as $key) {
            $data[$key] = Setting::getValue($key, $defaults[$key] ?? '');
        }
        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Public endpoint: get treasury wallet address and delivery fees (no auth).
     * Used by the wallet app for fees, payments (Jumia USDC), and displaying total with delivery fee.
     */
    public function getPublicSettings(Request $request): JsonResponse
    {
        $treasury = Setting::getValue(self::TREASURY_KEY, '');
        $deliveryFeeJumiaNgn = (float) Setting::getValue('delivery_fee_jumia_ngn', '0');
        $deliveryFeeCrossmintUsd = (float) Setting::getValue('delivery_fee_crossmint_usd', '0');
        $jupiterReferralAccount = trim(Setting::getValue('jupiter_referral_account', ''));
        $jupiterReferralFeeBps = (int) Setting::getValue('jupiter_referral_fee_bps', '50');
        return response()->json([
            'success' => true,
            'data' => [
                'treasury_wallet_address' => $treasury,
                'delivery_fee_jumia_ngn' => $deliveryFeeJumiaNgn,
                'delivery_fee_crossmint_usd' => $deliveryFeeCrossmintUsd,
                'jupiter_referral_account' => $jupiterReferralAccount,
                'jupiter_referral_fee_bps' => $jupiterReferralFeeBps,
            ],
        ]);
    }

    /**
     * Update processing fee and treasury (admin). Use when deducting from user accounts.
     */
    public function updateProcessingFee(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'processing_fee_percent' => 'nullable|numeric|min:0|max:100',
            'processing_fee_fixed_ngn' => 'nullable|numeric|min:0',
            'processing_fee_fixed_usd' => 'nullable|numeric|min:0',
            self::TREASURY_KEY => 'nullable|string|max:64',
            'delivery_fee_jumia_ngn' => 'nullable|numeric|min:0',
            'delivery_fee_crossmint_usd' => 'nullable|numeric|min:0',
            'jupiter_referral_account' => 'nullable|string|max:64',
            'jupiter_referral_fee_bps' => 'nullable|integer|min:0|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        foreach ($data as $key => $value) {
            if ($value !== null) {
                Setting::setValue($key, (string) $value);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Settings updated',
            'data' => [
                'processing_fee_percent' => Setting::getValue('processing_fee_percent', '0'),
                'processing_fee_fixed_ngn' => Setting::getValue('processing_fee_fixed_ngn', '0'),
                'processing_fee_fixed_usd' => Setting::getValue('processing_fee_fixed_usd', '0'),
                self::TREASURY_KEY => Setting::getValue(self::TREASURY_KEY, ''),
                'delivery_fee_jumia_ngn' => Setting::getValue('delivery_fee_jumia_ngn', '0'),
                'delivery_fee_crossmint_usd' => Setting::getValue('delivery_fee_crossmint_usd', '0'),
                'jupiter_referral_account' => Setting::getValue('jupiter_referral_account', ''),
                'jupiter_referral_fee_bps' => Setting::getValue('jupiter_referral_fee_bps', '50'),
            ],
        ]);
    }
}
