<?php

declare(strict_types=1);

namespace App\Http\Requests\TrackingLink;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTrackingLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = (int) $this->route('trackingLink');

        return [
            'destination_url' => ['sometimes', 'required', 'url', 'max:2048'],
            'campaign_id'     => ['nullable', 'integer', 'exists:campaigns,id'],
            'short_code'      => ['nullable', 'alpha_dash', 'max:20', Rule::unique('tracking_links', 'short_code')->ignore($id)],
            'platform'        => ['nullable', 'string', 'max:50'],
            'channel'         => ['nullable', 'string', 'max:100'],
            'source'          => ['nullable', 'string', 'max:100'],
            'sub_id'          => ['nullable', 'string', 'max:100'],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string', 'max:50'],
            'status'          => ['sometimes', 'required', Rule::in(['active', 'paused', 'archived', 'inactive'])],
            'product_name'    => ['nullable', 'string', 'max:1000'],
            'product_image_urls'   => ['nullable', 'array'],
            'product_image_urls.*' => ['string', 'url', 'max:2048'],
        ];
    }
}
