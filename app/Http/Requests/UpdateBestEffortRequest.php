<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBestEffortRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'override_seconds' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:86400'],
            'is_excluded' => ['sometimes', 'boolean'],
        ];
    }
}
