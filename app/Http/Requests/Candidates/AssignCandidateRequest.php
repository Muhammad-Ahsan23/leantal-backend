<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

class AssignCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_user_id' => ['required_without:assigned_user_ids', 'uuid'],
            'assigned_user_ids' => ['required_without:assigned_user_id', 'array', 'min:1'],
            'assigned_user_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
