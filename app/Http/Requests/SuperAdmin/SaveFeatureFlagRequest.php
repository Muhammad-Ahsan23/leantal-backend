<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class SaveFeatureFlagRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'enabled' => ['required', 'boolean'],
            'scope' => ['required', 'in:global,company'],
            'company_ids' => ['required_if:scope,company', 'array'],
            'company_ids.*' => ['uuid'],
        ];
    }
}
