<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

/**
 * The account fields shared by both ways to sign up (password or passkey).
 */
trait AccountFields
{
    /**
     * @return array<string, list<string>>
     */
    private function accountRules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254', 'unique:users,email'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_]+$/', 'unique:users,username'],
            // Optional and unverified (decision D-12): the verified email stays the primary contact.
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'adult_and_terms' => ['accepted'],
            // Canvas AuthSignup: "I'm a fan / I'm a creator". A creator goes on to Become a creator.
            'joining_as' => ['nullable', 'string', 'in:fan,creator'],
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
            'password.confirmed' => 'The two passwords do not match.',
            'username.regex' => 'Use letters, numbers and underscores only.',
            'phone.regex' => 'Enter the number with its country code, for example +254.',
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

        $phone = $this->input('phone');

        if (is_string($phone)) {
            $phone = (string) preg_replace('/[\s().-]+/', '', $phone);
            $this->merge(['phone' => $phone === '' ? null : $phone]);
        }
    }
}
