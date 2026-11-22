<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSportsSettingsRequest extends FormRequest
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
            'mode' => ['required', 'string', 'in:auto,custom'],
            'pin_sub_disciplines' => ['sometimes', 'boolean'],
            'order' => ['sometimes', 'array'],
            'order.*' => ['string', 'in:running,cycling,hiking,walking,swimming,cardio'],
            'families' => ['sometimes', 'array'],
            'families.running' => ['sometimes', 'array'],
            'families.running.mode' => ['sometimes', 'string', 'in:off,unified,split'],
            'families.running.disciplines' => ['sometimes', 'array'],
            'families.running.disciplines.*' => ['string', 'in:road_running,trail_running,track_running,treadmill_running'],
            'families.cycling' => ['sometimes', 'array'],
            'families.cycling.mode' => ['sometimes', 'string', 'in:off,unified,split'],
            'families.cycling.disciplines' => ['sometimes', 'array'],
            'families.cycling.disciplines.*' => ['string', 'in:road_cycling,gravel_cycling,mtb_cycling,indoor_cycling,ebike_cycling,commute_cycling'],
            'secondary' => ['sometimes', 'array'],
            'secondary.*' => ['string', 'in:hiking,walking,swimming,cardio'],
            'rollups' => ['sometimes', 'array'],
            'rollups.trail_running' => ['sometimes', 'string', 'in:own,running'],
            'rollups.track_running' => ['sometimes', 'string', 'in:road_running,own'],
            'rollups.gravel_cycling' => ['sometimes', 'string', 'in:own,road_cycling,mtb_cycling'],
            'rollups.indoor_cycling' => ['sometimes', 'string', 'in:road_cycling,own'],
            'rollups.ebike_cycling' => ['sometimes', 'string', 'in:own,mtb_cycling,exclude'],
            'best_efforts' => ['sometimes', 'array'],
            'best_efforts.trail_in_running_prs' => ['sometimes', 'boolean'],
            'best_efforts.cycling_disciplines' => ['sometimes', 'array'],
            'best_efforts.cycling_disciplines.*' => ['string', 'in:road,gravel,mtb,ebike'],
        ];
    }
}
