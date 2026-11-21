<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class AssignJobRequest extends FormRequest
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
            // One person (assigned_user_id, as before) or several (assigned_user_ids).
            'assigned_user_id' => ['required_without:assigned_user_ids', 'uuid'],
            'assigned_user_ids' => ['required_without:assigned_user_id', 'array', 'min:1'],
            'assigned_user_ids.*' => ['uuid', 'distinct'],
        ];
    }
}
