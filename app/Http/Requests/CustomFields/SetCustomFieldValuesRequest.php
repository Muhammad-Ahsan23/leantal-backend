<?php

namespace App\Http\Requests\CustomFields;

use Illuminate\Foundation\Http\FormRequest;

class SetCustomFieldValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'in:job,candidate'],
            'entity_id' => ['required', 'uuid'],
            // values is a map: { "<custom_field_id>": "<value>", ... }
            // Per-field type validation (number/date/dropdown/boolean)
            // happens in CustomFieldService — can't be expressed here
            // since which fields exist is dynamic, not static rules.
            'values' => ['required', 'array'],
        ];
    }
}
