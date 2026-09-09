<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Merchant;
use App\Services\PaystackBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class BillingController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly PaystackBillingService $billing,
    ) {}

    public function subscription(Request $request): JsonResponse
    {
        $merchant = $this->findOwnedMerchant($request);

        return response()->json([
            'subscription' => $this->billing->formatSubscription($merchant),
            'plans' => $this->billing->listPlans(),
            'add_ons' => $this->billing->listAddOns(),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('billing.plans', [])))],
        ]);

        try {
            $merchant = $this->findOwnedMerchant($request);
            $user = $request->user();

            // Plan changes go through hosted checkout (Paystack has no prorated change-plan).
            $session = filled($merchant->paystack_subscription_code)
                && in_array($merchant->subscription_status, ['active', 'on_hold'], true)
                && $merchant->subscription_plan !== $data['plan']
                ? $this->billing->changePlan($merchant, $user, $data['plan'])
                : $this->billing->createCheckoutSession($merchant, $user, $data['plan']);

            $checkoutUrl = $session['checkout_url'] ?? null;

            if (! is_string($checkoutUrl) || $checkoutUrl === '') {
                throw new RuntimeException('Checkout session did not return a checkout URL.');
            }

            return response()->json([
                'mode' => 'checkout',
                'checkout_url' => $checkoutUrl,
                'session_id' => $session['session_id'] ?? $session['reference'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function portal(Request $request): JsonResponse
    {
        try {
            $merchant = $this->findOwnedMerchant($request);
            $session = $this->billing->createCustomerPortalSession($merchant);
            $portalUrl = $session['url'] ?? $session['portal_url'] ?? null;

            if (! is_string($portalUrl) || $portalUrl === '') {
                throw new RuntimeException('Customer portal session did not return a URL.');
            }

            return response()->json([
                'portal_url' => $portalUrl,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function topup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['sms', 'whatsapp', 'ai_credits'])],
            'pack_id' => ['required', 'string', 'max:80'],
        ]);

        try {
            $merchant = $this->findOwnedMerchant($request);
            $session = $this->billing->createAddOnCheckoutSession(
                $merchant,
                $request->user(),
                $data['type'],
                $data['pack_id'],
            );
            $checkoutUrl = $session['checkout_url'] ?? null;

            if (! is_string($checkoutUrl) || $checkoutUrl === '') {
                throw new RuntimeException('Checkout session did not return a checkout URL.');
            }

            return response()->json([
                'mode' => 'checkout',
                'checkout_url' => $checkoutUrl,
                'session_id' => $session['session_id'] ?? $session['reference'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $this->billing->handleWebhook(
                $request->getContent(),
                $request->header('x-paystack-signature'),
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json(['received' => true]);
    }

    private function findOwnedMerchant(Request $request): Merchant
    {
        $merchant = Merchant::query()
            ->where('owner_user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if (! $merchant) {
            abort(404, 'Merchant not found.');
        }

        return $merchant;
    }
}
