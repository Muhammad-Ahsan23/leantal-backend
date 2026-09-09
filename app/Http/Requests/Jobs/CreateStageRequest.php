<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class CreateStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller — see note on other Job requests
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
