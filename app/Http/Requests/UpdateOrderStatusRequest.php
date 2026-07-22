<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                'in:pending,processing,shipped,delivered,cancelled,fulfilled,refunded,confirmed',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'refund' => ['sometimes', 'boolean'],
        ];
    }
}
