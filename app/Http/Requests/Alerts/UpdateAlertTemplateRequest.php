<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAlertTemplateRequest extends FormRequest
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
            'in_app_template' => ['required', 'string', 'max:5000'],
            'telegram_template' => ['required', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'in_app_template' => trim((string) $this->input('in_app_template', '')),
            'telegram_template' => trim((string) $this->input('telegram_template', '')),
        ]);
    }
}

