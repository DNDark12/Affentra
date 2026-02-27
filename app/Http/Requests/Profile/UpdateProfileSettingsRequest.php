<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateProfileSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name'                      => ['required', 'string', 'max:255'],
            'avatar'                    => ['nullable', 'url', 'max:2048'],
            'phone'                     => ['nullable', 'string', 'max:20'],
            'current_password'          => ['nullable', 'string'],
            'new_password'              => ['nullable', 'confirmed', Password::defaults()],
            'new_password_confirmation' => ['nullable', 'string'],
        ];
    }
}
