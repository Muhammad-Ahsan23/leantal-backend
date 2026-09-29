<?php

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

class CreateCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'in:starter,team,scale'], // Free never goes through Creem checkout
            'interval' => ['required', 'in:monthly,yearly'],
        ];
    }
}
