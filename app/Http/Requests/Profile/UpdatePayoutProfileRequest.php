<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePayoutProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'bank_code'           => ['nullable', 'string', 'max:50', 'required_with:bank_name,bank_account_name,bank_account_number'],
            'bank_name'           => ['nullable', 'string', 'max:255', 'required_with:bank_code,bank_account_name,bank_account_number'],
            'bank_account_name'   => ['nullable', 'string', 'max:255', 'required_with:bank_code,bank_name,bank_account_number'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^[0-9]{6,25}$/', 'required_with:bank_code,bank_name,bank_account_name'],
            'tax_id'              => ['nullable', 'string', 'regex:/^[0-9A-Za-z-]{8,20}$/'],
        ];
    }
}
