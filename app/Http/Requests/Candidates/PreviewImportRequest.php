<?php

namespace App\Http\Requests\Candidates;

use Illuminate\Foundation\Http\FormRequest;

class PreviewImportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Comes from the earlier detectHeaders() call — the file
            // itself was already uploaded then, this step only needs
            // to know WHICH upload + HOW the user chose to map it.
            'upload_id' => ['required', 'uuid'],
            'column_mapping' => ['required', 'array'],
            'column_mapping.first_name' => ['required', 'integer', 'min:0'],
            'column_mapping.last_name' => ['nullable', 'integer', 'min:0'],
            'column_mapping.email' => ['required', 'integer', 'min:0'],
            'column_mapping.phone' => ['nullable', 'integer', 'min:0'],
            'column_mapping.current_title' => ['nullable', 'integer', 'min:0'],
            'column_mapping.current_company' => ['nullable', 'integer', 'min:0'],
            'column_mapping.linkedin_url' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
