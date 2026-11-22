<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkReclassifyActivitiesRequest extends FormRequest
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
            'activity_ids' => ['required', 'array', 'min:1'],
            'activity_ids.*' => ['required', 'integer', 'exists:activities,id'],
            'discipline' => ['required', 'string', 'in:road,trail,track,gravel,mtb,ebike,commute,other'],
        ];
    }
}
