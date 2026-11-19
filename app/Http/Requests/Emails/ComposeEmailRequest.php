<?php

namespace App\Http\Requests\Emails;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Compose: an email to ANY address(es), with optional attachments. Always sent from the user's own connected
 * mailbox (never from the system address, so it cannot be used to send as LeanTal).
 */
class ComposeEmailRequest extends FormRequest
{
    /** Files the app accepts (checked against the file's real content, not just its name). No archives or programs. */
    public const ALLOWED_TYPES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,rtf,odt,ods,png,jpg,jpeg,gif';

    public const MAX_FILES = 5;

    /** Per file and in total, in KB. Graph's sendMail request is capped at about 4 MB with base64 included, so 3 MB of files is the safe ceiling for both Gmail and Outlook. */
    public const MAX_KB = 3072;

    public const MAX_RECIPIENTS = 10;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'to.*' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_KB, 'mimes:'.self::ALLOWED_TYPES],
        ];
    }

    public function messages(): array
    {
        return [
            'to.required' => 'Add at least one recipient.',
            'to.max' => 'You can send to at most '.self::MAX_RECIPIENTS.' recipients at once.',
            'to.*.email' => 'One of the recipient addresses is not valid.',
            'to.*.distinct' => 'The same address is added twice.',
            'attachments.max' => 'You can attach at most '.self::MAX_FILES.' files.',
            'attachments.*.max' => 'Each file must be 3 MB or smaller.',
            'attachments.*.mimes' => 'This file type cannot be attached. Use PDF, Word, Excel, PowerPoint, text, CSV or an image.',
            'attachments.*.uploaded' => 'A file could not be uploaded. Please try again.',
        ];
    }
}
