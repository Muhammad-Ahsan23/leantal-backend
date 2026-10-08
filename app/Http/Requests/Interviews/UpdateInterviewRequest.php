<?php

namespace App\Http\Requests\Interviews;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interview_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'provider' => ['sometimes', 'required', 'in:google_meet,microsoft_teams,zoom'],
            'start_time' => ['sometimes', 'required', 'date'],
            'end_time' => ['sometimes', 'required', 'date', 'after:start_time'],
        ];
    }
}
