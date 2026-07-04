<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorePaymentController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly PaystackService $paystack,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        return response()->json([
            'payments' => $this->formatPaymentSettings($store),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);

        $data = $request->validate([
            'payout_account_name' => 'nullable|string|max:160',
            'payout_bank_name' => 'nullable|string|max:120',
            'payout_account_number' => 'nullable|string|max:40',
        ]);

        foreach (['payout_account_name', 'payout_bank_name', 'payout_account_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : null;
                $store->{$field} = filled($value) ? $value : null;
            }
        }

        $store->save();

        return response()->json([
            'payments' => $this->formatPaymentSettings($store->fresh()),
            'message' => 'Payout details saved.',
        ]);
    }

    private function formatPaymentSettings($store): array
    {
        return [
            'checkout_enabled' => $this->paystack->isConfigured(),
            'payouts_configured' => filled($store->payout_account_name)
                && filled($store->payout_bank_name)
                && filled($store->payout_account_number),
            'payout_account_name' => $store->payout_account_name,
            'payout_bank_name' => $store->payout_bank_name,
            'payout_account_number' => $store->payout_account_number,
        ];
    }
}
