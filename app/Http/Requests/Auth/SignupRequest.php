<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint — anyone can sign up
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'company_email' => ['required', 'email', 'max:255'],
            // NOTE: PRD Section 15 mandates Argon2id hashing but does not
            // state a password complexity rule — min:10 is a reasonable
            // assumption (matches what we used in the frontend prototype),
            // flagged here as an assumption rather than a stated PRD rule.
            'password' => ['required', 'string', 'min:10'],
            'company_website' => ['required', 'string', 'max:255'],
            // "Company location" (PRD Section 12) — location is the free-text
            // display value, country_code is what the region resolver
            // actually needs (matches the country dropdown in the frontend).
            'location' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'captcha_token' => ['required', 'string'],
        ];
    }

    /**
     * PRD Section 12 — "The Owner's email domain must match the company
     * website domain." Applies ONLY at signup (Owner can invite any email
     * domain afterward). Checked here, not in rules(), because it compares
     * two fields together.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $email = $this->input('company_email');
            $website = $this->input('company_website');

            if (!$email || !$website) {
                return; // let the required/email rules report those errors first
            }

            $emailDomain = strtolower(trim(explode('@', $email)[1] ?? ''));
            $websiteDomain = strtolower(trim(preg_replace('#^https?://#', '', $website)));
            $websiteDomain = preg_replace('#^www\.#', '', $websiteDomain);
            $websiteDomain = explode('/', $websiteDomain)[0]; // strip any path

            if ($emailDomain !== $websiteDomain) {
                $validator->errors()->add(
                    'company_email',
                    'Your email domain must match your company website domain.'
                );
            }
        });
    }
}
