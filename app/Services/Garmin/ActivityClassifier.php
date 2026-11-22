<?php

namespace App\Services\Garmin;

class ActivityClassifier
{
    /**
     * Classify a provider activity type key into sport category, sport discipline, and indoor flag.
     *
     * @return array{sport_category: string, sport_discipline: ?string, is_indoor: bool}
     */
    public function classify(?string $typeKey): array
    {
        $key = mb_strtolower(trim((string) $typeKey));

        return match ($key) {
            'running', 'street_running' => [
                'sport_category' => 'running',
                'sport_discipline' => 'road',
                'is_indoor' => false,
            ],
            'trail_running', 'ultra_run', 'obstacle_run' => [
                'sport_category' => 'running',
                'sport_discipline' => 'trail',
                'is_indoor' => false,
            ],
            'track_running' => [
                'sport_category' => 'running',
                'sport_discipline' => 'track',
                'is_indoor' => false,
            ],
            'treadmill_running', 'indoor_running', 'virtual_run' => [
                'sport_category' => 'running',
                'sport_discipline' => 'road',
                'is_indoor' => true,
            ],
            'road_biking', 'track_cycling', 'recumbent_cycling' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'road',
                'is_indoor' => false,
            ],
            'indoor_cycling', 'virtual_ride' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'road',
                'is_indoor' => true,
            ],
            'gravel_cycling', 'cyclocross' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'gravel',
                'is_indoor' => false,
            ],
            'mountain_biking', 'trail_biking', 'downhill_biking', 'enduro_biking' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'mtb',
                'is_indoor' => false,
            ],
            'e_bike_mountain', 'e_bike_fitness', 'e_gravel_cycling' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'ebike',
                'is_indoor' => false,
            ],
            'commuting' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'commute',
                'is_indoor' => false,
            ],
            'cycling', 'biking' => [
                'sport_category' => 'biking',
                'sport_discipline' => 'other',
                'is_indoor' => false,
            ],
            'walking', 'casual_walking', 'speed_walking' => [
                'sport_category' => 'walking',
                'sport_discipline' => null,
                'is_indoor' => false,
            ],
            'hiking', 'mountaineering' => [
                'sport_category' => 'hiking',
                'sport_discipline' => null,
                'is_indoor' => false,
            ],
            'lap_swimming', 'open_water_swimming', 'swimming' => [
                'sport_category' => 'swimming',
                'sport_discipline' => null,
                'is_indoor' => $key === 'lap_swimming',
            ],
            'indoor_cardio',
            'strength_training',
            'cardio',
            'elliptical',
            'stair_climbing',
            'hiit',
            'yoga',
            'pilates',
            'breathwork',
            'indoor_rowing',
            'rowing' => [
                'sport_category' => 'cardio',
                'sport_discipline' => null,
                'is_indoor' => str_contains($key, 'indoor')
                    || in_array($key, ['strength_training', 'elliptical', 'yoga', 'pilates', 'breathwork'], true),
            ],
            default => [
                'sport_category' => 'other',
                'sport_discipline' => null,
                'is_indoor' => str_contains($key, 'indoor')
                    || str_contains($key, 'virtual')
                    || str_contains($key, 'treadmill'),
            ],
        };
    }
}
