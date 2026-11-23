<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SignUpRequest extends FormRequest
{
    use AccountFields;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->accountRules(),
            'password' => ['required', 'string', 'max:200', 'confirmed'],
        ];
    }
}
