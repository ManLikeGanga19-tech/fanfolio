<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Continue with a passkey": the same account fields, no password.
 */
final class PasskeySignUpRequest extends FormRequest
{
    use AccountFields;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->accountRules();
    }
}
