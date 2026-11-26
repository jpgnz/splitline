<?php

namespace Database\Factories;

use App\Models\RouteCluster;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RouteCluster>
 */
class RouteClusterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $waypoints = [];

        for ($i = 0; $i < 20; $i++) {
            $angle = ($i / 19) * 2.0 * M_PI;
            $waypoints[] = [
                round(-36.85 + 0.008 * sin($angle), 5),
                round(174.76 + 0.008 * cos($angle), 5),
            ];
        }

        $lats = array_column($waypoints, 0);
        $lngs = array_column($waypoints, 1);

        return [
            'name' => fake()->randomElement(['Waterfront 10K Loop', 'Cornwall Park Loop', 'Harbour Tempo Route']),
            'name_override' => null,
            'sport_category' => 'running',
            'representative_activity_id' => null,
            'is_loop' => true,
            'distance_meters' => 10000.0,
            'elevation_gain' => 95.0,
            'start_lat' => $waypoints[0][0],
            'start_lng' => $waypoints[0][1],
            'end_lat' => $waypoints[19][0],
            'end_lng' => $waypoints[19][1],
            'bbox_min_lat' => min($lats),
            'bbox_max_lat' => max($lats),
            'bbox_min_lng' => min($lngs),
            'bbox_max_lng' => max($lngs),
            'waypoints' => $waypoints,
            'encoded_polyline' => HeatmapService::encodePolyline($waypoints),
            'activity_count' => 2,
            'last_activity_at' => CarbonImmutable::now(),
        ];
    }
}
