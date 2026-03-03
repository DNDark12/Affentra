<?php

declare(strict_types=1);

namespace App\Http\Requests\Offers;

use Illuminate\Foundation\Http\FormRequest;

class GetOfferLinkRequest extends FormRequest
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
            'connection_id'   => ['required', 'integer'],
            'campaign_id'     => ['nullable', 'integer', 'exists:campaigns,id'],
            'offer_link'      => ['required', 'url', 'max:2048'],
            'item_id'         => ['required', 'string', 'max:100'],
            'shop_id'         => ['nullable', 'string', 'max:100'],
            'product_name'    => ['required', 'string', 'max:500'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image_url'       => ['nullable', 'url', 'max:2048'],
        ];
    }
}
