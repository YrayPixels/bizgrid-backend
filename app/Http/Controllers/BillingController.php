<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Models\Merchant;
use App\Services\DodoPaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class BillingController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly DodoPaymentsService $dodoPayments,
    ) {}

    public function subscription(Request $request): JsonResponse
    {
        $merchant = $this->findOwnedMerchant($request);

        return response()->json([
            'subscription' => $this->dodoPayments->formatSubscription($merchant),
            'plans' => $this->dodoPayments->listPlans(),
            'add_ons' => $this->dodoPayments->listAddOns(),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('dodopayments.plans', [])))],
        ]);

        try {
            $merchant = $this->findOwnedMerchant($request);
            $user = $request->user();

            // The free plan has nothing to charge for. Merchants on a paid subscription
            // must cancel through the billing portal; the resulting webhook drops them
            // to free. Anyone without a subscription can switch immediately.
            if ((float) (config("dodopayments.plans.{$data['plan']}.price_monthly_ngn") ?? 0) <= 0) {
                if (filled($merchant->dodo_subscription_id)
                    && in_array($merchant->subscription_status, ['active', 'on_hold'], true)
                ) {
                    return response()->json([
                        'mode' => 'portal_required',
                        'message' => 'Cancel your current subscription in the billing portal to move to the free plan.',
                    ], 409);
                }

                $merchant->subscription_plan = $data['plan'];
                $merchant->subscription_status = 'active';
                $merchant->save();
                $this->dodoPayments->grantMonthlyAllowances($merchant);
                $merchant->refresh();

                return response()->json([
                    'mode' => 'plan_change',
                    'subscription' => $this->dodoPayments->formatSubscription($merchant),
                    'message' => 'You are now on the free plan.',
                ]);
            }

            if (
                filled($merchant->dodo_subscription_id)
                && in_array($merchant->subscription_status, ['active', 'on_hold'], true)
                && $merchant->subscription_plan !== $data['plan']
            ) {
                $this->dodoPayments->changePlan($merchant, $data['plan']);
                $merchant->subscription_plan = $data['plan'];
                $merchant->save();
                $this->dodoPayments->grantMonthlyAllowances($merchant);
                $merchant->refresh();

                return response()->json([
                    'mode' => 'plan_change',
                    'subscription' => $this->dodoPayments->formatSubscription($merchant),
                    'message' => 'Your plan change has been submitted.',
                ]);
            }

            $session = $this->dodoPayments->createCheckoutSession($merchant, $user, $data['plan']);
            $checkoutUrl = $session['checkout_url'] ?? $session['checkoutUrl'] ?? null;

            if (! is_string($checkoutUrl) || $checkoutUrl === '') {
                throw new RuntimeException('Checkout session did not return a checkout URL.');
            }

            return response()->json([
                'mode' => 'checkout',
                'checkout_url' => $checkoutUrl,
                'session_id' => $session['session_id'] ?? $session['sessionId'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function portal(Request $request): JsonResponse
    {
        try {
            $merchant = $this->findOwnedMerchant($request);
            $session = $this->dodoPayments->createCustomerPortalSession($merchant);
            $portalUrl = $session['url'] ?? $session['portal_url'] ?? $session['customer_portal_url'] ?? null;

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
            $session = $this->dodoPayments->createAddOnCheckoutSession(
                $merchant,
                $request->user(),
                $data['type'],
                $data['pack_id'],
            );
            $checkoutUrl = $session['checkout_url'] ?? $session['checkoutUrl'] ?? null;

            if (! is_string($checkoutUrl) || $checkoutUrl === '') {
                throw new RuntimeException('Checkout session did not return a checkout URL.');
            }

            return response()->json([
                'mode' => 'checkout',
                'checkout_url' => $checkoutUrl,
                'session_id' => $session['session_id'] ?? $session['sessionId'] ?? null,
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $this->dodoPayments->handleWebhook(
                $request->getContent(),
                collect($request->headers->all())
                    ->mapWithKeys(fn (array $values, string $key) => [$key => $values[0] ?? null])
                    ->all(),
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
            abort(404, 'Merchant account not found.');
        }

        return $merchant;
    }
}
