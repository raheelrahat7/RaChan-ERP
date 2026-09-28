<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DemoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:100'],
            'portfolio_size' => ['nullable', 'string', 'max:100'],
            'preferred_language' => ['nullable', 'in:en,ar'],
            'message' => ['nullable', 'string', 'max:3000'],
            'website' => ['nullable', 'max:0'],
        ];
    }
}
