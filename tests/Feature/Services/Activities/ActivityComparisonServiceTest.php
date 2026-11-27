<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Services\Activities\ActivityComparisonService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;

function makeDistanceSeries(float $totalDistance, float $speedMps, int $baseHr, ?float $basePower = null, float $startAlt = 10.0, float $grade = 0.01): array
{
    $points = [];
    $steps = 20;

    for ($i = 0; $i <= $steps; $i++) {
        $distance = ($totalDistance * $i) / $steps;
        $time = $distance / $speedMps;

        $points[] = [
            'time' => round($time, 2),
            'distance' => round($distance, 2),
            'speed' => $speedMps,
            'gap_speed' => round($speedMps * 1.02, 3),
            'hr' => $baseHr + $i,
            'power' => $basePower !== null ? $basePower + ($i * 2.0) : null,
            'altitude' => round($startAlt + ($distance * $grade), 1),
            'cadence' => 176.0,
        ];
    }

    return $points;
}

it('aligns distance-indexed telemetry and computes Ghost Pacer time deltas accurately', function () {
    $baseline = Activity::factory()->create([
        'name' => 'Baseline 10K',
        'sport_category' => 'running',
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'avg_speed' => 3.333,
        'avg_hr' => 155,
    ]);

    // Faster challenger (4.0 m/s -> 250s/km vs baseline 300s/km) over 8km
    $faster = Activity::factory()->create([
        'name' => 'Fast 8K',
        'sport_category' => 'running',
        'distance_meters' => 8000.0,
        'duration_seconds' => 2000.0,
        'avg_speed' => 4.0,
        'avg_hr' => 165,
    ]);

    // Slower challenger (2.5 m/s -> 400s/km) over 10km
    $slower = Activity::factory()->create([
        'name' => 'Easy 10K',
        'sport_category' => 'running',
        'distance_meters' => 10000.0,
        'duration_seconds' => 4000.0,
        'avg_speed' => 2.5,
        'avg_hr' => 140,
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $baseline->id,
        'series' => makeDistanceSeries(10000.0, 1000.0 / 300.0, 145),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $faster->id,
        'series' => makeDistanceSeries(8000.0, 4.0, 155),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $slower->id,
        'series' => makeDistanceSeries(10000.0, 2.5, 132),
    ]);

    $result = app(ActivityComparisonService::class)->compare(
        "{$baseline->id},{$faster->id},{$slower->id}",
        'distance',
    );

    expect($result['align'])->toBe('distance')
        ->and($result['fallback_reason'])->toBeNull()
        ->and($result['selected_ids'])->toBe([$baseline->id, $faster->id, $slower->id])
        ->and($result['aligned']['can_align_distance'])->toBeTrue()
        ->and($result['aligned']['axis_max'])->toBe(8000.0)
        ->and($result['aligned']['bins'])->toHaveCount(ActivityComparisonService::SAMPLE_BINS)
        ->and($result['aligned']['bins'][0])->toBe(0.0)
        ->and($result['aligned']['bins'][ActivityComparisonService::SAMPLE_BINS - 1])->toBe(8000.0)
        ->and($result['aligned']['has_speed'])->toBeTrue()
        ->and($result['aligned']['has_gap'])->toBeTrue()
        ->and($result['aligned']['has_hr'])->toBeTrue()
        ->and($result['aligned']['has_altitude'])->toBeTrue();

    $baselineSeries = $result['aligned']['series'][0];
    $fasterSeries = $result['aligned']['series'][1];
    $slowerSeries = $result['aligned']['series'][2];

    // At 8000m: baseline = 2400s, faster = 2000s (-400s ahead), slower = 3200s (+800s behind)
    $lastIdx = ActivityComparisonService::SAMPLE_BINS - 1;

    expect($baselineSeries['time_delta_seconds'][0])->toBe(0.0)
        ->and($baselineSeries['time_delta_seconds'][$lastIdx])->toBe(0.0)
        ->and($fasterSeries['time_delta_seconds'][0])->toBe(0.0)
        ->and($fasterSeries['time_delta_seconds'][$lastIdx])->toBe(-400.0)
        ->and($fasterSeries['summary']['final_time_delta_seconds'])->toBe(-400.0)
        ->and($slowerSeries['time_delta_seconds'][$lastIdx])->toBe(800.0)
        ->and($slowerSeries['summary']['final_time_delta_seconds'])->toBe(800.0);
});

