<?php

namespace App\Http\Requests;

use App\Support\HealthSourceRegistry;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHealthSourcesRequest extends FormRequest
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
        $rules = [
            'sources' => ['required', 'array'],
        ];

        foreach (HealthSourceRegistry::domainKeys() as $domain) {
            $rules["sources.{$domain}"] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'];
        }

        return $rules;
    }
}
