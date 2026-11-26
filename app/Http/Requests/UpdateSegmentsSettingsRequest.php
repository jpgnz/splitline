<?php

namespace App\Http\Requests;

use App\Support\AppSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSegmentsSettingsRequest extends FormRequest
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
            'min_efforts' => [
                'required',
                'integer',
                'min:'.AppSettings::MIN_SEGMENTS_MIN_EFFORTS,
                'max:'.AppSettings::MAX_SEGMENTS_MIN_EFFORTS,
            ],
        ];
    }
}