it('falls back to time alignment when activities lack distance streams and computes distance deltas when available', function () {
    $a = Activity::factory()->indoor()->create([
        'name' => 'Indoor Trainer A',
        'sport_category' => 'biking',
        'distance_meters' => 0.0,
        'duration_seconds' => 1200.0,
    ]);

    $b = Activity::factory()->indoor()->create([
        'name' => 'Indoor Trainer B',
        'sport_category' => 'biking',
        'distance_meters' => 0.0,
        'duration_seconds' => 900.0,
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $a->id,
        'series' => [
            ['time' => 0, 'distance' => null, 'hr' => 130, 'power' => 200, 'speed' => null, 'altitude' => null, 'cadence' => 88],
            ['time' => 600, 'distance' => null, 'hr' => 148, 'power' => 230, 'speed' => null, 'altitude' => null, 'cadence' => 90],
            ['time' => 1200, 'distance' => null, 'hr' => 160, 'power' => 250, 'speed' => null, 'altitude' => null, 'cadence' => 92],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $b->id,
        'series' => [
            ['time' => 0, 'distance' => null, 'hr' => 128, 'power' => 210, 'speed' => null, 'altitude' => null, 'cadence' => 86],
            ['time' => 450, 'distance' => null, 'hr' => 152, 'power' => 245, 'speed' => null, 'altitude' => null, 'cadence' => 89],
            ['time' => 900, 'distance' => null, 'hr' => 166, 'power' => 265, 'speed' => null, 'altitude' => null, 'cadence' => 91],
        ],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$a->id, $b->id], 'distance');

    expect($result['align'])->toBe('time')
        ->and($result['requested_align'])->toBe('distance')
        ->and($result['fallback_reason'])->toBe('missing_distance_stream')
        ->and($result['aligned']['can_align_distance'])->toBeFalse()
        ->and($result['aligned']['axis_max'])->toBe(900.0)
        ->and($result['aligned']['has_power'])->toBeTrue()
        ->and($result['aligned']['has_hr'])->toBeTrue();

    // At t = 900s (last bin), Activity A power interpolates between 600s (230W) and 1200s (250W) -> 240W
    $lastIdx = ActivityComparisonService::SAMPLE_BINS - 1;
    expect($result['aligned']['series'][0]['power'][$lastIdx])->toBe(240.0)
        ->and($result['aligned']['series'][1]['power'][$lastIdx])->toBe(265.0);
});

it('supports explicit time alignment with distance delta calculations', function () {
    $a = Activity::factory()->create(['distance_meters' => 5000.0, 'duration_seconds' => 1500.0]);
    $b = Activity::factory()->create(['distance_meters' => 6000.0, 'duration_seconds' => 1500.0]);

    ActivityStream::factory()->create([
        'activity_id' => $a->id,
        'series' => makeDistanceSeries(5000.0, 5000.0 / 1500.0, 145),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $b->id,
        'series' => makeDistanceSeries(6000.0, 6000.0 / 1500.0, 152),
    ]);

    $result = app(ActivityComparisonService::class)->compare([$a->id, $b->id], 'time');
    $lastIdx = ActivityComparisonService::SAMPLE_BINS - 1;

    expect($result['align'])->toBe('time')
        ->and($result['fallback_reason'])->toBeNull()
        ->and($result['aligned']['can_align_distance'])->toBeTrue()
        ->and($result['aligned']['axis_max'])->toBe(1500.0)
        ->and($result['aligned']['series'][1]['distance_delta_meters'][$lastIdx])->toBe(1000.0)
        ->and($result['aligned']['series'][1]['summary']['final_distance_delta_meters'])->toBe(1000.0);
});

it('builds aligned kilometer splits diff across activities with varying split counts', function () {
    $base = Activity::factory()->create(['name' => 'Base Run']);
    $other = Activity::factory()->create(['name' => 'Faster Run']);

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'km_splits' => [
            [
                'km' => 1,
                'distance_meters' => 1000.0,
                'duration_seconds' => 300.0,
                'avg_speed' => 3.333,
                'pace_seconds_per_km' => 300.0,
                'gap_seconds_per_km' => 295.0,
                'avg_hr' => 148,
                'avg_power' => null,
                'elevation_gain' => 12.0,
                'elevation_loss' => 4.0,
            ],
            [
                'km' => 2,
                'distance_meters' => 1000.0,
                'duration_seconds' => 310.0,
                'avg_speed' => 3.226,
                'pace_seconds_per_km' => 310.0,
                'gap_seconds_per_km' => 302.0,
                'avg_hr' => 155,
                'avg_power' => null,
                'elevation_gain' => 5.0,
                'elevation_loss' => 15.0,
            ],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'km_splits' => [
            [
                'km' => 1,
                'distance_meters' => 1000.0,
                'duration_seconds' => 285.0,
                'avg_speed' => 3.509,
                'pace_seconds_per_km' => 285.0,
                'gap_seconds_per_km' => 282.0,
                'avg_hr' => 154,
                'avg_power' => null,
                'elevation_gain' => 10.0,
                'elevation_loss' => 4.0,
            ],
            [
                'km' => 2,
                'distance_meters' => 1000.0,
                'duration_seconds' => 318.0,
                'avg_speed' => 3.145,
                'pace_seconds_per_km' => 318.0,
                'gap_seconds_per_km' => 312.0,
                'avg_hr' => 160,
                'avg_power' => null,
                'elevation_gain' => 6.0,
                'elevation_loss' => 14.0,
            ],
            [
                'km' => 3,
                'distance_meters' => 420.0,
                'duration_seconds' => 126.0,
                'avg_speed' => 3.333,
                'pace_seconds_per_km' => 300.0,
                'gap_seconds_per_km' => null,
                'avg_hr' => 162,
                'avg_power' => null,
                'elevation_gain' => 2.0,
                'elevation_loss' => 1.0,
            ],
        ],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $other->id]);

    expect($result['splits'])->toHaveCount(3)
        ->and($result['splits'][0]['split_index'])->toBe(1)
        ->and($result['splits'][0]['entries'][0]['elevation_delta'])->toBe(8.0)
        ->and($result['splits'][0]['entries'][0]['time_delta_seconds'])->toBeNull()
        ->and($result['splits'][0]['entries'][1]['time_delta_seconds'])->toBe(-15.0)
        ->and($result['splits'][0]['entries'][1]['hr_delta'])->toBe(6)
        ->and($result['splits'][1]['entries'][1]['time_delta_seconds'])->toBe(8.0)
        ->and($result['splits'][1]['entries'][0]['elevation_delta'])->toBe(-10.0)
        ->and($result['splits'][2]['entries'][0]['duration_seconds'])->toBeNull()
        ->and($result['splits'][2]['entries'][1]['is_partial'])->toBeTrue()
        ->and($result['splits'][2]['entries'][1]['time_delta_seconds'])->toBeNull();
});

