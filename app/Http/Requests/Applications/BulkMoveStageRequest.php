<?php

namespace App\Http\Requests\Applications;

use Illuminate\Foundation\Http\FormRequest;

// Bulk action on the Jobs Kanban: move up to 100 applications to ONE stage in one request.
class BulkMoveStageRequest extends FormRequest
{
    public const MAX_IDS = 100;

    public function authorize(): bool
    {
        return true; // checked per application in the controller, like MoveStageRequest
    }

    public function rules(): array
    {
        return [
            'application_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'application_ids.*' => ['required', 'uuid', 'distinct'],
            'stage_id' => ['required', 'uuid'],
            // Used when the target is the Rejected stage; stored internally, never shown to the candidate.
            'rejection_reason_internal' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
