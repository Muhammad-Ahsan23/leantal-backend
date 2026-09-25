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
            // PRD Section 71 step 3 — "Prompt for candidate data
            // retention/deletion preferences." Values are a reasonable
            // ASSUMPTION (PRD names the step but not the exact choices)
            // — adjust once the client confirms exact wording/options.
            'data_retention_choice' => ['required', 'in:delete_immediately,retain_30_days,export_then_delete'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'You must type DELETE exactly to confirm.',
        ];
    }
}
