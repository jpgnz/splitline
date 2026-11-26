<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSegmentRequest extends FormRequest
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
            'activity_id' => ['required', 'integer', 'exists:activities,id'],
            'start_index' => ['required', 'integer', 'min:0'],
            'end_index' => ['required', 'integer', 'gt:start_index'],
            'name' => ['nullable', 'string', 'max:160'],
        ];
    }
}
