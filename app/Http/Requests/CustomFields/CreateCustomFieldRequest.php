<?php

namespace App\Http\Requests\CustomFields;

use Illuminate\Foundation\Http\FormRequest;

class CreateCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'in:job,candidate'],
            'field_name' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'in:text,number,date,dropdown,boolean'],
            // 'options' required only for dropdown — the array of choices.
            'options' => ['nullable', 'array', 'required_if:field_type,dropdown'],
            'options.*' => ['string'],
            'required' => ['boolean'],
        ];
    }
}
