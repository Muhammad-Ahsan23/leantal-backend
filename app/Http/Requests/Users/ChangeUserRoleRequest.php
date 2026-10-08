<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission is checked in the controller via UserPolicy::changeRole
    }

    public function rules(): array
    {
        return [
            // PRD Section 6/88: there are exactly three customer roles and exactly ONE Owner. The Owner
            // role is never handed out here (only "Transfer ownership" moves it), so only these two.
            'role' => ['required', 'string', 'in:hiring_manager,recruiter'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Choose Hiring Manager or Recruiter. The Owner role can only be moved with Transfer ownership.',
        ];
    }
}
