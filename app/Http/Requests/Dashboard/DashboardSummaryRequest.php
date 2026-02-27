<?php

declare(strict_types=1);

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardSummaryRequest extends FormRequest
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
            'period' => ['nullable', 'string', 'in:7days,30days,90days'],
        ];
    }

    public function period(): string
    {
        return (string) ($this->validated('period') ?? '30days');
    }
}
