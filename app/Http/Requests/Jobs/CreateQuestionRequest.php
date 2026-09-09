<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller — see note on other Job requests
    }

    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:1000'],
            'type' => ['required', 'in:short_text,long_text,yes_no,multiple_choice,single_choice,number,date,file_upload'],
            'required' => ['boolean'],
            'options' => ['nullable', 'array', 'required_if:type,multiple_choice', 'required_if:type,single_choice'],
            'options.*' => ['string'],
            // PRD Section 29 — "must be explicitly confirmed" — required_if
            // means the client CANNOT silently skip this when knockout=true.
            'knockout' => ['boolean'],
            'knockout_action' => ['nullable', 'required_if:knockout,true', 'in:reject,flag_only'],
            'knockout_expected_answer' => ['nullable', 'required_if:knockout,true', 'string'],
        ];
    }
}
