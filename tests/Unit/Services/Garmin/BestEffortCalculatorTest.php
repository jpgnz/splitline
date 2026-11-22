<?php

use App\Models\Activity;
use App\Models\BestEffort;
use App\Services\Garmin\BestEffortCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('finds the fastest sliding-window segment with linear interpolation and start offset', function () {
    // 0..500m at 3.0 m/s (slow warmup: 166.67s)
    // 500..1500m at 5.0 m/s (fast 1k surge: 200s)
    // 1500..2000m at 3.0 m/s (cooldown)
    $records = [];
    $time = 0.0;
    $distance = 0.0;

    // Warmup: 166s @ 3.0 m/s -> 498m, then 1s @ 2m to reach 500m at t=167
    for ($s = 0; $s <= 166; $s++) {
        $records[] = ['time' => (float) $s, 'distance' => $s * 3.0, 'speed' => 3.0];
    }

    $time = 167.0;
    $distance = 500.0;
    $records[] = ['time' => $time, 'distance' => $distance, 'speed' => 5.0];

    // Fast 1k surge: 200s @ 5.0 m/s -> 1500m at t=367
    for ($s = 1; $s <= 200; $s++) {
        $records[] = [
            'time' => $time + $s,
            'distance' => $distance + ($s * 5.0),
            'speed' => 5.0,
        ];
    }

    $time = 367.0;
    $distance = 1500.0;

    // Cooldown: 167s @ 3.0 m/s -> 2001m
    for ($s = 1; $s <= 167; $s++) {
        $records[] = [
            'time' => $time + $s,
            'distance' => $distance + ($s * 3.0),
            'speed' => 3.0,
        ];
    }

    $efforts = collect((new BestEffortCalculator)->calculate($records, 'running'))
        ->keyBy('distance_key');

    expect($efforts->keys()->all())->toBe(['400m', 'half_mile', '1k', '1mi']);

    // 400m at 5.0 m/s = 80.0s, starting inside the surge
    expect($efforts['400m']['elapsed_seconds'])->toBe(80.0)
        ->and($efforts['400m']['moving_seconds'])->toBe(80.0)
        ->and($efforts['400m']['start_offset_seconds'])->toBeGreaterThanOrEqual(167.0);

    // half_mile (804.67m) at 5.0 m/s = 160.934s -> linearly interpolated to 160.93s
    expect($efforts['half_mile']['elapsed_seconds'])->toBe(160.93);

    // 1k (1000m) at 5.0 m/s = exactly 200.0s starting at t=167.0
    expect($efforts['1k']['elapsed_seconds'])->toBe(200.0)
        ->and($efforts['1k']['moving_seconds'])->toBe(200.0)
        ->and($efforts['1k']['start_offset_seconds'])->toBe(167.0);
});

it('counts pauses in elapsed_seconds while excluding stationary intervals from moving_seconds', function () {
    $records = [];

    // 0..500m in 125s @ 4.0 m/s
    for ($s = 0; $s <= 125; $s++) {
        $records[] = ['time' => (float) $s, 'distance' => $s * 4.0, 'speed' => 4.0];
    }

    // 30s complete stop at 500m
    for ($s = 126; $s <= 155; $s++) {
        $records[] = ['time' => (float) $s, 'distance' => 500.0, 'speed' => 0.0];
    }

    // 500..1000m in 125s @ 4.0 m/s (t = 156..280)
    for ($s = 1; $s <= 125; $s++) {
        $records[] = ['time' => 155.0 + $s, 'distance' => 500.0 + ($s * 4.0), 'speed' => 4.0];
    }

    $efforts = collect((new BestEffortCalculator)->calculate($records, 'running'))
        ->keyBy('distance_key');

    // 400m avoids the pause completely -> 100s elapsed, 100s moving
    expect($efforts['400m']['elapsed_seconds'])->toBe(100.0)
        ->and($efforts['400m']['moving_seconds'])->toBe(100.0);

    // 1k spans the 30s stop -> 280s elapsed (race clock), 250s moving
    expect($efforts['1k']['elapsed_seconds'])->toBe(280.0)
        ->and($efforts['1k']['moving_seconds'])->toBe(250.0)
        ->and($efforts['1k']['start_offset_seconds'])->toBe(0.0);
});

