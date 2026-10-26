<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the resume on an existing candidate. Same file rules as the resume on Add Candidate.
 */
class ReplaceResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller via CandidatePolicy::update()
    }

    public function rules(): array
    {
        return [
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'], // 10MB
        ];
    }
}