<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preset_id'       => ['required', 'string', 'max:50'], // E.g., fb_post
            'provider_key'    => ['nullable', 'string', 'max:50'], // E.g., gemini, openai. Null means auto-fallback
            'model'           => ['nullable', 'string', 'max:50'], // E.g., gemini-1.5-pro, gpt-4o
            'variant_count'   => ['nullable', 'integer', 'min:1', 'max:5'],
            'force_new_seed'  => ['boolean'],
            
            // Image fetching
            'image_urls'      => ['nullable', 'array', 'max:5'],
            'image_urls.*'    => ['url', 'max:2048'],

            // Advanced Options
            'options'                => ['sometimes', 'array'],
            'options.goal'           => ['nullable', 'string', 'max:50'],
            'options.product_title'  => ['nullable', 'string', 'max:300'],
            'options.product_price'  => ['nullable', 'string', 'max:100'],
            'options.landing_link'   => ['nullable', 'string', 'max:1000'],
            'options.audience'       => ['nullable', 'string', 'max:200'],
            'options.tone'           => ['nullable', 'string', 'max:100'],
            'options.usp'            => ['nullable', 'string', 'max:2000'],
            'options.offers'         => ['nullable', 'string', 'max:2000'],
            'options.expiration'     => ['nullable', 'string', 'max:200'],
            'options.policy'         => ['nullable', 'string', 'max:2000'],
            'options.duration'       => [
                'nullable', 
                'integer', 
                'min:' . config('ai.providers.seedance.video_duration.min', 4),
                'max:' . config('ai.providers.seedance.video_duration.max', 12)
            ],
            'options.duration_sec'   => ['nullable', 'integer', 'min:4', 'max:30'],
            'options.aspect_ratio'   => ['nullable', 'string', 'max:20'],
            'options.headline'       => ['nullable', 'string', 'max:200'],
            'options.cta_text'       => ['nullable', 'string', 'max:120'],
            'options.visual_style'   => ['nullable', 'string', 'max:300'],
            'options.length'         => ['nullable', 'string', Rule::in(['short', 'medium', 'long'])],
            'options.custom_prompt'  => ['nullable', 'string', 'max:1000'],
            
            // Safety Toggles
            'options.safety_no_absolute' => ['boolean'],
            'options.safety_no_medical'  => ['boolean'],
            'options.safety_no_sensitive'=> ['boolean'],

            // Output type toggles (multi-select based on provider capabilities)
            'generate_text'   => ['boolean'],
            'generate_image'  => ['boolean'],
            'generate_video'  => ['boolean'],
        ];
    }
}
