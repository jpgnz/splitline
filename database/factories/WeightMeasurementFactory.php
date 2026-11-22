<?php

namespace Database\Factories;

use App\Models\WeightMeasurement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeightMeasurement>
 */
class WeightMeasurementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $calendarDate = fake()->date('Y-m-d', '2026-11-21');
        $weightGrams = fake()->numberBetween(65000, 85000);

        return [
            'garmin_sample_pk' => fake()->unique()->numberBetween(1000000000, 9999999999),
            'calendar_date' => $calendarDate,
            'measured_at' => CarbonImmutable::parse($calendarDate.' 07:15:00'),
            'weight_grams' => $weightGrams,
            'bmi' => fake()->randomFloat(1, 20.0, 26.0),
            'body_fat_pct' => fake()->randomFloat(1, 10.0, 22.0),
            'body_water_pct' => fake()->randomFloat(1, 55.0, 66.0),
            'bone_mass_grams' => fake()->numberBetween(2800, 3800),
            'muscle_mass_grams' => fake()->numberBetween(32000, 44000),
            'source_type' => 'INDEX_SCALE',
        ];
    }
}
