<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the empty comparison page with recent activity candidates', function () {
    $latest = Activity::factory()->create([
        'name' => 'Morning Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-11-22 07:00:00'),
    ]);
    $older = Activity::factory()->roadBiking()->create([
        'name' => 'Coastal Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
    ]);

    $this->get('/activities/compare')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Compare')
            ->where('align', 'distance')
            ->has('activities', 0)
            ->has('candidates', 2)
            ->where('candidates.0.id', $latest->id)
            ->where('candidates.1.id', $older->id),
        );
});

it('renders side-by-side comparison for comma-separated activity IDs', function () {
    $baseline = Activity::factory()->create([
        'name' => 'PR Waterfront 5K',
        'sport_category' => 'running',
        'distance_meters' => 5000.0,
        'duration_seconds' => 1200.0,
        'avg_speed' => 4.167,
        'avg_hr' => 170,
    ]);

    $attempt = Activity::factory()->create([
        'name' => 'Warm Waterfront 5K',
        'sport_category' => 'running',
        'distance_meters' => 5000.0,
        'duration_seconds' => 1260.0,
        'avg_speed' => 3.968,
        'avg_hr' => 174,
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $baseline->id,
        'series' => [
            ['time' => 0, 'distance' => 0, 'speed' => 4.167, 'hr' => 150, 'altitude' => 10],
            ['time' => 600, 'distance' => 2500, 'speed' => 4.167, 'hr' => 170, 'altitude' => 15],
            ['time' => 1200, 'distance' => 5000, 'speed' => 4.167, 'hr' => 182, 'altitude' => 10],
        ],
        'km_splits' => [
            [
                'km' => 1,
                'distance_meters' => 1000.0,
                'duration_seconds' => 240.0,
                'avg_speed' => 4.167,
                'pace_seconds_per_km' => 240.0,
                'gap_seconds_per_km' => null,
                'avg_hr' => 162,
                'avg_power' => null,
                'elevation_gain' => 5.0,
                'elevation_loss' => 2.0,
            ],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $attempt->id,
        'series' => [
            ['time' => 0, 'distance' => 0, 'speed' => 3.968, 'hr' => 152, 'altitude' => 10],
            ['time' => 630, 'distance' => 2500, 'speed' => 3.968, 'hr' => 175, 'altitude' => 15],
            ['time' => 1260, 'distance' => 5000, 'speed' => 3.968, 'hr' => 186, 'altitude' => 10],
        ],
        'km_splits' => [
            [
                'km' => 1,
                'distance_meters' => 1000.0,
                'duration_seconds' => 252.0,
                'avg_speed' => 3.968,
                'pace_seconds_per_km' => 252.0,
                'gap_seconds_per_km' => null,
                'avg_hr' => 168,
                'avg_power' => null,
                'elevation_gain' => 5.0,
                'elevation_loss' => 2.0,
            ],
        ],
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $baseline->id,
        'temperature_c' => 13.0,
        'wind_speed_kmh' => 8.0,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $attempt->id,
        'temperature_c' => 21.0,
        'wind_speed_kmh' => 19.0,
    ]);

    $this->get("/activities/compare?ids={$baseline->id},{$attempt->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Compare')
            ->where('align', 'distance')
            ->where('selected_ids', [$baseline->id, $attempt->id])
            ->has('activities', 2)
            ->where('activities.0.id', $baseline->id)
            ->where('activities.0.is_baseline', true)
            ->where('activities.1.id', $attempt->id)
            ->where('activities.1.deltas.duration_delta_s', 60)
            ->where('activities.1.deltas.hr_delta', 4)
            ->where('activities.1.deltas.temp_delta_c', 8)
            ->where('activities.1.deltas.wind_delta_kmh', 11)
            ->where('aligned.mode', 'distance')
            ->where('aligned.axis_max', 5000)
            ->where('aligned.series.1.summary.final_time_delta_seconds', 60)
            ->where('split_unit', 'km')
            ->has('splits', 1)
            ->where('splits.0.entries.1.time_delta_seconds', 12),
        );
});

it('supports array ids parameter and time alignment query option', function () {
    $first = Activity::factory()->create(['name' => 'First', 'duration_seconds' => 600.0]);
    $second = Activity::factory()->create(['name' => 'Second', 'duration_seconds' => 600.0]);

    ActivityStream::factory()->create([
        'activity_id' => $first->id,
        'series' => [
            ['time' => 0, 'distance' => 0, 'hr' => 140],
            ['time' => 600, 'distance' => 2000, 'hr' => 155],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $second->id,
        'series' => [
            ['time' => 0, 'distance' => 0, 'hr' => 142],
            ['time' => 600, 'distance' => 2200, 'hr' => 160],
        ],
    ]);

    $this->get("/activities/compare?ids[]={$second->id}&ids[]={$first->id}&align=time")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Compare')
            ->where('align', 'time')
            ->where('selected_ids', [$second->id, $first->id])
            ->where('activities.0.id', $second->id)
            ->where('aligned.mode', 'time')
            ->where('aligned.series.1.summary.final_distance_delta_meters', -200),
        );
});

it('rejects invalid comparison query parameters', function (string $query, string $errorKey) {
    $this->get("/activities/compare?{$query}")
        ->assertRedirect()
        ->assertSessionHasErrors($errorKey);
})->with([
    'non-numeric id' => ['ids=abc', 'ids.0'],
    'zero id' => ['ids=0', 'ids.0'],
    'duplicate ids' => ['ids=4,4', 'ids.0'],
    'more than three ids' => ['ids=1,2,3,4', 'ids'],
    'unknown align axis' => ['ids=1,2&align=pace', 'align'],
]);

it('ignores requested activity IDs that do not exist', function () {
    $activity = Activity::factory()->create();

    $this->get("/activities/compare?ids={$activity->id},999999")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_ids', [$activity->id])
            ->has('activities', 1),
        );
});
