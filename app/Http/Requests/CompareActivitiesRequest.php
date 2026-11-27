<?php

namespace App\Http\Requests;

use App\Services\Activities\ActivityComparisonService;
use Illuminate\Foundation\Http\FormRequest;

class CompareActivitiesRequest extends FormRequest
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
            'ids' => ['nullable', 'array', 'max:'.ActivityComparisonService::MAX_ACTIVITIES],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'align' => ['nullable', 'string', 'in:distance,time'],
        ];
    }

    /**
     * Accept `ids` as a comma-separated string (`?ids=1,2`) or an array (`?ids[]=1&ids[]=2`).
     */
    protected function prepareForValidation(): void
    {
        $ids = $this->query('ids');

        if (is_string($ids)) {
            $this->merge([
                'ids' => array_values(array_filter(
                    array_map(trim(...), explode(',', $ids)),
                    static fn (string $id): bool => $id !== '',
                )),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    public function activityIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->validated('ids') ?? [];

        return array_map(intval(...), $ids);
    }

    /**
     * @return 'distance'|'time'
     */
    public function align(): string
    {
        return $this->validated('align') === 'time' ? 'time' : 'distance';
    }
}
