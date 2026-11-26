<?php

namespace Database\Factories;

use App\Models\SleepRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SleepRecord>
 */
class SleepRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $calendarDate = fake()->unique()->date('Y-m-d', '2026-11-21');
        $deepSeconds = fake()->numberBetween(3600, 7200);
        $remSeconds = fake()->numberBetween(4200, 7800);
        $lightSeconds = fake()->numberBetween(12600, 18000);
        $sleepSeconds = $deepSeconds + $remSeconds + $lightSeconds;
        $sleepEnd = CarbonImmutable::parse($calendarDate.' 06:45:00');
        $sleepStart = $sleepEnd->subSeconds($sleepSeconds);

        return [
            'provider' => 'garmin_personal',
            'calendar_date' => $calendarDate,
            'sleep_seconds' => $sleepSeconds,
            'deep_seconds' => $deepSeconds,
            'light_seconds' => $lightSeconds,
            'rem_seconds' => $remSeconds,
            'awake_seconds' => fake()->numberBetween(300, 1800),
            'nap_seconds' => 0,
            'sleep_start_local' => $sleepStart,
            'sleep_end_local' => $sleepEnd,
            'sleep_score' => fake()->numberBetween(65, 95),
            'sleep_need_minutes' => 480,
            'next_sleep_need_minutes' => 470,
            'raw_payload' => null,
        ];
    }
}
