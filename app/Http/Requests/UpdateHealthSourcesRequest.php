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
     * Each domain accepts 'auto', a provider detected in that domain's data, or the currently saved selection.
     *
     * @return array<string, list<string>>
     */
    public function rules(HealthSourceRegistry $healthSources): array
    {
        $rules = [
            'sources' => ['required', 'array'],
        ];

        foreach (HealthSourceRegistry::domainKeys() as $domain) {
            $allowed = array_values(array_unique([
                HealthSourceRegistry::MODE_AUTO,
                $healthSources->configuredSelection($domain),
                ...$healthSources->availableProviders($domain),
            ]));

            $rules["sources.{$domain}"] = ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/', 'in:'.implode(',', $allowed)];
        }

        return $rules;
    }
}
