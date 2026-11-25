<?php

namespace Database\Factories;

use App\Models\HeartRateZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeartRateZone>
 */
class HeartRateZoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'garmin_personal',
            'sport' => fake()->unique()->randomElement(['DEFAULT', 'RUNNING', 'CYCLING']),
            'training_method' => 'HR_RESERVE',
            'max_hr' => 190,
            'resting_hr' => 48,
            'lactate_threshold_hr' => 172,
            'zone1_floor' => 119,
            'zone2_floor' => 133,
            'zone3_floor' => 147,
            'zone4_floor' => 162,
            'zone5_floor' => 176,
        ];
    }
}
