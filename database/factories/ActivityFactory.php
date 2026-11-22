<?php

namespace Database\Factories;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAtUtc = CarbonImmutable::instance(fake()->dateTimeBetween('-1 year', 'now'));
        $durationSeconds = (float) fake()->numberBetween(1200, 5400);
        $distanceMeters = fake()->randomFloat(1, 5000, 18000);

        return [
            'garmin_activity_id' => fake()->unique()->numberBetween(10000000000, 99999999999),
            'name' => fake()->randomElement(['Morning Run', 'Tempo Run', 'Easy Aerobic Run', 'Long Run']),
            'activity_type_key' => 'running',
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'discipline_override' => null,
            'is_indoor' => false,
            'started_at_local' => $startedAtUtc->addHours(12),
            'started_at_utc' => $startedAtUtc,
            'location_name' => 'Sample City',
            'start_lat' => 37.7749,
            'start_lng' => -122.4194,
            'distance_meters' => $distanceMeters,
            'duration_seconds' => $durationSeconds,
            'moving_duration_seconds' => max(0.0, $durationSeconds - 30.0),
            'elapsed_duration_seconds' => $durationSeconds + 45.0,
            'elevation_gain' => fake()->randomFloat(1, 20, 350),
            'elevation_loss' => fake()->randomFloat(1, 20, 350),
            'min_elevation' => fake()->randomFloat(1, 0, 50),
            'max_elevation' => fake()->randomFloat(1, 60, 250),
            'avg_speed' => round($distanceMeters / $durationSeconds, 3),
            'max_speed' => round(($distanceMeters / $durationSeconds) * 1.35, 3),
            'calories' => fake()->numberBetween(350, 1200),
            'avg_hr' => fake()->numberBetween(135, 168),
            'max_hr' => fake()->numberBetween(168, 192),
            'avg_cadence' => fake()->randomFloat(1, 168.0, 184.0),
            'max_cadence' => fake()->randomFloat(1, 185.0, 202.0),
            'avg_stride_length' => fake()->randomFloat(2, 1.05, 1.45),
            'steps' => fake()->numberBetween(5000, 18000),
            'aerobic_te' => fake()->randomFloat(1, 2.0, 4.5),
            'anaerobic_te' => fake()->randomFloat(1, 0.0, 3.0),
            'training_load' => fake()->randomFloat(1, 40.0, 220.0),
            'training_effect_label' => 'AEROBIC_BASE',
            'body_battery_diff' => -fake()->numberBetween(8, 30),
            'hr_zone_1_seconds' => fake()->randomFloat(1, 60, 300),
            'hr_zone_2_seconds' => fake()->randomFloat(1, 600, 2400),
            'hr_zone_3_seconds' => fake()->randomFloat(1, 300, 1200),
            'hr_zone_4_seconds' => fake()->randomFloat(1, 0, 900),
            'hr_zone_5_seconds' => fake()->randomFloat(1, 0, 180),
            'avg_power' => null,
            'max_power' => null,
            'normalized_power' => null,
            'ftp' => null,
            'tss' => null,
            'intensity_factor' => null,
            'total_work' => null,
            'left_balance' => null,
            'max_20min_power' => null,
            'power_zone_1_seconds' => null,
            'power_zone_2_seconds' => null,
            'power_zone_3_seconds' => null,
            'power_zone_4_seconds' => null,
            'power_zone_5_seconds' => null,
            'power_zone_6_seconds' => null,
            'power_zone_7_seconds' => null,
            'power_curve' => null,
            'grit' => null,
            'avg_flow' => null,
            'jump_count' => null,
            'unpaved_pct' => null,
            'workout_feel' => null,
            'workout_rpe' => null,
            'begin_stamina' => 100,
            'end_stamina' => fake()->numberBetween(35, 80),
            'is_favorite' => false,
            'is_pr' => false,
            'is_manual' => false,
            'streams_synced_at' => null,
            'raw_summary' => null,
        ];
    }

    public function trailRunning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Trail Run',
            'activity_type_key' => 'trail_running',
            'sport_category' => 'running',
            'sport_discipline' => 'trail',
            'elevation_gain' => fake()->randomFloat(1, 250, 950),
            'unpaved_pct' => fake()->randomFloat(1, 75.0, 100.0),
        ]);
    }

    public function roadBiking(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Road Cycling',
            'activity_type_key' => 'road_biking',
            'sport_category' => 'biking',
            'sport_discipline' => 'road',
            'distance_meters' => fake()->randomFloat(1, 30000, 110000),
            'avg_power' => fake()->randomFloat(1, 180, 260),
            'max_power' => fake()->randomFloat(1, 550, 980),
            'normalized_power' => fake()->randomFloat(1, 200, 280),
            'ftp' => 275.0,
            'tss' => fake()->randomFloat(1, 50, 210),
            'intensity_factor' => fake()->randomFloat(2, 0.68, 0.95),
            'steps' => null,
            'avg_stride_length' => null,
        ]);
    }

    public function gravelCycling(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Gravel Ride',
            'activity_type_key' => 'gravel_cycling',
            'sport_category' => 'biking',
            'sport_discipline' => 'gravel',
            'distance_meters' => fake()->randomFloat(1, 25000, 95000),
            'elevation_gain' => fake()->randomFloat(1, 300, 1200),
            'unpaved_pct' => fake()->randomFloat(1, 55.0, 95.0),
            'steps' => null,
            'avg_stride_length' => null,
        ]);
    }

    public function mountainBiking(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Trail Ride',
            'activity_type_key' => 'mountain_biking',
            'sport_category' => 'biking',
            'sport_discipline' => 'mtb',
            'distance_meters' => fake()->randomFloat(1, 12000, 40000),
            'elevation_gain' => fake()->randomFloat(1, 350, 1400),
            'grit' => fake()->randomFloat(1, 20.0, 95.0),
            'avg_flow' => fake()->randomFloat(1, 2.0, 10.0),
            'jump_count' => fake()->numberBetween(0, 24),
            'unpaved_pct' => fake()->randomFloat(1, 70.0, 99.0),
            'steps' => null,
            'avg_stride_length' => null,
        ]);
    }

    public function otherBiking(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Cycling',
            'activity_type_key' => 'cycling',
            'sport_category' => 'biking',
            'sport_discipline' => 'other',
            'steps' => null,
            'avg_stride_length' => null,
        ]);
    }

    public function hiking(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Mountain Hike',
            'activity_type_key' => 'hiking',
            'sport_category' => 'hiking',
            'sport_discipline' => null,
            'elevation_gain' => fake()->randomFloat(1, 350, 1200),
        ]);
    }

    public function swimming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Pool Swim',
            'activity_type_key' => 'lap_swimming',
            'sport_category' => 'swimming',
            'sport_discipline' => null,
            'is_indoor' => true,
            'start_lat' => null,
            'start_lng' => null,
            'distance_meters' => fake()->randomFloat(1, 1000, 3500),
            'elevation_gain' => null,
            'steps' => null,
        ]);
    }

    public function indoor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_indoor' => true,
            'start_lat' => null,
            'start_lng' => null,
        ]);
    }
}
