<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTelegramConfigRequest extends FormRequest
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
            'bot_token' => [
                'nullable',
                'string',
                'max:4096',
                'regex:/^\d{6,12}:[A-Za-z0-9_-]{20,}$/',
            ],
            'group_id' => [
                'nullable',
                'string',
                'max:100',
                'regex:/^(-?\d{5,32}|@[A-Za-z][A-Za-z0-9_]{4,31})$/',
            ],
            'is_enabled' => ['nullable', 'boolean'],
            'fallback_to_in_app' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('bot_token')) {
            $token = trim((string) $this->input('bot_token', ''));
            $payload['bot_token'] = $token !== '' ? $token : null;
        }

        if ($this->has('group_id')) {
            $groupId = $this->normalizeTelegramTargetInput((string) $this->input('group_id', ''));
            $payload['group_id'] = $groupId !== '' ? $groupId : null;
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bot_token.regex' => 'Bot token Telegram không đúng định dạng.',
            'group_id.regex' => 'Group ID phải là số (có thể âm) hoặc @username hợp lệ.',
        ];
    }

    private function normalizeTelegramTargetInput(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        if (preg_match('~web\\.telegram\\.org/.*/#(-?\\d+)~i', $value, $matches) === 1) {
            return trim((string) ($matches[1] ?? ''));
        }

        if (preg_match('~t\\.me/([A-Za-z][A-Za-z0-9_]{4,31})~i', $value, $matches) === 1) {
            return '@' . trim((string) ($matches[1] ?? ''));
        }

        return $value;
    }
}
