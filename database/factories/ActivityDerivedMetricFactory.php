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
            'hr_low_pct' => 72.0,
            'hr_moderate_pct' => 20.0,
            'hr_high_pct' => 8.0,
            'end_lat' => -36.8485,
            'end_lng' => 174.7633,
            'start_to_end_meters' => 120.0,
            'is_loop' => true,
            'bbox_min_lat' => -36.8600,
            'bbox_max_lat' => -36.8450,
            'bbox_min_lng' => 174.7600,
            'bbox_max_lng' => 174.7800,
            'route_fingerprint' => [
                [-36.8485, 174.7633],
                [-36.8510, 174.7680],
                [-36.8540, 174.7720],
                [-36.8570, 174.7760],
                [-36.8590, 174.7780],
                [-36.8580, 174.7740],
                [-36.8550, 174.7700],
                [-36.8520, 174.7660],
                [-36.8500, 174.7640],
                [-36.8486, 174.7634],
            ],
            'computed_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
