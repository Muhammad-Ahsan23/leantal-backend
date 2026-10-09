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
