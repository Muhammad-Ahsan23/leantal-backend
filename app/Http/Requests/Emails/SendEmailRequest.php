<?php

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'send' = actually send via our SMTP now (needs subject+body
            // OR template_id+variables). 'log' = just record an email the
            // staff member already sent from their own Gmail/Outlook.
            'mode' => ['required', 'in:send,log'],
            'template_id' => ['nullable', 'uuid'],
            'variables' => ['nullable', 'array'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            // Only meaningful for mode=log:
            'direction' => ['nullable', 'in:outbound,inbound'],
            'provider' => ['nullable', 'in:gmail,outlook'],
            'sent_at' => ['nullable', 'date'],
        ];
    }
}
