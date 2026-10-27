<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class ImpersonateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // PRD Section 80 — reason is explicitly required. The ticket ties the session to a support case.
            'reason' => ['required', 'string', 'max:500'],
            'ticket' => ['required', 'string', 'max:100'],
            // read_only is the safe default; full needs the extra companies.impersonate_full permission (checked in the controller).
            'mode' => ['nullable', 'in:read_only,full'],
        ];
    }
}
