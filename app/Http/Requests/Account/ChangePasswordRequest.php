<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // Same strength bar as signup — Argon2id hashing happens
            // automatically via the User model's password cast/mutator
            // (already wired for signup/accept-invite; unchanged here).
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
