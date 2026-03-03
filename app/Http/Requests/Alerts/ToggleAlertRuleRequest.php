<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

class ToggleAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

