<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

class MoveStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller — see note on other requests
    }

    public function rules(): array
    {
        return [
            'stage_id' => ['required', 'uuid'],
            // Client must submit the lock_version it last saw — this is
            // what makes optimistic locking work (Section 98).
            'lock_version' => ['required', 'integer', 'min:1'],
            'rejection_reason_internal' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
