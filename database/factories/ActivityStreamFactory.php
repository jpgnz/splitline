<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityStream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityStream>
 */
class ActivityStreamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_id' => Activity::factory(),
            'series' => [
                'time' => [0, 60, 120],
                'distance' => [0.0, 250.0, 500.0],
                'altitude' => [15.0, 18.5, 22.0],
                'speed' => [4.1, 4.2, 4.15],
                'hr' => [138, 148, 152],
                'cadence' => [174, 176, 176],
                'power' => [null, null, null],
                'grade' => [0.0, 1.4, 1.4],
            ],
            'polyline' => [
                [-36.8485, 174.7633],
                [-36.8470, 174.7650],
                [-36.8455, 174.7668],
            ],
            'laps' => [],
            'km_splits' => [],
        ];
    }
}
