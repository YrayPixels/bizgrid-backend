<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JumiaOrderItemResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'product_sku' => $this->product_sku,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'total_price' => $this->total_price,
            'product_image_url' => $this->product_image_url,
            'product_url' => $this->product_url,
            'category' => $this->category,
            'brand' => $this->brand,
            'size' => $this->size,
            'color' => $this->color,
            'weight' => $this->weight,
            'dimensions' => $this->dimensions,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            
            // Computed fields
            'formatted_unit_price' => 'NGN ' . number_format($this->unit_price, 2),
            'formatted_total_price' => 'NGN ' . number_format($this->total_price, 2),
            'formatted_weight' => $this->weight ? number_format($this->weight, 2) . ' kg' : null,
        ];
    }
}
