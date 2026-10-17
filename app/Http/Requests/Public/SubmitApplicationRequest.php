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
            // Resume is optional by default; a job can make it Required or switch it off
            // (checked in PublicApplicationService::validateProfileFields).
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'], // 10MB
            // Extra form fields (client request, Breezy-style). Whether each one is shown / required
            // depends on the job's settings, so only the shape and length are checked here.
            'address' => ['nullable', 'string', 'max:255'],
            'desired_salary' => ['nullable', 'string', 'max:100'],
            'preferred_location' => ['nullable', 'string', 'max:255'],
            'experience_summary' => ['nullable', 'string', 'max:5000'],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'work_history' => ['nullable', 'array', 'max:20'],
            'work_history.*' => ['array'],
            'work_history.*.company' => ['nullable', 'string', 'max:255'],
            'work_history.*.title' => ['nullable', 'string', 'max:255'],
            'work_history.*.start' => ['nullable', 'string', 'max:50'],
            'work_history.*.end' => ['nullable', 'string', 'max:50'],
            'work_history.*.description' => ['nullable', 'string', 'max:3000'],
            'education' => ['nullable', 'array', 'max:10'],
            'education.*' => ['array'],
            'education.*.school' => ['nullable', 'string', 'max:255'],
            'education.*.degree' => ['nullable', 'string', 'max:255'],
            'education.*.field' => ['nullable', 'string', 'max:255'],
            'education.*.year' => ['nullable', 'string', 'max:20'],
            // Per-job custom question answers — keys are question UUIDs.
            // Which ones are REQUIRED varies per job, so that's checked
            // separately in PublicApplicationService::validateAnswers(),
            // not here (a static rules() array can't express that).
            'answers' => ['nullable', 'array'],
            // A text answer is a string; a multiple-choice answer is a list of the chosen options.
            'answers.*' => ['nullable', function ($attribute, $value, $fail) {
                if (is_string($value)) {
                    if (mb_strlen($value) > 5000) {
                        $fail('This answer is too long.');
                    }
                    return;
                }
                if (is_array($value)) {
                    if (count($value) > 50) {
                        $fail('Too many options selected.');
                        return;
                    }
                    foreach ($value as $item) {
                        if (!is_string($item) || mb_strlen($item) > 500) {
                            $fail('Invalid selection.');
                            return;
                        }
                    }
                    return;
                }
                $fail('Invalid answer.');
            }],
            // File-upload questions (PRD Sec 28/94): one file per question, keyed by question UUID.
            'answer_files' => ['nullable', 'array'],
            'answer_files.*' => ['file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'], // 10MB each
        ];
    }
}
