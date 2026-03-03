<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use App\Support\AlertRuleContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAllAlertTemplatesRequest extends FormRequest
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
            'templates' => ['required', 'array', 'min:1', 'max:5'],
            'templates.*.metric' => ['required', 'string', Rule::in(AlertRuleContract::METRICS)],
            'templates.*.in_app_template' => ['required', 'string', 'max:5000'],
            'templates.*.telegram_template' => ['required', 'string', 'max:5000'],
        ];
    }
}