it('computes headline KPI and weather diffs relative to baseline', function () {
    $base = Activity::factory()->roadBiking()->create([
        'name' => 'Cool Ride',
        'distance_meters' => 40000.0,
        'duration_seconds' => 4800.0,
        'avg_speed' => 8.333,
        'avg_hr' => 148,
        'avg_power' => 220.0,
        'normalized_power' => 235.0,
    ]);

    $challenger = Activity::factory()->roadBiking()->create([
        'name' => 'Hot Windy Ride',
        'distance_meters' => 40500.0,
        'duration_seconds' => 4620.0,
        'avg_speed' => 8.766,
        'avg_hr' => 156,
        'avg_power' => 238.5,
        'normalized_power' => 252.0,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $base->id,
        'condition' => 'Clear',
        'temperature_c' => 14.0,
        'wind_speed_kmh' => 10.0,
        'headwind_pct' => 25.0,
        'tailwind_pct' => 45.0,
        'avg_effective_wind_kmh' => 3.2,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $challenger->id,
        'condition' => 'Windy',
        'temperature_c' => 22.5,
        'wind_speed_kmh' => 24.5,
        'headwind_pct' => 60.0,
        'tailwind_pct' => 15.0,
        'avg_effective_wind_kmh' => -7.8,
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $challenger->id]);

    expect($result['activities'][0]['is_baseline'])->toBeTrue()
        ->and($result['activities'][0]['deltas'])->toBeNull()
        ->and($result['activities'][1]['is_baseline'])->toBeFalse()
        ->and($result['activities'][1]['deltas']['duration_delta_s'])->toBe(-180.0)
        ->and($result['activities'][1]['deltas']['distance_delta_m'])->toBe(500.0)
        ->and($result['activities'][1]['deltas']['speed_delta'])->toBe(0.433)
        ->and($result['activities'][1]['deltas']['hr_delta'])->toBe(8)
        ->and($result['activities'][1]['deltas']['power_delta'])->toBe(18.5)
        ->and($result['activities'][1]['deltas']['temp_delta_c'])->toBe(8.5)
        ->and($result['activities'][1]['deltas']['wind_delta_kmh'])->toBe(14.5)
        ->and($result['activities'][1]['deltas']['effective_wind_delta_kmh'])->toBe(-11.0);
});

