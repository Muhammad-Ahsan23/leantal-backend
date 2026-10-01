<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class ImpersonateUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // PRD Section 80 — "start time, end time, and REASON in
            // audit logs" — reason is explicitly required, not optional.
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
