<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Weather\ActivityWaypointSampler;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

it('returns empty waypoints for indoor activities and outdoor activities without GNSS coordinates', function () {
    $sampler = new ActivityWaypointSampler;

    $indoor = new Activity([
        'is_indoor' => true,
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
        'duration_seconds' => 1800.0,
        'distance_meters' => 5000.0,
    ]);

    $outdoorNoCoords = new Activity([
        'is_indoor' => false,
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC'),
        'start_lat' => null,
        'start_lng' => null,
        'duration_seconds' => 1800.0,
        'distance_meters' => 5000.0,
    ]);
    $outdoorNoCoords->setRelation('stream', null);

    expect($sampler->sample($indoor)['waypoints'])->toBe([])
        ->and($sampler->sample($outdoorNoCoords)['waypoints'])->toBe([]);
});

it('falls back to start_point mode with hourly waypoints when no GNSS stream exists', function () {
    $sampler = new ActivityWaypointSampler;
    $startUtc = CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC');

    $activity = new Activity([
        'is_indoor' => false,
        'started_at_utc' => $startUtc,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
        'duration_seconds' => 8100.0, // 2h 15m
        'elapsed_duration_seconds' => 8100.0,
        'distance_meters' => 27000.0,
    ]);
    $activity->setRelation('stream', null);

    $result = $sampler->sample($activity);

    expect($result['mode'])->toBe('start_point')
        ->and($result['waypoints'])->toHaveCount(4)
        ->and(array_column($result['waypoints'], 'time_offset_s'))->toBe([0, 3600, 7200, 8100])
        ->and($result['waypoints'][0]['timestamp'])->toBe($startUtc->getTimestamp())
        ->and($result['waypoints'][3]['timestamp'])->toBe($startUtc->getTimestamp() + 8100)
        ->and($result['waypoints'][0]['lat'])->toBe(-36.8485)
        ->and($result['waypoints'][0]['lng'])->toBe(174.7633);
});

it('deduplicates tight 5 km loop waypoints within 2000 m and the same UTC hour while preserving UTC hour shifts', function () {
    $sampler = new ActivityWaypointSampler;

    // 5 km tight park loop (radius ~80 m, 10 laps) finished in 24 minutes inside 06:05..06:29 UTC
    $sameHourStart = CarbonImmutable::parse('2026-11-20 06:05:00', 'UTC');
    $series = [];

    for ($step = 0; $step <= 100; $step++) {
        $angle = ($step / 10.0) * 2.0 * M_PI;
        $series[] = [
            'time' => $step * 14.4,
            'distance' => $step * 50.0,
            'altitude' => 25.0,
            'lat' => -36.8485 + (0.0007 * sin($angle)),
            'lng' => 174.7633 + (0.0007 * cos($angle)),
        ];
    }

    $stream = new ActivityStream(['series' => $series]);

    $sameHourActivity = new Activity([
        'is_indoor' => false,
        'started_at_utc' => $sameHourStart,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
        'duration_seconds' => 1440.0,
        'distance_meters' => 5000.0,
        'elevation_gain' => 20.0,
    ]);

    $sameHourResult = $sampler->sample($sameHourActivity, $stream);

    expect($sameHourResult['mode'])->toBe('route')
        ->and($sameHourResult['waypoints'])->toHaveCount(1);

    // Same tight loop starting at 06:50 UTC -> crosses into 07:00 UTC hour, preserving the new hour waypoint
    $crossHourActivity = new Activity([
        'is_indoor' => false,
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:50:00', 'UTC'),
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
        'duration_seconds' => 1440.0,
        'distance_meters' => 5000.0,
        'elevation_gain' => 20.0,
    ]);

    $crossHourResult = $sampler->sample($crossHourActivity, $stream);

    expect($crossHourResult['mode'])->toBe('route')
        ->and($crossHourResult['waypoints'])->toHaveCount(2)
        ->and(intdiv($crossHourResult['waypoints'][0]['timestamp'], 3600))
        ->not->toBe(intdiv($crossHourResult['waypoints'][1]['timestamp'], 3600));
});

it('caps a 100 km 4-hour ride at 25 waypoints while preserving start, end, and peak elevation', function () {
    $sampler = new ActivityWaypointSampler;
    $startUtc = CarbonImmutable::parse('2026-11-20 05:00:00', 'UTC');

    // 100 km straight northward ride over 4 hours (14,400 s), sampled every 500 m (201 points)
    // Peak mountain pass at 52.5 km (index 105) with 950 m altitude
    $series = [];
    $peakIndex = 105;

    for ($i = 0; $i <= 200; $i++) {
        $distanceM = $i * 500.0;
        $timeS = $i * 72.0;
        $lat = -36.8485 + ($i * 0.0045); // ~500 m per step
        $lng = 174.7633;
        $altitude = $i === $peakIndex ? 950.0 : (50.0 + (200.0 * sin(($i / 200.0) * M_PI)));

        $series[] = [
            'time' => $timeS,
            'distance' => $distanceM,
            'altitude' => $altitude,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    $activity = new Activity([
        'is_indoor' => false,
        'started_at_utc' => $startUtc,
        'start_lat' => $series[0]['lat'],
        'start_lng' => $series[0]['lng'],
        'duration_seconds' => 14400.0,
        'distance_meters' => 100000.0,
        'elevation_gain' => 900.0,
    ]);

    $result = $sampler->sample($activity, new ActivityStream(['series' => $series]));
    $waypoints = $result['waypoints'];

    expect($result['mode'])->toBe('route')
        ->and($waypoints)->toHaveCount(25)
        ->and($waypoints[0]['distance_m'])->toBe(0.0)
        ->and($waypoints[24]['distance_m'])->toBe(100000.0);

    // Peak waypoint (52,500 m) is preserved inside the 25-waypoint cap
    $distances = array_column($waypoints, 'distance_m');
    expect($distances)->toContain(52500.0);
});

it('inserts an isolated peak elevation waypoint when elevation gain >= 200 m and >= 1500 m from existing waypoints', function () {
    $sampler = new ActivityWaypointSampler;
    $startUtc = CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC');

    // Outbound climb: 0 m -> 2000 m (summit @ 450 m altitude, 2000 m from start and 2000 m from end) -> 4000 m
    // Step is 2000 m so normal 2500 m threshold only picks 0 m and 4000 m unless peak insertion adds 2000 m!
    $series = [
        ['time' => 0.0, 'distance' => 0.0, 'altitude' => 50.0, 'lat' => -36.8500, 'lng' => 174.7600],
        ['time' => 450.0, 'distance' => 2000.0, 'altitude' => 450.0, 'lat' => -36.8320, 'lng' => 174.7600],
        ['time' => 900.0, 'distance' => 2400.0, 'altitude' => 150.0, 'lat' => -36.8140, 'lng' => 174.7600],
    ];

    $activity = new Activity([
        'is_indoor' => false,
        'started_at_utc' => $startUtc,
        'start_lat' => -36.8500,
        'start_lng' => 174.7600,
        'duration_seconds' => 900.0,
        'distance_meters' => 2400.0,
        'elevation_gain' => 400.0,
    ]);

    $result = $sampler->sample($activity, new ActivityStream(['series' => $series]));

    expect($result['mode'])->toBe('route')
        ->and($result['waypoints'])->toHaveCount(3)
        ->and(array_column($result['waypoints'], 'distance_m'))->toBe([0.0, 2000.0, 2400.0]);
});
