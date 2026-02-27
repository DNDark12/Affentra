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
            'app_id'     => ['sometimes', 'required_if:method,open_api', 'nullable', 'string', 'max:255'],
            'app_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cookie_header' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->input('method') === 'cookie' && blank($this->input('curl_command'))),
            ],
            'curl_command' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->input('method') === 'cookie' && blank($this->input('cookie_header'))),
            ],
            'consent_acknowledged' => ['nullable', 'boolean', 'accepted_if:method,cookie'],
            'sync_mode'  => ['sometimes', 'required', 'string', Rule::in(['manual', 'scheduled'])],
            'status'     => ['sometimes', 'required', 'string', Rule::in(['active', 'inactive', 'error', 'disabled', 'expired'])],
        ];
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
