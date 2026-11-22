<?php

namespace Database\Factories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement([
                'Weekly Running Distance',
                'Monthly Trail Elevation',
                'Annual Road Mileage',
                'Weekly Training Consistency',
            ]),
            'sport' => 'running',
            'metric' => 'distance_km',
            'period' => 'weekly',
            'target_value' => 50.0,
            'year' => null,
            'is_active' => true,
        ];
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => 'weekly',
            'year' => null,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => 'monthly',
            'year' => null,
        ]);
    }

    public function yearly(?int $year = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'period' => 'yearly',
            'year' => $year,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
