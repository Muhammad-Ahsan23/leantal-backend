<?php

namespace App\Http\Requests\NotificationPreferences;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notification_type' => ['required', 'string', 'max:100'],
            'channel' => ['required', 'in:email,in_app'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
