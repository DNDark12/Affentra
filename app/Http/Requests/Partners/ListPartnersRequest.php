<?php

declare(strict_types=1);

namespace App\Http\Requests\Partners;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPartnersRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(['active', 'pending', 'suspended', 'banned', 'rejected'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter([
            'search' => $this->validated('search'),
            'status' => $this->validated('status'),
            'date_from' => $this->validated('date_from'),
            'date_to' => $this->validated('date_to'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}

