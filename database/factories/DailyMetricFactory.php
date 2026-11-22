<?php

namespace Database\Factories;

use App\Models\DailyMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyMetric>
 */
class DailyMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $restingHr = fake()->numberBetween(44, 62);
        $activeCalories = fake()->numberBetween(300, 1200);
        $bmrCalories = fake()->numberBetween(1600, 2000);

        return [
            'calendar_date' => fake()->unique()->date('Y-m-d', '2026-11-21'),
            'day_duration_seconds' => 86400,
            'steps' => fake()->numberBetween(4000, 18000),
            'step_goal' => 10000,
            'distance_meters' => fake()->randomFloat(1, 3000, 16000),
            'total_calories' => $bmrCalories + $activeCalories,
            'active_calories' => $activeCalories,
            'bmr_calories' => $bmrCalories,
            'resting_hr' => $restingHr,
            'seven_day_avg_rhr' => $restingHr + fake()->numberBetween(-2, 2),
            'min_hr' => $restingHr - fake()->numberBetween(1, 5),
            'max_hr' => fake()->numberBetween(140, 188),
            'avg_stress' => fake()->numberBetween(18, 45),
            'max_stress' => fake()->numberBetween(65, 95),
            'stress_qualifier' => fake()->randomElement(['calm', 'balanced', 'stressful']),
            'rest_stress_seconds' => fake()->numberBetween(25000, 45000),
            'low_stress_seconds' => fake()->numberBetween(8000, 20000),
            'medium_stress_seconds' => fake()->numberBetween(2000, 8000),
            'high_stress_seconds' => fake()->numberBetween(0, 3000),
            'body_battery_current' => fake()->numberBetween(20, 95),
            'body_battery_high' => fake()->numberBetween(75, 100),
            'body_battery_low' => fake()->numberBetween(5, 35),
            'body_battery_charged' => fake()->numberBetween(40, 85),
            'body_battery_drained' => fake()->numberBetween(35, 80),
            'body_battery_sleep' => fake()->numberBetween(30, 75),
            'moderate_intensity_minutes' => fake()->numberBetween(0, 90),
            'vigorous_intensity_minutes' => fake()->numberBetween(0, 60),
            'intensity_minutes_goal' => 150,
            'floors_ascended' => fake()->randomFloat(1, 2, 25),
            'floors_descended' => fake()->randomFloat(1, 2, 25),
            'floors_goal' => 10,
            'avg_respiration' => fake()->randomFloat(1, 12.0, 17.5),
            'min_respiration' => fake()->randomFloat(1, 10.0, 13.0),
            'max_respiration' => fake()->randomFloat(1, 18.0, 24.0),
            'avg_spo2' => fake()->randomFloat(1, 95.0, 99.5),
            'lowest_spo2' => fake()->randomFloat(1, 90.0, 96.0),
            'latest_spo2' => fake()->randomFloat(1, 95.0, 99.5),
            'hydration_ml' => null,
            'heart_rate_series' => null,
            'body_battery_series' => null,
            'raw_payload' => null,
        ];
    }
}
