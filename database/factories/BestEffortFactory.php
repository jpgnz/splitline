<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\BestEffort;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BestEffort>
 */
class BestEffortFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $elapsedSeconds = fake()->randomFloat(1, 1080.0, 1620.0);

        return [
            'activity_id' => Activity::factory(),
            'sport' => 'running',
            'distance_key' => '5k',
            'distance_meters' => 5000.0,
            'elapsed_seconds' => $elapsedSeconds,
            'moving_seconds' => max(0.0, $elapsedSeconds - 5.0),
            'start_offset_seconds' => fake()->randomFloat(1, 0.0, 600.0),
            'achieved_at' => CarbonImmutable::instance(fake()->dateTimeBetween('-1 year', 'now')),
            'source' => 'fit_stream',
            'override_seconds' => null,
            'is_excluded' => false,
        ];
    }

    public function excluded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_excluded' => true,
        ]);
    }
}
