<?php

declare(strict_types=1);

namespace App\Http\Requests\Offers;

use Illuminate\Foundation\Http\FormRequest;

class SearchOffersRequest extends FormRequest
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
            'connection_id' => ['required', 'integer'],
            'keyword'       => ['nullable', 'string', 'max:200'],
            'search_type'   => ['nullable', 'string', 'in:detail,keyword'],
            'listType'      => ['nullable', 'integer'],
            'sortType'      => ['nullable', 'integer'],
            'page'          => ['nullable', 'integer', 'min:1', 'max:200'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:50'],
            'shopId'        => ['nullable', 'integer'],
            'itemId'        => ['nullable', 'integer'],
            'productCatId'  => ['nullable', 'integer'],
        ];
    }
}
