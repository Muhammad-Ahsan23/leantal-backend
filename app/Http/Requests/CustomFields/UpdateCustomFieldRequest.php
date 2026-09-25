<?php

namespace App\Http\Requests\CustomFields;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // entity_type and field_type are deliberately NOT editable —
            // changing a field's TYPE after values exist would corrupt
            // already-stored data (e.g. a 'number' field with text
            // values in it). Delete and recreate instead if the type
            // needs to change.
            'field_name' => ['sometimes', 'required', 'string', 'max:255'],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.*' => ['string'],
            'required' => ['sometimes', 'boolean'],
        ];
    }
}