it('preserves requested order, deduplicates, ignores invalid IDs, and caps at 3 activities', function () {
    $a = Activity::factory()->create(['name' => 'A']);
    $b = Activity::factory()->create(['name' => 'B']);
    $c = Activity::factory()->create(['name' => 'C']);
    $d = Activity::factory()->create(['name' => 'D']);

    $result = app(ActivityComparisonService::class)->compare(
        "abc, -5, {$c->id}, {$a->id}, {$c->id}, {$d->id}, {$b->id}",
    );

    expect($result['selected_ids'])->toBe([$c->id, $a->id, $d->id])
        ->and(array_column($result['activities'], 'name'))->toBe(['C', 'A', 'D']);
});

it('handles empty and single-activity states with sport-filtered candidates', function () {
    $run1 = Activity::factory()->create([
        'name' => 'Run 1',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
    ]);
    $run2 = Activity::factory()->create([
        'name' => 'Run 2',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
    ]);
    $bike = Activity::factory()->roadBiking()->create([
        'name' => 'Bike 1',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 07:00:00'),
    ]);

    $service = app(ActivityComparisonService::class);

    // Empty state returns candidates across all sports
    $empty = $service->compare(null);
    expect($empty['activities'])->toBeEmpty()
        ->and($empty['aligned']['bins'])->toBeEmpty()
        ->and($empty['splits'])->toBeEmpty()
        ->and(array_column($empty['candidates'], 'id'))->toBe([$bike->id, $run1->id, $run2->id]);

    // Single activity state filters candidates to same sport_category and excludes selected
    $single = $service->compare((string) $run1->id);
    expect($single['selected_ids'])->toBe([$run1->id])
        ->and($single['activities'])->toHaveCount(1)
        ->and($single['activities'][0]['is_baseline'])->toBeTrue()
        ->and($single['aligned']['bins'])->toBeEmpty()
        ->and(array_column($single['candidates'], 'id'))->toBe([$run2->id]);
});

it('compares pace instead of raw duration when final splits are partial', function () {
    $base = Activity::factory()->create();
    $other = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'km_splits' => [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 300.0, 'avg_hr' => 150],
            ['km' => 2, 'distance_meters' => 1000.0, 'duration_seconds' => 300.0, 'avg_hr' => 152],
            ['km' => 3, 'distance_meters' => 300.0, 'duration_seconds' => 90.0, 'avg_hr' => 155],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'km_splits' => [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 290.0, 'avg_hr' => 151],
            // Partial vs full baseline split: 500m at 280s/km pace
            ['km' => 2, 'distance_meters' => 500.0, 'duration_seconds' => 140.0, 'avg_hr' => 154],
            // Both partial: 800m at 290s/km vs baseline 300m at 300s/km
            ['km' => 3, 'distance_meters' => 800.0, 'duration_seconds' => 232.0, 'avg_hr' => 158],
        ],
    ]);

    $splits = app(ActivityComparisonService::class)->compare([$base->id, $other->id])['splits'];

    expect($splits[0]['entries'][1]['time_delta_seconds'])->toBe(-10.0)
        ->and($splits[0]['entries'][1]['time_delta_basis'])->toBe('duration')
        ->and($splits[1]['entries'][1]['time_delta_seconds'])->toBe(-20.0)
        ->and($splits[1]['entries'][1]['time_delta_basis'])->toBe('pace')
        ->and($splits[2]['entries'][0]['is_partial'])->toBeTrue()
        ->and($splits[2]['entries'][1]['is_partial'])->toBeTrue()
        ->and($splits[2]['entries'][1]['time_delta_seconds'])->toBe(-10.0)
        ->and($splits[2]['entries'][1]['time_delta_basis'])->toBe('pace')
        ->and($splits[2]['entries'][0]['time_delta_basis'])->toBeNull();
});

