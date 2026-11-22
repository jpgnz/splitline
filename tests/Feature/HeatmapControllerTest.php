<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the route heatmap page with default Inertia props', function () {
    $run = Activity::factory()->create([
        'name' => 'Cornwall Park Loop',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:15:00'),
        'location_name' => 'Auckland',
        'distance_meters' => 11200.0,
        'duration_seconds' => 3100.0,
        'elevation_gain' => 145.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-19 08:30:00'),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $run->id,
        'polyline' => [[-36.8960, 174.7830], [-36.8990, 174.7880]],
    ]);

    // Unsynced outdoor activity to verify summary count
    Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'streams_synced_at' => null,
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
    ]);

    $this->get('/heatmap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Heatmap/Index')
            ->where('filters.sport', 'all')
            ->where('filters.timeframe', 'all')
            ->where('summary.track_count', 1)
            ->where('summary.total_distance_meters', 11200)
            ->where('summary.total_elevation_gain', 145)
            ->where('summary.unsynced_gnss_count', 1)
            ->has('tracks', 1)
            ->where('tracks.0.activity_id', $run->id)
            ->where('tracks.0.name', 'Cornwall Park Loop')
            ->where('tracks.0.sport_category', 'running')
            ->where('tracks.0.date', '2026-11-19')
            ->where(
                'tracks.0.polyline',
                HeatmapService::encodePolyline([[-36.8960, 174.7830], [-36.8990, 174.7880]]),
            ),
        );
});

it('applies sport and timeframe query filters and falls back invalid values', function () {
    $recentMtb = Activity::factory()->otherBiking()->create([
        'name' => 'Redwoods Reclassified Trail',
        'discipline_override' => 'mtb',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 09:00:00'),
        'distance_meters' => 24500.0,
        'duration_seconds' => 5200.0,
        'elevation_gain' => 780.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-12 11:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $recentMtb->id,
        'polyline' => [[-38.1580, 176.2710], [-38.1650, 176.2800]],
    ]);

    $oldMtb = Activity::factory()->mountainBiking()->create([
        'name' => 'Old Winter Trail',
        'started_at_local' => CarbonImmutable::parse('2025-07-04 10:00:00'),
        'distance_meters' => 19000.0,
        'duration_seconds' => 4500.0,
        'elevation_gain' => 620.0,
        'streams_synced_at' => CarbonImmutable::parse('2025-07-04 12:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $oldMtb->id,
        'polyline' => [[-38.1500, 176.2600], [-38.1600, 176.2700]],
    ]);

    $recentRun = Activity::factory()->create([
        'name' => 'Morning Recovery Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-15 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $recentRun->id,
        'polyline' => [[-36.8485, 174.7633], [-36.8510, 174.7680]],
    ]);

    $this->get('/heatmap?sport=mtb&timeframe=30d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Heatmap/Index')
            ->where('filters.sport', 'mtb')
            ->where('filters.timeframe', '30d')
            ->where('summary.track_count', 1)
            ->where('summary.total_distance_meters', 24500)
            ->where('summary.total_elevation_gain', 780)
            ->has('tracks', 1)
            ->where('tracks.0.activity_id', $recentMtb->id)
            ->where('tracks.0.effective_discipline', 'mtb'),
        );

    $this->get('/heatmap?sport=invalid&timeframe=bogus')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Heatmap/Index')
            ->where('filters.sport', 'all')
            ->where('filters.timeframe', 'all')
            ->where('summary.track_count', 3)
            ->has('tracks', 3),
        );
});

it('does not share external map API keys via Inertia props', function () {
    $this->get('/heatmap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('maps'),
        );
});
