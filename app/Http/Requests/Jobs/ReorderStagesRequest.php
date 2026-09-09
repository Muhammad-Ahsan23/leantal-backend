<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class ReorderStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Only CUSTOM stage IDs, in the new desired order. Applied/
            // Hired/Rejected are never included — their position is fixed
            // (first, and last-two respectively) and recalculated
            // automatically, not submitted by the client.
            'stage_order' => ['required', 'array', 'min:1'],
            'stage_order.*' => ['required', 'uuid'],
        ];
    }
}
