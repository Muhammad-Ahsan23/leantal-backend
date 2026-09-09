<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class CreateJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // actual authorization happens in the controller — see note above
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'location_type' => ['nullable', 'in:remote,hybrid,onsite'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,temporary,internship'],
            'compensation_enabled' => ['boolean'],
            'compensation_type' => ['nullable', 'required_if:compensation_enabled,true', 'in:salary_range,hourly_rate,annual_compensation,free_text'],
            'compensation_value' => ['nullable', 'string'],
            'about_company' => ['nullable', 'string'],
            'benefits' => ['nullable', 'string'],
            'team_info' => ['nullable', 'string'],
            'additional_sections' => ['nullable', 'array'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'publish' => ['boolean'], // if true, job goes straight to 'published' instead of 'draft'
        ];
    }
}
