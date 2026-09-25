<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller via CompanyPolicy
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'website' => ['sometimes', 'required', 'url', 'max:255'],
            'location' => ['sometimes', 'required', 'string', 'max:255'],
            // PRD Section 68 — "Company (Owner only): Company name,
            // website, location, careers slug." Slug IS editable
            // (earlier version of this file wrongly excluded it).
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            // PRD Section 44/45 — careers page needs a "simple
            // description/header"; Section 13's minimal-field list
            // doesn't name it, but the schema (careers_description
            // column) already supports it as the field that fills that
            // requirement.
            'careers_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
