<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StoreOrder */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'order_number' => $this->order_number,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'delivery_address' => $this->delivery_address,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'total_amount' => (float) $this->total_amount,
            'items' => $this->items ?? [],
            'notes' => $this->notes,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
