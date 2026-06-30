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
            'status' => ['required', 'string', 'in:pending,processing,fulfilled,cancelled,refunded'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
