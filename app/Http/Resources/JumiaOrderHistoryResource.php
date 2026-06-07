<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JumiaOrderHistoryResource extends JsonResource
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
            'status' => $this->status,
            'status_description' => $this->status_description,
            'timestamp' => $this->timestamp?->toISOString(),
            'notes' => $this->notes,
            'updated_by' => $this->updated_by,
            'external_reference' => $this->external_reference,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Computed fields
            'status_label' => $this->getStatusLabel(),
            'time_ago' => $this->getTimeAgo(),
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
     * Get human-readable time ago
     */
    private function getTimeAgo(): string
    {
        if (!$this->timestamp) {
            return 'Unknown';
        }

        $now = now();
        $timestamp = $this->timestamp;
        
        if ($timestamp->isPast()) {
            $diff = $now->diff($timestamp);
            
            if ($diff->days > 0) {
                return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
            } elseif ($diff->h > 0) {
                return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
            } elseif ($diff->i > 0) {
                return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
            } else {
                return 'Just now';
            }
        }
        
        return 'Future';
    }
}
