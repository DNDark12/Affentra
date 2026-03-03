<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use App\Support\AlertRuleContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAlertRuleRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'metric' => ['sometimes', 'required', 'string', Rule::in(AlertRuleContract::METRICS)],
            'operator' => ['sometimes', 'required', 'string', Rule::in(AlertRuleContract::OPERATORS)],
            'threshold' => ['sometimes', 'numeric'],
            'channel' => ['sometimes', 'required', 'string', Rule::in(AlertRuleContract::CHANNELS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('name')) {
            $payload['name'] = trim((string) $this->input('name', ''));
        }
        if ($this->has('metric')) {
            $payload['metric'] = trim((string) $this->input('metric', ''));
        }
        if ($this->has('operator')) {
            $payload['operator'] = trim((string) $this->input('operator', ''));
        }
        if ($this->has('channel')) {
            $payload['channel'] = trim((string) $this->input('channel', ''));
        }

        if ($payload !== []) {
            $this->merge($payload);
        }
    }
}
