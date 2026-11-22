<?php

namespace Database\Factories;

use App\Models\FitnessAgeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FitnessAgeRecord>
 */
class FitnessAgeRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $chronologicalAge = fake()->randomFloat(1, 25.0, 50.0);
        $fitnessAge = round(max(18.0, $chronologicalAge - fake()->randomFloat(1, 1.0, 8.0)), 1);

        return [
            'calendar_date' => fake()->unique()->date('Y-m-d', '2026-11-21'),
            'chronological_age' => $chronologicalAge,
            'fitness_age' => $fitnessAge,
            'previous_fitness_age' => round($fitnessAge + fake()->randomFloat(1, -0.5, 0.5), 1),
            'components' => [
                'rhr' => ['value' => 48, 'target' => 50],
                'bodyFat' => ['value' => 14.5, 'target' => 15.0],
            ],
        ];
    }
}
