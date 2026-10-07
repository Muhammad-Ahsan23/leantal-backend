<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // PRD Section 71 — "Require typing DELETE."
            'confirmation' => ['required', 'in:DELETE'],
            // Client decision: deletion is permanent and immediate — there is no
            // retention choice any more. Still ACCEPTED (and ignored) so an older
            // frontend that keeps sending it doesn't get a validation error.
            'data_retention_choice' => ['sometimes', 'nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'You must type DELETE exactly to confirm.',
        ];
    }
}
