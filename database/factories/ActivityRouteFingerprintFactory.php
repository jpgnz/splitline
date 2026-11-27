<?php

namespace Database\Factories;

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\RouteCluster;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityRouteFingerprint>
 */
class ActivityRouteFingerprintFactory extends Factory
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
            'activity_id' => Activity::factory(),
            'route_cluster_id' => RouteCluster::factory(),
            'start_lat' => $waypoints[0][0],
            'start_lng' => $waypoints[0][1],
            'end_lat' => $waypoints[19][0],
            'end_lng' => $waypoints[19][1],
            'bbox_min_lat' => min($lats),
            'bbox_max_lat' => max($lats),
            'bbox_min_lng' => min($lngs),
            'bbox_max_lng' => max($lngs),
            'distance_meters' => 10000.0,
            'start_to_end_meters' => 12.0,
            'is_loop' => true,
            'waypoints' => $waypoints,
            'encoded_preview_polyline' => HeatmapService::encodePolyline($waypoints),
            'is_unlinked' => false,
            'computed_at' => CarbonImmutable::now(),
        ];
    }
}
