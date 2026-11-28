<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTaskRequest extends FormRequest
{
    /** Most candidates one request can create tasks for. */
    public const MAX_CANDIDATES = 50;

    public function authorize(): bool
    {
        return true; // checked in controller — see note on other requests
    }

    public function rules(): array
    {
        return [
            // PRD Section 62 — 3 task types
            'type' => ['required', 'in:normal,assign_candidate,assign_job'],
            'title' => ['required', 'string', 'max:255'],
            'assigned_user_id' => ['required_without:assigned_user_ids', 'uuid'],
            'assigned_user_ids' => ['required_without:assigned_user_id', 'array', 'min:1'],
            'assigned_user_ids.*' => ['uuid', 'distinct'],
            // One candidate (candidate_id) or several at once (candidate_ids: one task is created for each, all or nothing).
            'candidate_id' => ['nullable', 'uuid', Rule::requiredIf(fn () => $this->input('type') === 'assign_candidate' && empty($this->input('candidate_ids')))],
            'candidate_ids' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_CANDIDATES, 'prohibited_unless:type,assign_candidate'],
            'candidate_ids.*' => ['uuid', 'distinct'],
            'job_id' => ['nullable', 'uuid', 'required_if:type,assign_job'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
