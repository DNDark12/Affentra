<?php

declare(strict_types=1);

namespace App\Http\Requests\Alerts;

use Illuminate\Foundation\Http\FormRequest;

class AddIncidentCommentRequest extends FormRequest
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
            'comment' => ['required', 'string', 'min:2', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'comment' => trim((string) $this->input('comment', '')),
        ]);
    }
}

