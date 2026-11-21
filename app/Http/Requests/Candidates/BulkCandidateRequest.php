<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// PRD 25 - bulk actions on the Candidates page. One request, up to 100 candidates, one action.
class BulkCandidateRequest extends FormRequest
{
    public const MAX_IDS = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:archive,restore,assign,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'uuid', 'distinct'],
            'assigned_user_id' => ['nullable', 'uuid', Rule::requiredIf(fn () => $this->input('action') === 'assign' && empty($this->input('assigned_user_ids')))],
            'assigned_user_ids' => ['nullable', 'array'],
            'assigned_user_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
