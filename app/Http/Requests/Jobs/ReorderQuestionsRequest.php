<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class ReorderQuestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question_order' => ['required', 'array', 'min:1'],
            'question_order.*' => ['required', 'uuid'],
        ];
    }
}
