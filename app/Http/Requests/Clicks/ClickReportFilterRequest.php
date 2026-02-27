<?php

declare(strict_types=1);

namespace App\Http\Requests\Clicks;

use Illuminate\Foundation\Http\FormRequest;

class ClickReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // We will use Controller/Service logic or middleware for authorization
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'tracking_link_id' => ['nullable', 'integer', 'exists:tracking_links,id'],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'device_type' => ['nullable', 'string', 'in:desktop,mobile,tablet'],
            'referer_domain' => ['nullable', 'string'],
            'is_bot' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
