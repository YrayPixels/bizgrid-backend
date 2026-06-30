<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitContactRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'block_id' => ['nullable', 'string', 'max:80'],
            'fields' => ['required', 'array', 'min:1', 'max:12'],
            'fields.*' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
