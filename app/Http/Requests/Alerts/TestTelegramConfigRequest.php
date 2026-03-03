<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

class TestTelegramConfigRequest extends FormRequest
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
            'message' => ['nullable', 'string', 'max:1000'],
            'group_id' => ['nullable', 'string', 'max:100', 'regex:/^(-?\d{5,32}|@[A-Za-z][A-Za-z0-9_]{4,31})$/'],
            // Backward-compatible alias for older clients.
            'chat_id' => ['nullable', 'string', 'max:100', 'regex:/^(-?\d{5,32}|@[A-Za-z][A-Za-z0-9_]{4,31})$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = ['message' => trim((string) $this->input('message', ''))];

        if ($this->filled('bot_token')) {
            $payload['bot_token'] = trim((string) $this->input('bot_token', ''));
        }

        if ($this->filled('group_id')) {
            $payload['group_id'] = $this->normalizeTelegramTargetInput((string) $this->input('group_id', ''));
        }

        if ($this->filled('chat_id')) {
            $legacyChatId = $this->normalizeTelegramTargetInput((string) $this->input('chat_id', ''));
            $payload['chat_id'] = $legacyChatId;
            if (! $this->filled('group_id')) {
                $payload['group_id'] = $legacyChatId;
            }
        }

        $this->merge($payload);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bot_token.regex' => 'Bot token test không hợp lệ.',
            'group_id.regex' => 'Group ID test không hợp lệ. Dùng số (vd: -100...) hoặc @username.',
            'chat_id.regex' => 'Chat ID test không hợp lệ. Dùng số (vd: -100...) hoặc @username.',
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
