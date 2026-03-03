<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Services\AI\PromptTemplateRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Scope authorization is handled by the controller's route model binding.
        return true;
    }

    public function rules(): array
    {
        /** @var PromptTemplateRegistry $registry */
        $registry       = app(PromptTemplateRegistry::class);
        $validPresets   = $registry->listFor($this->input('type', 'text'));

        return [
            'type'            => ['required', Rule::in(['text', 'image', 'video'])],
            'platform'        => ['required', Rule::in(['facebook', 'tiktok', 'instagram', 'generic'])],
            'preset'          => ['required', Rule::in($validPresets)],
            'force_new_seed'  => ['boolean'],

            // Options sub-object
            'options'                => ['sometimes', 'array'],
            'options.tone'           => ['sometimes', Rule::in(['friendly', 'professional', 'hype', 'minimalist'])],
            'options.goal'           => ['sometimes', Rule::in(['traffic', 'conversion', 'remarketing'])],
            'options.audience'       => ['sometimes', 'string', 'max:200'],
            'options.variant_count'  => ['sometimes', 'integer', 'min:1', 'max:10'],
            'options.product_title'  => ['sometimes', 'string', 'max:300'],
            'options.product_price'  => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'preset.in'   => 'Preset không hợp lệ cho loại nội dung này.',
            'type.in'     => 'Loại nội dung phải là text, image hoặc video.',
            'platform.in' => 'Platform không hợp lệ.',
        ];
    }
}
