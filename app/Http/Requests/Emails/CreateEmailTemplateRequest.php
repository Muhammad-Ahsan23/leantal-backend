<?php

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

class CreateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            // Body contains {{variable}} placeholders — e.g. {{candidate_name}},
            // {{job_title}}, {{company_name}} — substituted at send-time.
            'body' => ['required', 'string', 'max:10000'],
        ];
    }
}
