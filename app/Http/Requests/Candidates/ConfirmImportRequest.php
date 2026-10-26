<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmImportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'uuid'],
            // Optional (PRD Section 32: a candidate exists without any job). Omitted = add candidates only.
            'job_id' => ['nullable', 'uuid'],
            // Optional, needs a job_id: land every imported candidate in this stage of the job instead of Applied.
            'stage_id' => ['nullable', 'uuid'],
        ];
    }
}