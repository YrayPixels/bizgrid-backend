<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateJumiaDeliveryAddressRequest extends FormRequest
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
            'full_name' => 'required|string|max:255',
            'phone_number' => 'required|string|max:20|regex:/^[0-9+\-\s()]+$/',
            'email' => 'nullable|email|max:255',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|max:100',
            'is_default' => 'boolean',
            'landmark' => 'nullable|string|max:255',
            'additional_instructions' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180'
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Full name is required.',
            'full_name.max' => 'Full name cannot exceed 255 characters.',
            'phone_number.required' => 'Phone number is required.',
            'phone_number.max' => 'Phone number cannot exceed 20 characters.',
            'phone_number.regex' => 'Please enter a valid phone number.',
            'email.email' => 'Please enter a valid email address.',
            'email.max' => 'Email cannot exceed 255 characters.',
            'address_line_1.required' => 'Address line 1 is required.',
            'address_line_1.max' => 'Address line 1 cannot exceed 255 characters.',
            'city.required' => 'City is required.',
            'city.max' => 'City cannot exceed 100 characters.',
            'state.required' => 'State is required.',
            'state.max' => 'State cannot exceed 100 characters.',
            'postal_code.required' => 'Postal code is required.',
            'postal_code.max' => 'Postal code cannot exceed 20 characters.',
            'country.required' => 'Country is required.',
            'country.max' => 'Country cannot exceed 100 characters.',
            'landmark.max' => 'Landmark cannot exceed 255 characters.',
            'additional_instructions.max' => 'Additional instructions cannot exceed 1000 characters.',
            'latitude.between' => 'Latitude must be between -90 and 90.',
            'longitude.between' => 'Longitude must be between -180 and 180.'
        ];
    }
}
