<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SignUpRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254', 'unique:users,email'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            'password' => ['required', 'string', 'max:200'],
            'adult_and_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email already has an account. Log in instead.',
            'username.unique' => 'This username is taken.',
            'username.regex' => 'Use letters, numbers and underscores only.',
            'adult_and_terms.accepted' => 'Confirm you are 18 or older and agree to the Terms and Privacy Policy.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $username = $this->input('username');

        if (is_string($username)) {
            // The screen shows usernames as @handle; the @ is not part of the name.
            $this->merge(['username' => ltrim(trim($username), '@')]);
        }
    }
}
