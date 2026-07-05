<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PaystackService
{
    public function __construct(
        private readonly PlatformNotificationService $notifications,
        private readonly StoreNotificationService $storeNotifications,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('paystack.public_key')) && filled(config('paystack.secret_key'));
    }

    public function publicKey(): ?string
    {
        $key = config('paystack.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function initializeOrderPayment(Store $store, StoreOrder $order, string $callbackUrl): array
    {
        $this->assertConfigured();

        $reference = $order->paystack_reference ?: $this->uniqueReference($order);
        if (! $order->paystack_reference) {
            $order->paystack_reference = $reference;
            $order->save();
        }

        $amount = $this->amountInMinorUnits((float) $order->total_amount, (string) $order->currency);

        $response = $this->request('post', '/transaction/initialize', [
            'email' => $order->customer_email,
            'amount' => $amount,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'store_id' => $store->id,
                'merchant_id' => $store->merchant_id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
        ]);

        $data = $response['data'] ?? [];

        return [
            'provider' => 'paystack',
            'public_key' => $this->publicKey(),
            'reference' => (string) ($data['reference'] ?? $reference),
            'access_code' => $data['access_code'] ?? null,
            'authorization_url' => $data['authorization_url'] ?? null,
            'amount' => $amount,
            'currency' => strtoupper((string) $order->currency),
        ];
    }

    public function verifyAndMarkPaid(Store $store, string $reference): StoreOrder
    {
        $this->assertConfigured();

        $order = StoreOrder::query()
            ->where('store_id', $store->id)
            ->where('paystack_reference', $reference)
            ->first();

        if (! $order) {
            throw new RuntimeException('Order not found for this payment reference.');
        }

        if ($order->payment_status === 'paid') {
            return $order;
        }

        $response = $this->request('get', '/transaction/verify/'.urlencode($reference));
        $data = $response['data'] ?? [];

        if (($data['status'] ?? null) !== 'success') {
            throw new RuntimeException('Payment has not been completed yet.');
        }

        $this->markOrderPaid($order, $data);

        return $order->fresh();
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $this->assertConfigured();

        if (! filled($signature)) {
            throw new RuntimeException('Missing Paystack signature.');
        }

        $secret = (string) config('paystack.secret_key');
        $expected = hash_hmac('sha512', $payload, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid Paystack signature.');
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            throw new RuntimeException('Invalid webhook payload.');
        }

        if (($event['event'] ?? null) !== 'charge.success') {
            return;
        }

        $data = $event['data'] ?? [];
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '') {
            return;
        }

        $order = StoreOrder::query()->where('paystack_reference', $reference)->first();

        if (! $order || $order->payment_status === 'paid') {
            return;
        }

        if (($data['status'] ?? null) !== 'success') {
            return;
        }

        $this->markOrderPaid($order, $data);
    }

    private function markOrderPaid(StoreOrder $order, array $paystackData): void
    {
        if ($order->payment_status === 'paid') {
            return;
        }

        DB::transaction(function () use ($order): void {
            $order->refresh();
            if ($order->payment_status === 'paid') {
                return;
            }

            $order->payment_status = 'paid';
            $order->status = $order->status === 'pending' ? 'processing' : $order->status;
            $order->paid_at = now();
            $order->settlement_status = 'pending_settlement';
            $order->save();

            $store = Store::query()->lockForUpdate()->find($order->store_id);
            if ($store) {
                $store->increment('orders_count');
                $store->increment('gross_revenue', (float) $order->total_amount);

                $store->loadMissing('merchant');
                if ($store->merchant) {
                    app(MerchantUsageEnforcementService::class)
                        ->recordOrderProcessing($store->merchant, (float) $order->total_amount);
                }
            }
        });

        $order->loadMissing('store');
        $this->notifications->notify(
            'order.paid',
            'Payment received: '.$order->order_number,
            $order->store?->name ?? 'Store order',
            [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'reference' => $paystackData['reference'] ?? $order->paystack_reference,
                'amount' => (float) $order->total_amount,
                'settlement_status' => 'pending_settlement',
            ],
        );

        if ($order->store) {
            $this->storeNotifications->orderPaid($order->store, $order);
        }
    }

    private function uniqueReference(StoreOrder $order): string
    {
        do {
            $reference = 'SH-'.$order->order_number.'-'.Str::lower(Str::random(6));
        } while (StoreOrder::query()->where('paystack_reference', $reference)->exists());

        return $reference;
    }

    private function amountInMinorUnits(float $amount, string $currency): int
    {
        $currency = strtoupper($currency);

        return match ($currency) {
            'NGN', 'GHS', 'ZAR', 'KES', 'USD' => (int) round($amount * 100),
            default => (int) round($amount * 100),
        };
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Paystack is not configured on the platform.');
        }
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $secret = config('paystack.secret_key');
        if (! filled($secret)) {
            throw new RuntimeException('Paystack secret key is missing.');
        }

        $baseUrl = rtrim((string) config('paystack.base_url'), '/');

        try {
            $client = Http::withToken((string) $secret)->acceptJson()->timeout(20);
            $response = $method === 'get'
                ? $client->get($baseUrl.$path)
                : $client->asJson()->{$method}($baseUrl.$path, $payload);

            $response->throw();
        } catch (RequestException $exception) {
            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error')
                ?? 'Paystack request failed.';

            throw new RuntimeException((string) $message, previous: $exception);
        }

        return $response->json() ?? [];
    }
}