it('falls back to split position when stored km numbers are missing or duplicated', function () {
    $base = Activity::factory()->create();
    $other = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'km_splits' => [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 300.0],
            ['distance_meters' => 1000.0, 'duration_seconds' => 310.0],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'km_splits' => [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 295.0],
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 305.0],
        ],
    ]);

    $splits = app(ActivityComparisonService::class)->compare([$base->id, $other->id])['splits'];

    expect($splits)->toHaveCount(2)
        ->and($splits[0]['entries'][1]['time_delta_seconds'])->toBe(-5.0)
        ->and($splits[1]['entries'][0]['duration_seconds'])->toBe(310.0)
        ->and($splits[1]['entries'][1]['time_delta_seconds'])->toBe(-5.0);
});

it('builds mile splits from distance streams for imperial users', function () {
    app(AppSettings::class)->set('unit_system', 'imperial');

    $base = Activity::factory()->create();
    $other = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => makeDistanceSeries(3500.0, 4.0, 140),
        'km_splits' => [['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 250.0]],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'series' => makeDistanceSeries(3400.0, 3.5, 140),
        'km_splits' => [['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 285.7]],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $other->id]);
    $splits = $result['splits'];

    // Mile 1: 1609.344m at 4.0 m/s = 402.3s vs 3.5 m/s = 459.8s (+57.5s)
    // Mile 3 is partial for both (281m vs 181m), so the delta is per-mile pace: same +57.5s
    expect($result['split_unit'])->toBe('mi')
        ->and($splits)->toHaveCount(3)
        ->and($splits[0]['label'])->toBe('Mi 1')
        ->and($splits[0]['entries'][0]['distance_meters'])->toBe(1609.3)
        ->and($splits[0]['entries'][0]['duration_seconds'])->toEqualWithDelta(402.3, 0.2)
        ->and($splits[0]['entries'][0]['pace_seconds_per_km'])->toEqualWithDelta(250.0, 0.2)
        ->and($splits[0]['entries'][1]['time_delta_seconds'])->toEqualWithDelta(57.5, 0.2)
        ->and($splits[0]['entries'][1]['time_delta_basis'])->toBe('duration')
        ->and($splits[0]['entries'][0]['is_partial'])->toBeFalse()
        ->and($splits[2]['entries'][0]['is_partial'])->toBeTrue()
        ->and($splits[2]['entries'][0]['distance_meters'])->toEqualWithDelta(281.3, 0.2)
        ->and($splits[2]['entries'][1]['time_delta_basis'])->toBe('pace')
        ->and($splits[2]['entries'][1]['time_delta_seconds'])->toEqualWithDelta(57.5, 0.3);
});

it('keeps stored km splits for imperial users when an activity lacks a distance stream', function () {
    app(AppSettings::class)->set('unit_system', 'imperial');

    $base = Activity::factory()->create();
    $other = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => makeDistanceSeries(3500.0, 4.0, 140),
        'km_splits' => [['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 250.0]],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'series' => [],
        'km_splits' => [['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 260.0]],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $other->id]);

    expect($result['split_unit'])->toBe('km')
        ->and($result['splits'])->toHaveCount(1)
        ->and($result['splits'][0]['label'])->toBe('Km 1')
        ->and($result['splits'][0]['entries'][1]['time_delta_seconds'])->toBe(10.0);
});

it('includes stopped time in the distance-aligned ghost gap and ignores backwards distance steps', function () {
    $base = Activity::factory()->create();
    $paused = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => [
            ['time' => 0, 'distance' => 0],
            ['time' => 500, 'distance' => 2000],
            ['time' => 1000, 'distance' => 4000],
        ],
    ]);

    // Same 4 m/s pace, but a 120s stop at 2000m and a GPS glitch stepping back to 1995m
    ActivityStream::factory()->create([
        'activity_id' => $paused->id,
        'series' => [
            ['time' => 0, 'distance' => 0],
            ['time' => 487.5, 'distance' => 1950],
            ['time' => 500, 'distance' => 2000],
            ['time' => 560, 'distance' => 1995],
            ['time' => 620, 'distance' => 2000],
            ['time' => 1120, 'distance' => 4000],
        ],
    ]);

    $series = app(ActivityComparisonService::class)
        ->compare([$base->id, $paused->id], 'distance')['aligned']['series'][1];

    // Bin 39 sits at ~981m, before the stop
    expect($series['time_delta_seconds'][39])->toBe(0.0)
        ->and($series['time_delta_seconds'][ActivityComparisonService::SAMPLE_BINS - 1])->toBe(120.0)
        ->and($series['summary']['final_time_delta_seconds'])->toBe(120.0);
});

it('aligns from the first sample with distance when leading distance values are missing', function () {
    $base = Activity::factory()->create();
    $lateLock = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => [
            ['time' => 0, 'distance' => 0],
            ['time' => 1000, 'distance' => 4000],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $lateLock->id,
        'series' => [
            ['time' => 0, 'distance' => null],
            ['time' => 10, 'distance' => null],
            ['time' => 20, 'distance' => 0],
            ['time' => 1020, 'distance' => 4000],
        ],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $lateLock->id], 'distance');
    $series = $result['aligned']['series'][1];

    expect($result['aligned']['axis_max'])->toBe(4000.0)
        ->and($series['elapsed_seconds'][0])->toBe(20.0)
        ->and($series['time_delta_seconds'][0])->toBe(20.0)
        ->and($series['summary']['final_time_delta_seconds'])->toBe(20.0);
});

it('normalises columnar stream series', function () {
    $base = Activity::factory()->create();
    $columnar = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => [
            ['time' => 0, 'distance' => 0, 'hr' => 140],
            ['time' => 1000, 'distance' => 4000, 'hr' => 160],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $columnar->id,
        'series' => [
            'time' => [0, 530, 1060],
            'distance' => [0, 2000, 4000],
            'hr' => [142, 152, 162],
        ],
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $columnar->id]);
    $series = $result['aligned']['series'][1];

    expect($result['activities'][1]['has_streams'])->toBeTrue()
        ->and($series['hr'][0])->toBe(142.0)
        ->and($series['summary']['final_time_delta_seconds'])->toBe(60.0);
});

it('rebases epoch timestamps to elapsed seconds', function () {
    $base = Activity::factory()->create();
    $epoch = Activity::factory()->create();
    $start = 1_763_000_000;

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => [
            ['time' => 0, 'distance' => 0],
            ['time' => 1000, 'distance' => 4000],
        ],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $epoch->id,
        'series' => [
            ['time' => $start, 'distance' => 0],
            ['time' => $start + 1060, 'distance' => 4000],
        ],
    ]);

    $series = app(ActivityComparisonService::class)
        ->compare([$base->id, $epoch->id])['aligned']['series'][1];

    expect($series['elapsed_seconds'][0])->toBe(0.0)
        ->and($series['summary']['final_time_delta_seconds'])->toBe(60.0);
});

it('keeps aligning the remaining activities when one of three has no stream', function () {
    $base = Activity::factory()->create();
    $noStream = Activity::factory()->create();
    $slower = Activity::factory()->create();

    ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'series' => makeDistanceSeries(5000.0, 4.0, 140),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $slower->id,
        'series' => makeDistanceSeries(4000.0, 3.2, 140),
    ]);

    $result = app(ActivityComparisonService::class)->compare([$base->id, $noStream->id, $slower->id]);
    $aligned = $result['aligned'];
    $lastIdx = ActivityComparisonService::SAMPLE_BINS - 1;

    // At 4000m: baseline 1000s vs slower 1250s
    expect($result['align'])->toBe('distance')
        ->and($result['activities'][1]['has_streams'])->toBeFalse()
        ->and($aligned['axis_max'])->toBe(4000.0)
        ->and($aligned['series'][1]['has_stream'])->toBeFalse()
        ->and($aligned['series'][1]['time_delta_seconds'])->each->toBeNull()
        ->and($aligned['series'][1]['summary']['final_time_delta_seconds'])->toBeNull()
        ->and($aligned['series'][2]['time_delta_seconds'][$lastIdx])->toBe(250.0);
});

it('falls back to distance alignment when time alignment is impossible', function () {
    $a = Activity::factory()->create();
    $b = Activity::factory()->create();

    foreach ([$a, $b] as $activity) {
        ActivityStream::factory()->create([
            'activity_id' => $activity->id,
            'series' => [
                ['time' => 0, 'distance' => 0],
                ['time' => 0, 'distance' => 1000],
            ],
        ]);
    }

    $result = app(ActivityComparisonService::class)->compare([$a->id, $b->id], 'time');

    expect($result['align'])->toBe('distance')
        ->and($result['requested_align'])->toBe('time')
        ->and($result['fallback_reason'])->toBe('missing_time_stream')
        ->and($result['aligned']['axis_max'])->toBe(1000.0);
});
