<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class ImportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only Owner or Leader can import orders
        $user = $this->user();

        return $user !== null && ($user->isOwner() || $user->isLeader());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file'     => ['required', 'file', 'mimes:csv,txt', 'max:10240'], // 10 MB
            'platform' => ['sometimes', 'string', 'in:shopee,lazada,tiktok,other'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Vui lòng chọn file CSV.',
            'file.mimes'    => 'Chỉ hỗ trợ file CSV.',
            'file.max'      => 'File tối đa 10 MB.',
        ];
    }
}
