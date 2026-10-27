<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'role_id' => ['sometimes', 'uuid', Rule::exists('admin_db.admin_roles', 'id')],
            'disabled' => ['sometimes', 'boolean'],
        ];
    }
}
