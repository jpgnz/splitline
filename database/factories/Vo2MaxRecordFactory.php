<?php

namespace Database\Factories;

use App\Models\Vo2MaxRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vo2MaxRecord>
 */
class Vo2MaxRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vo2MaxPrecise = fake()->randomFloat(1, 45.0, 65.0);

        return [
            'calendar_date' => fake()->unique()->date('Y-m-d', '2026-11-21'),
            'sport' => fake()->randomElement(['running', 'cycling']),
            'vo2_max_precise' => $vo2MaxPrecise,
            'vo2_max' => round($vo2MaxPrecise),
            'sub_sport' => null,
        ];
    }
}
