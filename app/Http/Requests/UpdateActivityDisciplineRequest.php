<?php

namespace App\Http\Requests;

use App\Models\Activity;
use App\Support\SportRegistry;
use Illuminate\Foundation\Http\FormRequest;

class UpdateActivityDisciplineRequest extends FormRequest
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
        $routeActivity = $this->route('activity');
        $allowed = $routeActivity instanceof Activity
            ? $sports->validDisciplineValuesForCategory($routeActivity->sport_category)
            : ['road', 'trail', 'track', 'gravel', 'mtb', 'ebike', 'commute', 'other'];

        if ($allowed === []) {
            $allowed = ['road', 'trail', 'track', 'gravel', 'mtb', 'ebike', 'commute', 'other'];
        }

        return [
            'discipline_override' => ['nullable', 'string', 'in:'.implode(',', $allowed)],
        ];
    }
}
