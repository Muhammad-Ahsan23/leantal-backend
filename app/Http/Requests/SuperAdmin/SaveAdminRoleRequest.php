<?php

namespace App\Http\Requests\SuperAdmin;

use App\Support\AdminPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAdminRoleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('admin_db.admin_roles', 'name')->ignore($this->route('id'))],
            'description' => ['nullable', 'string', 'max:300'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(AdminPermissions::all())],
        ];
    }
}
