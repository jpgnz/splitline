<?php

namespace Database\Factories;

use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = CarbonImmutable::now()->subMinutes(2);

        return [
            'type' => 'incremental',
            'status' => 'completed',
            'started_at' => $startedAt,
            'finished_at' => $startedAt->addSeconds(25),
            'cursor_date' => null,
            'items_synced' => fake()->numberBetween(1, 50),
            'error_message' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'running',
            'finished_at' => null,
            'items_synced' => 0,
            'error_message' => null,
        ]);
    }

    public function failed(string $message = 'Garmin API request failed.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'failed',
            'finished_at' => CarbonImmutable::now(),
            'error_message' => $message,
        ]);
    }
}
