<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\Segment;
use App\Models\SegmentEffort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmentEffort>
 */
class SegmentEffortFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'segment_id' => Segment::factory(),
            'activity_id' => Activity::factory(),
            'started_at_local' => CarbonImmutable::now(),
            'start_index' => 10,
            'end_index' => 190,
            'start_distance_meters' => 1000.0,
            'end_distance_meters' => 2700.0,
            'distance_meters' => 1700.0,
            'elapsed_seconds' => 360.0,
            'moving_seconds' => 360.0,
            'avg_speed' => 4.722,
            'elevation_gain' => 112.0,
            'avg_grade_pct' => 6.59,
            'vam' => 1120.0,
            'avg_hr' => 168,
            'max_hr' => 182,
            'avg_power' => 275.0,
            'w_per_kg' => 3.82,
            'avg_cadence' => 84.0,
            'rank' => 1,
            'is_pr' => true,
        ];
    }
}
