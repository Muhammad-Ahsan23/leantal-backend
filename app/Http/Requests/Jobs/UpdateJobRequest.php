<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJobRequest extends FormRequest
{
    /**
     * Always true here — this app resolves models across MULTIPLE
     * regional database connections (see RegionResolver), so implicit
     * route-model-binding (which assumes one default connection) can't
     * be trusted for the authorization check. The controller fetches the
     * Job on the correct connection FIRST, then calls $user->can(...)
     * manually before doing anything else.
     */
    public function authorize(): bool
    {
        return true; // actual authorization happens in the controller — see note above
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'department' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'location_type' => ['nullable', 'in:remote,hybrid,onsite'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contract,temporary,internship'],
            'compensation_enabled' => ['boolean'],
            'compensation_type' => ['nullable', 'in:salary_range,hourly_rate,annual_compensation,free_text'],
            'compensation_value' => ['nullable', 'string'],
            'about_company' => ['nullable', 'string'],
            'benefits' => ['nullable', 'string'],
            'team_info' => ['nullable', 'string'],
            'additional_sections' => ['nullable', 'array'],
        ];
    }
}
