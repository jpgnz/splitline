<?php

namespace App\Http\Requests;

use App\Models\Goal;
use Illuminate\Foundation\Http\FormRequest;

class StoreGoalRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:120'],
            'sport' => ['required', 'string', 'in:'.implode(',', Goal::VALID_SPORTS)],
            'metric' => ['required', 'string', 'in:'.implode(',', Goal::VALID_METRICS)],
            'period' => ['required', 'string', 'in:'.implode(',', Goal::VALID_PERIODS)],
            'target_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
