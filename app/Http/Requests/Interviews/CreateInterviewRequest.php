<?php

namespace App\Http\Requests\Interviews;

use Illuminate\Foundation\Http\FormRequest;

class CreateInterviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // checked in controller
    }

    public function rules(): array
    {
        return [
            'candidate_id' => ['required', 'uuid'],
            'job_id' => ['required', 'uuid'],
            'organizer_id' => ['required', 'uuid'],
            'interview_type' => ['nullable', 'string', 'max:255'],
            // Schema's provider ENUM — video-conferencing only, per the
            // actual migration (no 'phone'/'onsite' option exists).
            'provider' => ['required', 'in:google_meet,microsoft_teams,zoom'],
            // ASSUMPTION: manually pasted for now — auto-generating a
            // real Meet/Teams/Zoom link requires that provider's OAuth
            // to be connected (see OAuthConnectionController), which
            // isn't wired to live external APIs yet (deliberately
            // deferred, same reasoning as GoogleIndexingService).
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
        ];
    }
}
