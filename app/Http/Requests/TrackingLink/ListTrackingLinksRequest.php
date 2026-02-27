<?php

declare(strict_types=1);

namespace App\Http\Requests\TrackingLink;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTrackingLinksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(['all', 'active', 'paused', 'archived', 'inactive', 'high_commission', 'low_cr'])],
            'search' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'preset' => ['nullable', 'string', Rule::in(['top_performing', 'underperforming'])],
            'sort' => ['nullable', 'string', Rule::in(['created_at', 'clicks_count', 'orders_count'])],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
