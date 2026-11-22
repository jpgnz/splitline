<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignActivityGearRequest extends FormRequest
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
            'gear_item_id' => ['nullable', 'integer', 'exists:gear_items,id'],
        ];
    }
}
