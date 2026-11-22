<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartBackfillRequest extends FormRequest
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
            'range' => ['sometimes', 'string', 'in:90d,1y,3y,all,resume'],
        ];
    }
}
