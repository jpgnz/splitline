<?php

namespace Database\Factories;

use App\Models\Segment;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
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
            $fraction = $i / 19;
            $waypoints[] = [
                round(-36.8800 + (0.015 * $fraction), 5),
                round(174.7600 + (0.005 * $fraction), 5),
            ];
        }

        $lats = array_column($waypoints, 0);
        $lngs = array_column($waypoints, 1);

        return [
            'name' => fake()->randomElement(['Scenic Drive Climb', 'Mt Eden Summit Climb', 'Waitakere Ridge Climb']),
            'name_override' => null,
            'place_label' => null,
            'kind' => Segment::KIND_CLIMB,
            'sport_category' => 'biking',
            'source_activity_id' => null,
            'climb_category' => 'cat4',
            'distance_meters' => 1700.0,
            'elevation_gain' => 112.0,
            'elevation_loss' => 2.0,
            'avg_grade_pct' => 6.6,
            'max_grade_pct' => 11.4,
            'fiets_score' => 0.74,
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
            'is_starred' => false,
            'effort_count' => 0,
            'detection_count' => 5,
            'is_sub_climb' => false,
            'is_climb_verified' => true,
            'best_elapsed_seconds' => null,
            'best_effort_id' => null,
            'last_effort_at' => CarbonImmutable::now('UTC'),
            'geocoded_at' => null,
        ];
    }

    public function climb(string $category = 'cat4'): static
    {
        return $this->state(fn (): array => [
            'kind' => Segment::KIND_CLIMB,
            'climb_category' => $category,
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (): array => [
            'kind' => Segment::KIND_CUSTOM,
            'climb_category' => null,
            'fiets_score' => null,
        ]);
    }

    public function starred(): static
    {
        return $this->state(fn (): array => [
            'is_starred' => true,
        ]);
    }
}
