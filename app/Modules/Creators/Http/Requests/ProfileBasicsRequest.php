<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ProfileBasicsRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'min:2', 'max:50'],
            'handle' => ['required', 'string', 'max:31'],
            'category' => ['required', 'string', 'max:30'],
            'is_adult' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Enter the name fans will see.',
            'handle.required' => 'Choose a handle for your page address.',
            'category.required' => 'Choose a category.',
        ];
    }
}
