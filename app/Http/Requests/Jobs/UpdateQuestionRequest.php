<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class UpdateQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => ['sometimes', 'required', 'string', 'max:1000'],
            'type' => ['sometimes', 'required', 'in:short_text,long_text,yes_no,multiple_choice,single_choice,number,date,file_upload'],
            'required' => ['boolean'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string'],
            'knockout' => ['boolean'],
            'knockout_action' => ['nullable', 'required_if:knockout,true', 'in:reject,flag_only'],
            'knockout_expected_answer' => ['nullable', 'required_if:knockout,true', 'string'],
        ];
    }
}
