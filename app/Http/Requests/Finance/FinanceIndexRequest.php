<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class FinanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'billing_status' => ['nullable', 'string', 'max:50'],
            'payout_status' => ['nullable', 'string', 'max:50'],
            'billings_page' => ['nullable', 'integer', 'min:1'],
            'payouts_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter([
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
            'billing_status' => $this->validated('billing_status'),
            'payout_status' => $this->validated('payout_status'),
            'billings_page' => $this->validated('billings_page'),
            'payouts_page' => $this->validated('payouts_page'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}

