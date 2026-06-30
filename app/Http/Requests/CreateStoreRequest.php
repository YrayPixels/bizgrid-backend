<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\StorefrontTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'business_name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'industry' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:1000'],
            'brand_color' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'storefront_template_id' => ['nullable', 'string', Rule::in(array_merge(['ai_pick'], StorefrontTemplate::activeConcreteIds()))],
        ], $this->businessProfileRules(required: true));
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
