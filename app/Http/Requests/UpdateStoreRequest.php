<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'business_name' => ['sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'brand_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], $this->businessProfileRules(required: false));
    }

    /** @return array<string, mixed> */
    private function businessProfileRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'business_location' => [$presence, 'string', Rule::in(['nigeria', 'kenya'])],
            'weekly_orders' => [$presence, 'string', Rule::in(['0-50', '51-100', '101-1000', '1001+'])],
            'payment_currencies' => [$presence, 'array', 'min:1'],
            'payment_currencies.*' => ['string', Rule::in(['NGN', 'KES', 'USD', 'GBP', 'CAD', 'others'])],
            'staff_count' => [$presence, 'string', Rule::in(['none', '1-3', '4-5', '6-10', '11+'])],
            'physical_store_count' => [$presence, 'string', Rule::in(['none', '1', '2', '3', '4+'])],
        ];
    }
}
