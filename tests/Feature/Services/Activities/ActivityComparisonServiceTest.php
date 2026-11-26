<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Services\Activities\ActivityComparisonService;
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
        ->and($fasterSeries['summary']['max_ahead_seconds'])->toBe(-400.0)
        ->and($slowerSeries['time_delta_seconds'][$lastIdx])->toBe(800.0)
        ->and($slowerSeries['summary']['final_time_delta_seconds'])->toBe(800.0)
        ->and($slowerSeries['summary']['max_behind_seconds'])->toBe(800.0);
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
