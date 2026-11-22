<?php

namespace App\Http\Requests;

use App\Support\SportRegistry;
use Illuminate\Foundation\Http\FormRequest;

class StoreGearItemRequest extends FormRequest
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
    public function rules(SportRegistry $sports): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'type' => ['required', 'string', 'in:'.implode(',', $sports->validGearTypes())],
            'default_sport' => ['nullable', 'string', 'in:'.implode(',', $sports->validGearDefaultSports())],
            'initial_distance_km' => ['nullable', 'numeric', 'min:0', 'max:200000'],
            'max_distance_km' => ['nullable', 'numeric', 'min:1', 'max:500000'],
            'purchased_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
