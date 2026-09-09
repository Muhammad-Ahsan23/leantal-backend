<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            // Same min:10 assumption used at signup — keep both in sync if
            // the client confirms a different password policy.
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ];
    }
}
