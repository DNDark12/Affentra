<?php

declare(strict_types=1);

namespace App\Http\Requests\TrackingLink;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTrackingLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'destination_url' => ['required', 'url', 'max:2048'],
            'campaign_id'     => ['nullable', 'integer', 'exists:campaigns,id'],
            'short_code'      => ['nullable', 'alpha_dash', 'max:20', 'unique:tracking_links,short_code'],
            'platform'        => ['nullable', 'string', Rule::in(array_map(static fn (Platform $platform): string => $platform->value, Platform::cases()))],
            'channel'         => ['nullable', 'string', 'max:100'],
            'source'          => ['nullable', 'string', 'max:100'],
            'sub_id'          => ['nullable', 'string', 'max:100'],
            'tags'            => ['nullable', 'array'],
            'tags.*'          => ['string', 'max:50'],
        ];
    }
}
