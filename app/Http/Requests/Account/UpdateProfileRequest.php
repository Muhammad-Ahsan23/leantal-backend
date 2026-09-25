<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PRD Section 114 — "Users manage personal name..."
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
