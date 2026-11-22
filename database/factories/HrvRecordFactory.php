<?php

namespace Database\Factories;

use App\Models\HrvRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HrvRecord>
 */
class HrvRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $balancedLow = 52;
        $balancedUpper = 74;

        return [
            'calendar_date' => fake()->unique()->date('Y-m-d', '2026-11-21'),
            'weekly_avg' => fake()->numberBetween($balancedLow, $balancedUpper),
            'last_night_avg' => fake()->numberBetween(45, 82),
            'last_night_5min_high' => fake()->numberBetween(70, 115),
            'status' => fake()->randomElement(['BALANCED', 'UNBALANCED', 'LOW']),
            'baseline_low_upper' => 46,
            'baseline_balanced_low' => $balancedLow,
            'baseline_balanced_upper' => $balancedUpper,
            'feedback_phrase' => 'HRV_BALANCED_7',
        ];
    }
}
