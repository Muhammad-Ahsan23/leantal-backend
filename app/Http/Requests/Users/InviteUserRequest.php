<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // actual permission check happens in the controller via UserPolicy
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            // PRD Section 88: Owner invites HM or Recruiter — never another Owner
            'role' => ['required', 'in:hiring_manager,recruiter'],
        ];
    }
}
