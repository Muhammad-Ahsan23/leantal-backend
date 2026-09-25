<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class TransferOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_owner_id' => ['required', 'uuid'],
            // PRD Section 69 — "Require typing TRANSFER." Must match
            // exactly (case-sensitive) — this is the confirmation step,
            // not a free-text field.
            'confirmation' => ['required', 'in:TRANSFER'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'You must type TRANSFER exactly to confirm.',
        ];
    }
}
