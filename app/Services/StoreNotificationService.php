<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CustomerOrderCancelledEmail;
use App\Mail\CustomerOrderConfirmationEmail;
use App\Mail\CustomerOrderRefundedEmail;
use App\Mail\CustomerOrderShippedEmail;
use App\Mail\CustomerPaymentConfirmationEmail;
use App\Mail\MerchantBillingEmail;
use App\Mail\MerchantLowStockEmail;
use App\Mail\MerchantNewOrderEmail;
use App\Mail\MerchantOrderPaidEmail;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class StoreNotificationService
{
    public function orderPlaced(Store $store, StoreOrder $order, bool $awaitingPayment): void
    {
        $store->loadMissing('merchant.owner');

        if ($store->notify_customer_order_confirmation && filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerOrderConfirmationEmail(
                $store,
                $order,
                $awaitingPayment,
            ));
        }

        if ($store->notify_merchant_new_order) {
            $recipient = $this->merchantNotificationEmail($store);
            if ($recipient) {
                Mail::to($recipient)->send(new MerchantNewOrderEmail($store, $order, $awaitingPayment));
            }
        }
    }

    public function orderPaid(Store $store, StoreOrder $order): void
    {
        $store->loadMissing('merchant.owner');

        if ($store->notify_customer_payment_confirmation && filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerPaymentConfirmationEmail($store, $order));
        }

        if ($store->notify_merchant_new_order) {
            $recipient = $this->merchantNotificationEmail($store);
            if ($recipient) {
                Mail::to($recipient)->send(new MerchantOrderPaidEmail($store, $order));
            }
        }
    }

    public function orderShipped(Store $store, StoreOrder $order): void
    {
        if ($store->notify_customer_order_confirmation && filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerOrderShippedEmail($store, $order));
        }
    }

    public function orderCancelled(Store $store, StoreOrder $order): void
    {
        if ($store->notify_customer_order_confirmation && filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerOrderCancelledEmail($store, $order));
        }
    }

    public function orderRefunded(Store $store, StoreOrder $order): void
    {
        if ($store->notify_customer_payment_confirmation && filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerOrderRefundedEmail($store, $order));
        }
    }

    public function orderStatusChanged(Store $store, StoreOrder $order, string $previousStatus): void
    {
        // Hook for future merchant status-change digests; shipped/cancelled/refunded use dedicated mails.
        unset($store, $order, $previousStatus);
    }

    public function lowStock(Store $store, StoreProduct $product): void
    {
        if (! $store->notify_merchant_low_stock) {
            return;
        }

        $recipient = $this->merchantNotificationEmail($store);
        if (! $recipient) {
            return;
        }

        Mail::to($recipient)->send(new MerchantLowStockEmail($store, $product));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function billingEvent(Merchant $merchant, string $event, array $context = []): void
    {
        $merchant->loadMissing('owner');
        $recipient = $merchant->owner?->email;

        if (! filled($recipient)) {
            return;
        }

        Mail::to($recipient)->send(new MerchantBillingEmail($merchant, $event, $context));
    }

    public function merchantNotificationEmail(Store $store): ?string
    {
        $store->loadMissing('merchant');

        $email = $store->notification_email
            ?: $store->contact_email
            ?: $store->merchant?->owner?->email;

        return filled($email) ? (string) $email : null;
    }

    public function storefrontOrderUrl(Store $store, StoreOrder $order, string $path = 'checkout/success'): string
    {
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $base = 'https://'.$store->slug.'.'.$platformDomain;
        $email = urlencode((string) $order->customer_email);

        if ($path === 'checkout/success') {
            return $base.'/checkout/success?order='.urlencode($order->order_number).'&email='.$email;
        }

        if ($path === 'orders/track') {
            return $base.'/orders/track?order='.urlencode($order->order_number).'&email='.$email;
        }

        return $base.'/checkout?recover='.urlencode($order->order_number);
    }

    public function merchantDashboardUrl(): string
    {
        return rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/').'/admin/orders';
    }

    public function billingSettingsUrl(): string
    {
        return rtrim((string) config('storehause.app_url', 'http://localhost:3000'), '/').'/admin/settings/plan';
    }

    /**
     * @return array<string, mixed>
     */
    public function formatNotificationSettings(Store $store): array
    {
        return [
            'notify_merchant_new_order' => (bool) $store->notify_merchant_new_order,
            'notify_customer_order_confirmation' => (bool) $store->notify_customer_order_confirmation,
            'notify_customer_payment_confirmation' => (bool) $store->notify_customer_payment_confirmation,
            'notify_merchant_low_stock' => (bool) $store->notify_merchant_low_stock,
            'notification_email' => $store->notification_email,
            'customer_order_note' => $store->customer_order_note,
            'sms_sender_name' => $store->sms_sender_name,
        ];
    }

    public function formatMoney(float $amount, string $currency): string
    {
        return strtoupper($currency).' '.number_format($amount, 0);
    }

    public function formatRenewalDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toFormattedDateString();
        }

        return null;
    }
}
