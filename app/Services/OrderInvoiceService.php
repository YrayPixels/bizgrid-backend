<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\View;

class OrderInvoiceService
{
    public function __construct(
        private readonly StoreOrderItemService $orderItems,
    ) {}

    public function ensureInvoiceNumber(StoreOrder $order): string
    {
        if (filled($order->invoice_number)) {
            return (string) $order->invoice_number;
        }

        $order->invoice_number = 'INV-'.$order->id;
        $order->save();

        return (string) $order->invoice_number;
    }

    public function renderHtml(Store $store, StoreOrder $order): string
    {
        $this->ensureInvoiceNumber($order);

        return View::make('invoices.order', [
            'store' => $store,
            'order' => $order,
            'items' => $this->orderItems->linesForOrder($order),
            'invoiceNumber' => $order->invoice_number,
        ])->render();
    }
}
