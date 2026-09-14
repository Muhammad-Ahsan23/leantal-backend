<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

class CreateCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller — see note on Job requests
    }

    public function rules(): array
    {
        return [
            'job_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'current_title' => ['nullable', 'string', 'max:255'],
            'current_company' => ['nullable', 'string', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'], // 10MB
        ];
    }
}
