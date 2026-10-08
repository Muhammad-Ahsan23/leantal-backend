<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PRD Section 7 ("Edit permitted candidate information") and Section 72 (GDPR "correction").
 * Only the profile fields of Section 35. Email is deliberately NOT editable here: duplicate
 * detection (Section 135) works on the normalized email, so changing it is a different operation.
 */
class UpdateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller via CandidatePolicy::update()
    }

    protected function prepareForValidation(): void
    {
        $linkedin = $this->input('linkedin_url');
        if (is_string($linkedin)) {
            $linkedin = trim($linkedin);
            if ($linkedin !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $linkedin)) {
                $linkedin = 'https://'.ltrim($linkedin, '/');
            }
            $this->merge(['linkedin_url' => $linkedin]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'current_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'current_company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'linkedin_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
        ];
    }
}
