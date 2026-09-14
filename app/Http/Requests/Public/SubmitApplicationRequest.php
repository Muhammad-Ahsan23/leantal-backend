<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class SubmitApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint — anyone can apply
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'], // 10MB — ASSUMPTION: optional, PRD doesn't explicitly mandate it
            // Per-job custom question answers — keys are question UUIDs.
            // Which ones are REQUIRED varies per job, so that's checked
            // separately in PublicApplicationService::validateAnswers(),
            // not here (a static rules() array can't express that).
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
