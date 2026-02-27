<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class UploadPortalExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', \Illuminate\Validation\Rule::in(['conversion', 'click', 'offer'])],
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:20480'], // 20MB max
        ];
    }
}
