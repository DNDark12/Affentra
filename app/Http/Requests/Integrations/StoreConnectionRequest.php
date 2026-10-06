<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use App\Services\Integration\IntegrationFactory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'platform'   => ['required', 'string', Rule::in(IntegrationFactory::supportedPlatforms())],
            'method'     => ['required', 'string', Rule::in($this->allowedMethods())],
            'label'      => ['nullable', 'string', 'max:100'],
            
            // Open API Rules — TikTok uses global OAuth credentials, not per-connection app_id/app_secret
            'app_id'     => [
                Rule::requiredIf(fn (): bool => $this->input('method') === 'open_api' && $this->input('platform') !== 'tiktok'),
                'nullable', 'string', 'max:255',
            ],
            'app_secret' => [
                Rule::requiredIf(fn (): bool => $this->input('method') === 'open_api' && $this->input('platform') !== 'tiktok'),
                'nullable', 'string', 'max:255',
            ],

            // Cookie Rules
            'cookie_header'        => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->input('method') === 'cookie' && blank($this->input('curl_command'))),
            ],
            'curl_command'         => [
                'nullable',
                'string',
            ],
            'consent_acknowledged' => ['nullable', 'boolean', 'accepted_if:method,cookie'],
            'sync_mode'            => ['sometimes', 'required', 'string', Rule::in(['manual', 'scheduled'])],
            'sync_interval'        => ['nullable', 'string', Rule::requiredIf(fn (): bool => $this->input('sync_mode') === 'scheduled'), Rule::in(['15m', '1h', '3h', '8h', 'daily'])],
            'sync_time'            => ['nullable', 'string', 'date_format:H:i', Rule::requiredIf(fn (): bool => $this->input('sync_mode') === 'scheduled' && $this->input('sync_interval') === 'daily')],
        ];
    }

    /**
     * Add platform-method cross-validation.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v): void {
            $platform = $this->input('platform');
            $method   = $this->input('method');

            // TikTok only supports open_api (OAuth-based, global credentials)
            if ($platform === 'tiktok' && $method !== 'open_api') {
                $v->errors()->add('method', 'TikTok chỉ hỗ trợ phương thức Open API.');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function allowedMethods(): array
    {
        $methods = ['open_api', 'portal_export'];

        if ($this->canUseCookieMethod()) {
            $methods[] = 'cookie';
        }

        return $methods;
    }

    private function canUseCookieMethod(): bool
    {
        $user = $this->user();

        return (bool) config('integrations.enable_cookie_method', false)
            && $user !== null
            && ($user->isOwner() || $user->isLeader());
    }
}