it('calculates road biking benchmarks across 5k, 10k, 20k, and 40k', function () {
    // 42 km ride at constant 10.0 m/s (36 km/h) sampled every 10 seconds
    $records = [];

    for ($s = 0; $s <= 4200; $s += 10) {
        $records[] = ['time' => (float) $s, 'distance' => $s * 10.0, 'speed' => 10.0];
    }

    $efforts = collect((new BestEffortCalculator)->calculate($records, 'road_biking'))
        ->keyBy('distance_key');

    expect($efforts->keys()->all())->toBe(['5k', '10k', '20k', '40k'])
        ->and($efforts['5k']['elapsed_seconds'])->toBe(500.0)
        ->and($efforts['10k']['elapsed_seconds'])->toBe(1000.0)
        ->and($efforts['20k']['elapsed_seconds'])->toBe(2000.0)
        ->and($efforts['40k']['elapsed_seconds'])->toBe(4000.0);
});

it('excludes indoor runs and MTB rides while syncing outdoor runs and road rides and preserving overrides', function () {
    $records = [];

    for ($s = 0; $s <= 1300; $s += 5) {
        $records[] = ['time' => (float) $s, 'distance' => $s * 4.0, 'speed' => 4.0]; // 5200m
    }

    $calculator = new BestEffortCalculator;

    $indoorRun = Activity::factory()->indoor()->create([
        'sport_category' => 'running',
        'activity_type_key' => 'treadmill_running',
    ]);
    $mtbRide = Activity::factory()->mountainBiking()->create();
    $otherRide = Activity::factory()->otherBiking()->create();
    $overriddenRoadRide = Activity::factory()->otherBiking()->create([
        'discipline_override' => 'road',
    ]);
    $outdoorRun = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
    ]);

    BestEffort::factory()->create([
        'activity_id' => $outdoorRun->id,
        'sport' => 'running',
        'distance_key' => '1k',
        'distance_meters' => 1000.0,
        'elapsed_seconds' => 260.0,
        'source' => 'garmin_split',
        'override_seconds' => 245.5,
        'is_excluded' => true,
    ]);

    expect($calculator->syncForActivity($indoorRun, $records))->toBe(0)
        ->and($calculator->syncForActivity($mtbRide, $records))->toBe(0)
        ->and($calculator->syncForActivity($otherRide, $records))->toBe(0);

    // Overridden road ride gets 5k road_biking effort
    expect($calculator->syncForActivity($overriddenRoadRide, $records))->toBe(1);
    expect(BestEffort::query()->where('activity_id', $overriddenRoadRide->id)->first())
        ->sport->toBe('road_biking')
        ->distance_key->toBe('5k')
        ->elapsed_seconds->toBe(1250.0);

    // Outdoor run gets 400m, half_mile, 1k, 1mi, 2mi, 5k (6 efforts)
    expect($calculator->syncForActivity($outdoorRun, $records))->toBe(6);

    $updated1k = BestEffort::query()
        ->where('activity_id', $outdoorRun->id)
        ->where('distance_key', '1k')
        ->first();

    expect($updated1k)->not->toBeNull()
        ->and($updated1k->source)->toBe('fit_stream')
        ->and($updated1k->elapsed_seconds)->toBe(250.0)
        ->and($updated1k->override_seconds)->toBe(245.5)
        ->and($updated1k->is_excluded)->toBeTrue();
});

it('records avg_hr, net elevation_delta, and distance window bounds for each best effort', function () {
    $records = [];

    // 0..1000m downhill (-50m, HR 152 bpm) at 4.0 m/s
    for ($s = 0; $s <= 250; $s++) {
        $dist = $s * 4.0;
        $records[] = [
            'time' => (float) $s,
            'distance' => $dist,
            'speed' => 4.0,
            'hr' => 152,
            'altitude' => 200.0 - ($dist * 0.05),
        ];
    }

    $efforts = collect((new BestEffortCalculator)->calculate($records, 'running'))
        ->keyBy('distance_key');

    expect($efforts['1k']['avg_hr'])->toBe(152)
        ->and($efforts['1k']['elevation_delta'])->toBe(-50.0)
        ->and($efforts['1k']['start_distance_meters'])->toBe(0.0)
        ->and($efforts['1k']['end_distance_meters'])->toBe(1000.0);
});
