<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityWeather;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityWeather>
 */
class ActivityWeatherFactory extends Factory
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
            'provider' => 'open_meteo',
            'model_endpoint' => 'forecast',
            'sampling_mode' => 'route',
            'waypoint_count' => 2,
            'wmo_code' => 2,
            'condition' => 'Partly Cloudy',
            'base_condition' => 'Partly Cloudy',
            'icon' => 'cloud-sun',
            'temperature_c' => 16.2,
            'min_temperature_c' => 15.8,
            'max_temperature_c' => 16.6,
            'apparent_temperature_c' => 14.9,
            'humidity_pct' => 68,
            'dew_point_c' => 10.3,
            'wind_speed_kmh' => 12.4,
            'wind_gusts_kmh' => 21.0,
            'wind_direction_deg' => 45,
            'wind_direction_compass' => 'NE',
            'precipitation_mm' => 0.0,
            'cloud_cover_pct' => 42,
            'surface_pressure_hpa' => 1015.4,
            'headwind_pct' => 40.0,
            'crosswind_pct' => 30.0,
            'tailwind_pct' => 30.0,
            'avg_effective_wind_kmh' => -1.5,
            'samples' => [
                [
                    'time_offset_s' => 0,
                    'distance_m' => 0.0,
                    'lat' => -36.8485,
                    'lng' => 174.7633,
                    'wmo_code' => 2,
                    'temperature_c' => 15.8,
                    'apparent_temperature_c' => 14.5,
                    'humidity_pct' => 70,
                    'dew_point_c' => 10.3,
                    'wind_speed_kmh' => 12.0,
                    'wind_gusts_kmh' => 19.5,
                    'wind_direction_deg' => 44,
                    'wind_direction_compass' => 'NE',
                    'precipitation_mm' => 0.0,
                    'cloud_cover_pct' => 40,
                    'surface_pressure_hpa' => 1015.5,
                ],
                [
                    'time_offset_s' => 1800,
                    'distance_m' => 5000.0,
                    'lat' => -36.8200,
                    'lng' => 174.7900,
                    'wmo_code' => 2,
                    'temperature_c' => 16.6,
                    'apparent_temperature_c' => 15.3,
                    'humidity_pct' => 66,
                    'dew_point_c' => 10.3,
                    'wind_speed_kmh' => 12.8,
                    'wind_gusts_kmh' => 21.0,
                    'wind_direction_deg' => 46,
                    'wind_direction_compass' => 'NE',
                    'precipitation_mm' => 0.0,
                    'cloud_cover_pct' => 44,
                    'surface_pressure_hpa' => 1015.3,
                ],
            ],
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    public function startPoint(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sampling_mode' => 'start_point',
            'waypoint_count' => 1,
            'headwind_pct' => null,
            'crosswind_pct' => null,
            'tailwind_pct' => null,
            'avg_effective_wind_kmh' => null,
            'samples' => [
                [
                    'time_offset_s' => 0,
                    'distance_m' => 0.0,
                    'lat' => -36.8485,
                    'lng' => 174.7633,
                    'wmo_code' => 2,
                    'temperature_c' => 16.0,
                    'apparent_temperature_c' => 14.8,
                    'humidity_pct' => 68,
                    'dew_point_c' => 10.1,
                    'wind_speed_kmh' => 12.4,
                    'wind_gusts_kmh' => 20.0,
                    'wind_direction_deg' => 45,
                    'wind_direction_compass' => 'NE',
                    'precipitation_mm' => 0.0,
                    'cloud_cover_pct' => 42,
                    'surface_pressure_hpa' => 1015.4,
                ],
            ],
        ]);
    }

    public function windy(): static
    {
        return $this->state(fn (array $attributes): array => [
            'wmo_code' => 2,
            'condition' => 'Windy',
            'base_condition' => 'Partly Cloudy',
            'icon' => 'wind',
            'wind_speed_kmh' => 25.4,
            'wind_gusts_kmh' => 41.2,
        ]);
    }
}
