<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateJumiaOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'delivery_address_id' => 'required|exists:jumia_delivery_addresses,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|string|max:255',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'items.*.unit_price' => 'required|numeric|min:0|max:999999.99',
            'items.*.product_sku' => 'nullable|string|max:255',
            'items.*.product_image_url' => 'nullable|url|max:500',
            'items.*.product_url' => 'nullable|url|max:500',
            'items.*.category' => 'nullable|string|max:100',
            'items.*.brand' => 'nullable|string|max:100',
            'items.*.size' => 'nullable|string|max:50',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.weight' => 'nullable|numeric|min:0|max:999.99',
            'items.*.dimensions' => 'nullable|string|max:100',
            'payment_method' => 'nullable|string|in:card,cash_on_delivery,bank_transfer,wallet',
            'notes' => 'nullable|string|max:1000',
            'is_express_delivery' => 'boolean',
            'delivery_instructions' => 'nullable|string|max:500',
            'coupon_code' => 'nullable|string|max:50'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'delivery_address_id.required' => 'Please select a delivery address.',
            'delivery_address_id.exists' => 'The selected delivery address is invalid.',
            'items.required' => 'Please add at least one item to your order.',
            'items.min' => 'Please add at least one item to your order.',
            'items.*.product_id.required' => 'Product ID is required for all items.',
            'items.*.product_name.required' => 'Product name is required for all items.',
            'items.*.quantity.required' => 'Quantity is required for all items.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'items.*.quantity.max' => 'Quantity cannot exceed 100.',
            'items.*.unit_price.required' => 'Unit price is required for all items.',
            'items.*.unit_price.min' => 'Unit price must be positive.',
            'payment_method.in' => 'Please select a valid payment method.',
            'is_express_delivery.boolean' => 'Express delivery must be true or false.'
        ];
    }
}
