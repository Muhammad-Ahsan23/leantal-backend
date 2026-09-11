<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

class CreateTaskRequest extends FormRequest
{
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
            'assigned_user_id' => ['required', 'uuid'],
            'candidate_id' => ['nullable', 'uuid', 'required_if:type,assign_candidate'],
            'job_id' => ['nullable', 'uuid', 'required_if:type,assign_job'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
