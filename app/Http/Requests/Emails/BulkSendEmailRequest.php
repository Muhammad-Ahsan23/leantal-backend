<?php

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

// Bulk "Send Email" on the Jobs Kanban: one message to up to 100 selected applications, sent in the background.
class BulkSendEmailRequest extends FormRequest
{
    public const MAX_IDS = 100;

    public function authorize(): bool
    {
        return true; // checked per application in the controller, like the other bulk actions
    }

    public function rules(): array
    {
        return [
            'application_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'application_ids.*' => ['required', 'uuid', 'distinct'],
            // send_personal = the acting user's own connected Gmail/Outlook (normal bulk email).
            // send_system   = LeanTal's no-reply address (PRD Section 55: rejection emails), same as the single rejection email.
            'mode' => ['required', 'in:send_personal,send_system'],
            'template_id' => ['nullable', 'uuid'],
            'subject' => ['nullable', 'string', 'max:255', 'required_without:template_id'],
            'body' => ['nullable', 'string', 'max:10000', 'required_without:template_id'],
        ];
    }
}
