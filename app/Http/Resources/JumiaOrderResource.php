<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JumiaOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'jumia_order_id' => $this->jumia_order_id,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'order_date' => $this->order_date?->toISOString(),
            'estimated_delivery_date' => $this->estimated_delivery_date?->toISOString(),
            'notes' => $this->notes,
            'tracking_number' => $this->tracking_number,
            'delivery_fee' => $this->delivery_fee,
            'tax_amount' => $this->tax_amount,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'coupon_code' => $this->coupon_code,
            'is_express_delivery' => $this->is_express_delivery,
            'delivery_instructions' => $this->delivery_instructions,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Relationships
            'delivery_address' => new JumiaDeliveryAddressResource($this->whenLoaded('deliveryAddress')),
            'order_items' => JumiaOrderItemResource::collection($this->whenLoaded('orderItems')),
            'order_history' => JumiaOrderHistoryResource::collection($this->whenLoaded('orderHistory')),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            
            // Computed fields
            'status_label' => $this->getStatusLabel(),
            'payment_status_label' => $this->getPaymentStatusLabel(),
            'formatted_total' => $this->currency . ' ' . number_format($this->total_amount, 2),
            'days_to_delivery' => $this->getDaysToDelivery(),
        ];
    }

    /**
     * Get human-readable status label
     */
    private function getStatusLabel(): string
    {
        return match($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'out_for_delivery' => 'Out for Delivery',
            'delivered' => 'Delivered',
            'cancelled' => 'Cancelled',
            'returned' => 'Returned',
            'refunded' => 'Refunded',
            default => ucfirst($this->status)
        };
    }

    /**
     * Get human-readable payment status label
     */
    private function getPaymentStatusLabel(): string
    {
        return match($this->payment_status) {
            'pending' => 'Pending',
            'paid' => 'Paid',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            default => ucfirst($this->payment_status)
        };
    }

    /**
     * Calculate days to delivery
     */
    private function getDaysToDelivery(): ?int
    {
        if (!$this->estimated_delivery_date) {
            return null;
        }

        $now = now();
        $deliveryDate = $this->estimated_delivery_date;
        
        if ($deliveryDate->isPast()) {
            return 0;
        }

        return $now->diffInDays($deliveryDate, false);
    }
}
