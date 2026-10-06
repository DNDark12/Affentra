<?php

declare(strict_types=1);

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'method'     => ['sometimes', 'required', 'string', Rule::in($this->allowedMethods())],
            'label'      => ['nullable', 'string', 'max:100'],
            'app_id'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'app_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cookie_header' => ['nullable', 'string'],
            'curl_command' => ['nullable', 'string'],
            'consent_acknowledged' => ['nullable', 'boolean', 'accepted_if:method,cookie'],
            'sync_mode'  => ['sometimes', 'required', 'string', Rule::in(['manual', 'scheduled'])],
            'sync_interval' => ['nullable', 'string', Rule::requiredIf(fn (): bool => $this->input('sync_mode') === 'scheduled'), Rule::in(['15m', '1h', '3h', '8h', 'daily'])],
            'sync_time' => ['nullable', 'string', 'date_format:H:i', Rule::requiredIf(fn (): bool => $this->input('sync_mode') === 'scheduled' && $this->input('sync_interval') === 'daily')],
            'status'     => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive', 'error', 'disabled', 'expired', 'needs_revalidation'])],
        ];
    }

    /**
     * Add platform-method cross-validation.
     * Reads platform from route model, not from payload.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Contracts\Validation\Validator $v): void {
            $connection = $this->route('connection');
            $platform   = $connection?->platform;
            $method     = $this->input('method');

            if ($platform === 'tiktok' && $method !== null && $method !== 'open_api') {
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
