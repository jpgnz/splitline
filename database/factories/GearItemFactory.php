<?php

namespace Database\Factories;

use App\Models\GearItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GearItem>
 */
class GearItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'ASICS Novablast 4',
                'Nike Pegasus 41',
                'Hoka Mach 6',
                'Saucony Endorphin Speed 4',
            ]),
            'brand' => fake()->randomElement(['ASICS', 'Nike', 'Hoka', 'Saucony']),
            'model' => fake()->randomElement(['Novablast 4', 'Pegasus 41', 'Mach 6', 'Endorphin Speed 4']),
            'type' => 'running_shoe',
            'default_sport' => null,
            'initial_distance_meters' => 0.0,
            'max_distance_meters' => 650000.0,
            'purchased_at' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'retired_at' => null,
            'notes' => null,
        ];
    }

    public function runningShoe(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'ASICS Novablast 4',
            'brand' => 'ASICS',
            'model' => 'Novablast 4',
            'type' => 'running_shoe',
            'max_distance_meters' => 650000.0,
        ]);
    }

    public function roadBike(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Canyon Aeroad CF SLX',
            'brand' => 'Canyon',
            'model' => 'Aeroad CF SLX',
            'type' => 'road_bike',
            'max_distance_meters' => 3000000.0,
        ]);
    }

    public function mtbBike(): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Santa Cruz Tallboy',
            'brand' => 'Santa Cruz',
            'model' => 'Tallboy CC',
            'type' => 'mtb_bike',
            'max_distance_meters' => 2500000.0,
        ]);
    }

    public function defaultFor(string $sport): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_sport' => $sport,
        ]);
    }

    public function retired(?string $date = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'retired_at' => $date ?? '2026-10-01',
            'default_sport' => null,
        ]);
    }
}
