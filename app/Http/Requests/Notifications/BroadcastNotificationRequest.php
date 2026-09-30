<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class BroadcastNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:500'],
            // Empty/omitted = broadcast to the whole company.
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['uuid'],
        ];
    }
}
