<?php

namespace App\Http\Controllers;

use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    /**
     * Get NGN/USD rate for deducting from user accounts (e.g. Jumia orders in NGN).
     * Returns: ngn_per_usd (1 USD = X NGN), usd_per_ngn (1 NGN = X USD).
     */
    public function getRate(Request $request): JsonResponse
    {
        $ngnPerUsd = ExchangeRateService::ngnPerUsd();
        $usdPerNgn = ExchangeRateService::usdPerNgn();

        if ($ngnPerUsd === null) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate temporarily unavailable',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ngn_per_usd' => $ngnPerUsd,
                'usd_per_ngn' => $usdPerNgn,
                'source' => 'open.er-api.com',
            ],
        ]);
    }

    /**
     * Convert NGN amount to USD (e.g. for deduction).
     */
    public function convertNgnToUsd(Request $request): JsonResponse
    {
        $amountNgn = (float) $request->query('amount', 0);
        if ($amountNgn <= 0) {
            return response()->json(['success' => false, 'message' => 'amount must be positive'], 422);
        }
        $usd = ExchangeRateService::ngnToUsd($amountNgn);
        if ($usd === null) {
            return response()->json([
                'success' => false,
                'message' => 'Exchange rate temporarily unavailable',
            ], 503);
        }
        return response()->json([
            'success' => true,
            'data' => [
                'amount_ngn' => $amountNgn,
                'amount_usd' => $usd,
            ],
        ]);
    }
}
