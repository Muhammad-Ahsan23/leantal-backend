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
            // No meeting_url here on purpose (PRD Section 61): the link is always created by LeanTal through
            // the organizer's calendar (see InterviewService), never typed in or pasted by a user.
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
        ];
    }
}
