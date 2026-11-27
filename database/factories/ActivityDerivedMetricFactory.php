<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityDerivedMetric>
 */
class ActivityDerivedMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'decoupling_pct' => 3.2,
            'decoupling_metric' => 'gap',
            'decoupling_rating' => 'well_coupled',
            'efficiency_factor' => 1.54,
            'first_half_ef' => 1.56,
            'second_half_ef' => 1.51,
            'pacing_strategy' => 'even_split',
            'split_ratio' => 1.005,
            'pace_cv_pct' => 2.4,
            'computed_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
