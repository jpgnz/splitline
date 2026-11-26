<?php

use App\Models\Activity;
use App\Models\BestEffort;
use App\Services\Garmin\BestEffortCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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
