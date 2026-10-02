<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class PlatformBroadcastRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            // PRD Section 81 — "All customers / Selected companies / Selected users."
            'target' => ['required', 'in:all,companies,users'],
            'company_ids' => ['required_if:target,companies', 'array'],
            'company_ids.*' => ['uuid'],
            // Each entry needs BOTH ids — a user_id alone can't be
            // resolved to a region/connection without knowing which
            // company (and therefore which regional database) it lives in.
            'users' => ['required_if:target,users', 'array'],
            'users.*.company_id' => ['required_with:users', 'uuid'],
            'users.*.user_id' => ['required_with:users', 'uuid'],
        ];
    }
}
