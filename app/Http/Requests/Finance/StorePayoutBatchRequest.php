<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class StorePayoutBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user()->isPartner();
    }

    public function rules(): array
    {
        return [
            'payout_ids'   => ['required', 'array', 'min:1'],
            'payout_ids.*' => ['integer', 'min:1'],
            'note'         => ['nullable', 'string', 'max:2000'],
        ];
    }
}
