<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

class UploadTaskAttachmentsRequest extends FormRequest
{
    public const MAX_PER_REQUEST = 5;
    public const MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,csv,txt,png,jpg,jpeg';

    public function authorize(): bool
    {
        return true; // checked in the controller via TaskPolicy::update()
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_REQUEST],
            'files.*' => ['file', 'mimes:'.self::MIMES, 'max:10240'], // 10MB each
        ];
    }
}
