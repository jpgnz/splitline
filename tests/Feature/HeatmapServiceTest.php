<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('returns a clean empty payload when no activities exist', function () {
    $payload = app(HeatmapService::class)->tracks();

    expect($payload['filters'])->toBe([
        'sport' => 'all',
        'timeframe' => 'all',
    ])
        ->and($payload['summary'])->toBe([
            'track_count' => 0,
            'total_distance_meters' => 0.0,
            'total_elevation_gain' => 0.0,
            'unsynced_gnss_count' => 0,
        ])
        ->and($payload['tracks'])->toBe([])
        ->and($payload['available_sports'])->not->toBeEmpty();
});

it('filters tracks by sport and respects effective biking discipline overrides', function () {
    $run = Activity::factory()->create([
        'name' => 'Tamaki Drive Tempo',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'location_name' => 'Auckland',
        'distance_meters' => 10000.0,
        'duration_seconds' => 2600.0,
        'elevation_gain' => 85.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-20 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $run->id,
        'polyline' => [[-36.8485, 174.7633], [-36.8510, 174.7900]],
    ]);

    $road = Activity::factory()->roadBiking()->create([
        'name' => 'Waterfront Road Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 06:30:00'),
        'distance_meters' => 45000.0,
        'duration_seconds' => 5400.0,
        'elevation_gain' => 420.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-19 09:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $road->id,
        'polyline' => [[-36.8500, 174.7700], [-36.8600, 174.8200]],
    ]);

    $mtb = Activity::factory()->mountainBiking()->create([
        'name' => 'Woodhill Singletrack',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 15:00:00'),
        'distance_meters' => 22000.0,
        'duration_seconds' => 4800.0,
        'elevation_gain' => 610.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-18 17:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $mtb->id,
        'polyline' => [[-36.7300, 174.4300], [-36.7400, 174.4400]],
    ]);

    $overriddenToRoad = Activity::factory()->otherBiking()->create([
        'name' => 'Reclassified Road Ride',
        'discipline_override' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-17 07:30:00'),
        'distance_meters' => 30000.0,
        'duration_seconds' => 3600.0,
        'elevation_gain' => 250.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-17 09:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $overriddenToRoad->id,
        'polyline' => [[-36.8400, 174.7500], [-36.8300, 174.7400]],
    ]);

    $overriddenToMtb = Activity::factory()->roadBiking()->create([
        'name' => 'Reclassified Trail Ride',
        'discipline_override' => 'mtb',
        'started_at_local' => CarbonImmutable::parse('2026-11-16 09:00:00'),
        'distance_meters' => 18000.0,
        'duration_seconds' => 4000.0,
        'elevation_gain' => 500.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-16 11:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $overriddenToMtb->id,
        'polyline' => [[-38.1600, 176.2800], [-38.1700, 176.2900]],
    ]);

    $service = app(HeatmapService::class);

    $all = $service->tracks('all', 'all');
    expect($all['summary']['track_count'])->toBe(5)
        ->and(array_column($all['tracks'], 'activity_id'))->toBe([
            $run->id,
            $road->id,
            $mtb->id,
            $overriddenToRoad->id,
            $overriddenToMtb->id,
        ]);

    $running = $service->tracks('running', 'all');
    expect($running['filters'])->toBe(['sport' => 'running', 'timeframe' => 'all'])
        ->and($running['summary'])->toBe([
            'track_count' => 1,
            'total_distance_meters' => 10000.0,
            'total_elevation_gain' => 85.0,
            'unsynced_gnss_count' => 0,
        ])
        ->and($running['tracks'][0])->toBe([
            'activity_id' => $run->id,
            'name' => 'Tamaki Drive Tempo',
            'sport_category' => 'running',
            'effective_discipline' => 'road',
            'sport_key' => 'road_running',
            'accent_hex' => '#10b981',
            'date' => '2026-11-20',
            'location_name' => 'Auckland',
            'distance_meters' => 10000.0,
            'duration_seconds' => 2600.0,
            'elevation_gain' => 85.0,
            'polyline' => HeatmapService::encodePolyline([[-36.8485, 174.7633], [-36.8510, 174.7900]]),
        ])
        ->and(HeatmapService::decodePolyline($running['tracks'][0]['polyline']))->toBe([
            [-36.8485, 174.7633],
            [-36.851, 174.79],
        ]);

    $roadPayload = $service->tracks('road', 'all');
    expect(array_column($roadPayload['tracks'], 'activity_id'))->toBe([
        $road->id,
        $overriddenToRoad->id,
    ])
        ->and($roadPayload['tracks'][1]['effective_discipline'])->toBe('road')
        ->and($roadPayload['summary']['total_distance_meters'])->toBe(75000.0)
        ->and($roadPayload['summary']['total_elevation_gain'])->toBe(670.0);

    $mtbPayload = $service->tracks('mtb', 'all');
    expect(array_column($mtbPayload['tracks'], 'activity_id'))->toBe([
        $mtb->id,
        $overriddenToMtb->id,
    ])
        ->and($mtbPayload['tracks'][1]['effective_discipline'])->toBe('mtb')
        ->and($mtbPayload['summary']['total_distance_meters'])->toBe(40000.0)
        ->and($mtbPayload['summary']['total_elevation_gain'])->toBe(1110.0);

    $invalid = $service->tracks('skydiving', 'decade');
    expect($invalid['filters'])->toBe(['sport' => 'all', 'timeframe' => 'all'])
        ->and($invalid['summary']['track_count'])->toBe(5);
});

