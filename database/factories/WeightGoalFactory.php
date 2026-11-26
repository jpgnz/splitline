<?php

namespace Database\Factories;

use App\Models\WeightGoal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightGoal>
 */
class WeightGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->unique()->date('Y-m-d', '2026-11-21');

        return [
            'provider' => 'garmin_personal',
            'start_date' => $startDate,
            'end_date' => null,
            'goal_grams' => fake()->numberBetween(70000, 90000),
            'source_category' => 'MANUAL',
            'garmin_updated_at' => CarbonImmutable::parse($startDate.' 08:00:00'),
        ];
    }
}
