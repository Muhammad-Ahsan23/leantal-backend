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
            // OPTIONAL: a candidate can be added on their own (PRD Section 32 — a candidate is a person,
            // an application is that person's application to ONE job). With a job_id they also get an
            // application in that job's Applied stage; without one they are only added to the candidate list
            // and can be put into a job later ("Add to a job" on their profile).
            'job_id' => ['nullable', 'uuid'],
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
