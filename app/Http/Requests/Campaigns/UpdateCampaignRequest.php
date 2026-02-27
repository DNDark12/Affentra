<?php

declare(strict_types=1);

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'goal_amount' => ['nullable', 'numeric', 'min:0'],
            'status'      => ['sometimes', Rule::in(array_column(CampaignStatus::cases(), 'value'))],
            'date_start'  => ['nullable', 'date'],
            'date_end'    => ['nullable', 'date'],
        ];
    }
}
