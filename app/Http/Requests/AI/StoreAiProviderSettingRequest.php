<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Services\AI\AiSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProviderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth guard handles user-level.
    }

    public function rules(): array
    {
        $validProviders = array_keys(AiSettingsService::PROVIDER_REGISTRY);

        return [
            'provider_key'        => ['required', Rule::in($validProviders)],
            'api_key'             => ['nullable', 'string', 'max:500'],
            'base_url'            => ['nullable', 'url', 'max:255'],
            'default_model'       => ['nullable', 'string', 'max:100'],
            'label'               => ['nullable', 'string', 'max:150'],
            'status'              => ['sometimes', Rule::in(['enabled', 'disabled'])],
            'capabilities'        => ['sometimes', 'array'],
            'capabilities.*'      => [Rule::in(['text', 'image', 'video'])],
            'token_quota_per_day' => ['nullable', 'integer', 'min:100', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'base_url.url'        => 'Base URL phải là URL hợp lệ (e.g. http://127.0.0.1:8045).',
            'provider_key.in'     => 'Provider không hợp lệ. Các provider được hỗ trợ: ' . implode(', ', array_keys(AiSettingsService::PROVIDER_REGISTRY)) . '.',
        ];
    }
}
