<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'goal_amount'  => ['nullable', 'numeric', 'min:0'],
            'platform'     => ['nullable', 'string', Rule::in(array_column(Platform::cases(), 'value'))],
            'date_start'   => ['nullable', 'date'],
            'date_end'     => ['nullable', 'date', 'after:date_start'],
        ];
    }
}