it('filters tracks by 30d, this_year, and all timeframes in the configured timezone', function () {
    $recent = Activity::factory()->create([
        'name' => 'Recent 30d Run',
        'started_at_local' => CarbonImmutable::parse('2026-10-23 06:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-10-23 07:00:00'),
    ]);
    ActivityStream::factory()->create(['activity_id' => $recent->id]);

    $earlierThisYear = Activity::factory()->create([
        'name' => 'Autumn Run',
        'started_at_local' => CarbonImmutable::parse('2026-03-15 08:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-03-15 09:00:00'),
    ]);
    ActivityStream::factory()->create(['activity_id' => $earlierThisYear->id]);

    $lastYear = Activity::factory()->create([
        'name' => 'Last Year Run',
        'started_at_local' => CarbonImmutable::parse('2025-12-20 08:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2025-12-20 09:00:00'),
    ]);
    ActivityStream::factory()->create(['activity_id' => $lastYear->id]);

    $service = app(HeatmapService::class);

    $last30 = $service->tracks('all', '30d');
    expect(array_column($last30['tracks'], 'activity_id'))->toBe([$recent->id]);

    $thisYear = $service->tracks('all', 'this_year');
    expect(array_column($thisYear['tracks'], 'activity_id'))->toBe([
        $recent->id,
        $earlierThisYear->id,
    ]);

    $allTime = $service->tracks('all', 'all');
    expect(array_column($allTime['tracks'], 'activity_id'))->toBe([
        $recent->id,
        $earlierThisYear->id,
        $lastYear->id,
    ]);
});

it('skips empty, null, single-point, and invalid polylines', function () {
    $valid = Activity::factory()->create([
        'name' => 'Valid GNSS Run',
        'distance_meters' => 8000.0,
        'elevation_gain' => 120.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-20 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $valid->id,
        'polyline' => [[-36.84, 174.76], [-36.85, 174.77], ['bad', 'coord']],
    ]);

    $emptyPolyline = Activity::factory()->create([
        'name' => 'Empty Polyline Run',
        'distance_meters' => 5000.0,
        'elevation_gain' => 50.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-19 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $emptyPolyline->id,
        'polyline' => [],
    ]);

    $nullPolyline = Activity::factory()->create([
        'name' => 'Null Polyline Run',
        'distance_meters' => 6000.0,
        'elevation_gain' => 40.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-18 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $nullPolyline->id,
        'polyline' => null,
    ]);

    $singlePoint = Activity::factory()->create([
        'name' => 'Single Point Run',
        'distance_meters' => 4000.0,
        'elevation_gain' => 30.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-17 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-17 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $singlePoint->id,
        'polyline' => [[-36.84, 174.76]],
    ]);

    $payload = app(HeatmapService::class)->tracks();

    expect($payload['summary']['track_count'])->toBe(1)
        ->and($payload['summary']['total_distance_meters'])->toBe(8000.0)
        ->and($payload['summary']['total_elevation_gain'])->toBe(120.0)
        ->and($payload['tracks'])->toHaveCount(1)
        ->and($payload['tracks'][0]['activity_id'])->toBe($valid->id)
        ->and(HeatmapService::decodePolyline($payload['tracks'][0]['polyline']))->toBe([
            [-36.84, 174.76],
            [-36.85, 174.77],
        ]);
});

it('counts unsynced outdoor GNSS activities within the active filters while ignoring indoor, manual, and synced ones', function () {
    // Unsynced outdoor running within 30d (counted for all, running, 30d)
    Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'streams_synced_at' => null,
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
    ]);

    // Unsynced outdoor road biking earlier this year (counted for all, road, this_year; NOT 30d)
    Activity::factory()->roadBiking()->create([
        'is_indoor' => false,
        'streams_synced_at' => null,
        'started_at_local' => CarbonImmutable::parse('2026-05-10 08:00:00'),
    ]);

    // Unsynced indoor treadmill run (never counted)
    Activity::factory()->indoor()->create([
        'sport_category' => 'running',
        'activity_type_key' => 'treadmill_running',
        'streams_synced_at' => null,
        'started_at_local' => CarbonImmutable::parse('2026-11-16 07:00:00'),
    ]);

    // Unsynced manual outdoor ride (never counted — garmin:sync-streams skips manual activities)
    Activity::factory()->roadBiking()->create([
        'is_indoor' => false,
        'is_manual' => true,
        'start_lat' => null,
        'start_lng' => null,
        'streams_synced_at' => null,
        'started_at_local' => CarbonImmutable::parse('2026-11-14 08:00:00'),
    ]);

    // Already synced outdoor run (not counted as unsynced)
    $syncedRun = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-18 09:00:00'),
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
    ]);
    ActivityStream::factory()->create(['activity_id' => $syncedRun->id]);

    $service = app(HeatmapService::class);

    expect($service->tracks('all', 'all')['summary']['unsynced_gnss_count'])->toBe(2)
        ->and($service->tracks('all', '30d')['summary']['unsynced_gnss_count'])->toBe(1)
        ->and($service->tracks('running', 'all')['summary']['unsynced_gnss_count'])->toBe(1)
        ->and($service->tracks('road', 'this_year')['summary']['unsynced_gnss_count'])->toBe(1)
        ->and($service->tracks('mtb', 'all')['summary']['unsynced_gnss_count'])->toBe(0);
});
