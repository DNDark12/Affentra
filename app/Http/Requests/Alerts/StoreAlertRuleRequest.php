<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use App\Support\AlertRuleContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAlertRuleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'metric' => ['required', 'string', Rule::in(AlertRuleContract::METRICS)],
            'operator' => ['required', 'string', Rule::in(AlertRuleContract::OPERATORS)],
            'threshold' => ['required', 'numeric'],
            'channel' => ['nullable', 'string', Rule::in(AlertRuleContract::CHANNELS)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'metric' => trim((string) $this->input('metric', '')),
            'operator' => trim((string) $this->input('operator', '')),
            'channel' => trim((string) $this->input('channel', 'in_app')),
        ]);
    }
}
