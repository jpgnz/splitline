<?php

use App\Models\Activity;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'Pacific/Auckland'));
});

it('falls back to unified running and cycling starter defaults when no activities exist', function () {
    $registry = app(SportRegistry::class);

    $config = $registry->resolvedConfig();

    expect($config['mode'])->toBe('auto')
        ->and($config['families']['running'])->toBe(['mode' => 'unified', 'disciplines' => ['road_running']])
        ->and($config['families']['cycling'])->toBe(['mode' => 'unified', 'disciplines' => ['road_cycling']])
        ->and($config['secondary'])->toBe([])
        ->and($registry->activeSportKeys())->toBe(['running', 'cycling']);
});

it('auto-detects unified running and disables cycling when only road runs exist', function () {
    Activity::factory()->count(2)->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
    ]);

    $registry = app(SportRegistry::class);
    $registry->flushMemo();

    $config = $registry->resolvedConfig();

    expect($config['families']['running']['mode'])->toBe('unified')
        ->and($config['families']['running']['disciplines'])->toBe(['road_running'])
        ->and($config['families']['cycling']['mode'])->toBe('off')
        ->and($registry->familyTabs('running'))->toBe([])
        ->and($registry->activeSportKeys())->toBe(['running']);
});

it('auto-detects split mode for running and cycling when multiple disciplines exist', function () {
    Activity::factory()->create(['sport_category' => 'running', 'sport_discipline' => 'road']);
    Activity::factory()->trailRunning()->create();
    Activity::factory()->roadBiking()->create();
    Activity::factory()->gravelCycling()->create();
    Activity::factory()->mountainBiking()->create();

    $registry = app(SportRegistry::class);
    $registry->flushMemo();

    $config = $registry->resolvedConfig();
    $runningTabs = $registry->familyTabs('running');
    $cyclingTabs = $registry->familyTabs('cycling');

    expect($config['families']['running']['mode'])->toBe('split')
        ->and($config['families']['running']['disciplines'])->toBe(['road_running', 'trail_running'])
        ->and($config['families']['cycling']['mode'])->toBe('split')
        ->and($config['families']['cycling']['disciplines'])->toBe(['road_cycling', 'gravel_cycling', 'mtb_cycling'])
        ->and($config['rollups']['trail_running'])->toBe('own')
        ->and($config['best_efforts']['trail_in_running_prs'])->toBeFalse()
        ->and(array_column($runningTabs, 'key'))->toBe(['running', 'road_running', 'trail_running'])
        ->and(array_column($cyclingTabs, 'key'))->toBe(['cycling', 'road_cycling', 'gravel_cycling', 'mtb_cycling']);
});

it('persists custom sports configuration and supports returning to auto mode', function () {
    $registry = app(SportRegistry::class);

    $saved = $registry->saveConfig([
        'mode' => 'custom',
        'pin_sub_disciplines' => true,
        'order' => ['cycling', 'hiking', 'running', 'swimming'],
        'families' => [
            'running' => ['mode' => 'split', 'disciplines' => ['road_running', 'trail_running', 'invalid_key']],
            'cycling' => ['mode' => 'unified', 'disciplines' => ['road_cycling']],
        ],
        'secondary' => ['hiking', 'swimming', 'unknown_sport'],
        'rollups' => [
            'trail_running' => 'own',
            'track_running' => 'road_running',
            'gravel_cycling' => 'road_cycling',
            'indoor_cycling' => 'own',
            'ebike_cycling' => 'exclude',
        ],
        'best_efforts' => [
            'trail_in_running_prs' => true,
            'cycling_disciplines' => ['road', 'gravel'],
        ],
    ]);

    expect($saved['mode'])->toBe('custom')
        ->and($saved['pin_sub_disciplines'])->toBeTrue()
        ->and($saved['families']['running']['disciplines'])->toBe(['road_running', 'trail_running'])
        ->and($saved['secondary'])->toBe(['hiking', 'swimming'])
        ->and($saved['rollups']['gravel_cycling'])->toBe('road_cycling')
        ->and($saved['rollups']['ebike_cycling'])->toBe('exclude')
        ->and($saved['best_efforts']['trail_in_running_prs'])->toBeTrue()
        ->and($saved['best_efforts']['cycling_disciplines'])->toBe(['road', 'gravel'])
        ->and($registry->activeSportKeys())->toBe(['cycling', 'hiking', 'road_running', 'trail_running', 'swimming']);

    $auto = $registry->saveConfig(['mode' => 'auto', 'pin_sub_disciplines' => false]);

    expect($auto['mode'])->toBe('auto')
        ->and($auto['pin_sub_disciplines'])->toBeFalse();
});

it('applies discipline rollup rules when scoping activity queries', function () {
    $roadRide = Activity::factory()->roadBiking()->create(['is_indoor' => false]);
    $indoorRide = Activity::factory()->roadBiking()->indoor()->create();
    $gravelRide = Activity::factory()->gravelCycling()->create();
    $mtbRide = Activity::factory()->mountainBiking()->create();
    $ebikeRide = Activity::factory()->create([
        'activity_type_key' => 'e_bike_mountain',
        'sport_category' => 'biking',
        'sport_discipline' => 'ebike',
    ]);
    $roadRun = Activity::factory()->create(['sport_category' => 'running', 'sport_discipline' => 'road']);
    $trackRun = Activity::factory()->create([
        'activity_type_key' => 'track_running',
        'sport_category' => 'running',
        'sport_discipline' => 'track',
    ]);

    $registry = app(SportRegistry::class);
    $registry->saveConfig([
        'mode' => 'custom',
        'families' => [
            'running' => ['mode' => 'split', 'disciplines' => ['road_running']],
            'cycling' => ['mode' => 'split', 'disciplines' => ['road_cycling', 'mtb_cycling', 'indoor_cycling']],
        ],
        'rollups' => [
            'trail_running' => 'own',
            'track_running' => 'road_running',
            'gravel_cycling' => 'road_cycling',
            'indoor_cycling' => 'own',
            'ebike_cycling' => 'exclude',
        ],
    ]);

    $roadCycleIds = $registry->applyScope(Activity::query(), 'road_cycling')->pluck('id')->all();
    $indoorCycleIds = $registry->applyScope(Activity::query(), 'indoor_cycling')->pluck('id')->all();
    $allCycleIds = $registry->applyScope(Activity::query(), 'cycling')->pluck('id')->all();
    $roadRunIds = $registry->applyScope(Activity::query(), 'road_running')->pluck('id')->all();

    expect($roadCycleIds)->toEqualCanonicalizing([$roadRide->id, $gravelRide->id])
        ->and($indoorCycleIds)->toBe([$indoorRide->id])
        ->and($allCycleIds)->not->toContain($ebikeRide->id)
        ->and($allCycleIds)->toContain($roadRide->id, $mtbRide->id)
        ->and($roadRunIds)->toEqualCanonicalizing([$roadRun->id, $trackRun->id]);

    $registry->saveConfig([
        'mode' => 'custom',
        'rollups' => [
            'gravel_cycling' => 'mtb_cycling',
            'ebike_cycling' => 'mtb_cycling',
        ],
    ]);

    $mtbRolledIds = $registry->applyScope(Activity::query(), 'mtb_cycling')->pluck('id')->all();

    expect($mtbRolledIds)->toEqualCanonicalizing([$mtbRide->id, $gravelRide->id, $ebikeRide->id]);
});

it('filters activities by environment and terrain climbing density buckets', function () {
    $flatRide = Activity::factory()->mountainBiking()->create([
        'distance_meters' => 30000.0,
        'elevation_gain' => 150.0, // 5 m/km -> flat
        'is_indoor' => false,
    ]);
    $rollingRide = Activity::factory()->mountainBiking()->create([
        'distance_meters' => 20000.0,
        'elevation_gain' => 320.0, // 16 m/km -> rolling
        'is_indoor' => false,
    ]);
    $steepRide = Activity::factory()->mountainBiking()->create([
        'distance_meters' => 25000.0,
        'elevation_gain' => 1100.0, // 44 m/km -> steep
        'is_indoor' => false,
    ]);
    $indoorRide = Activity::factory()->mountainBiking()->indoor()->create([
        'distance_meters' => 20000.0,
        'elevation_gain' => 0.0,
    ]);

    $registry = app(SportRegistry::class);

    expect($registry->applyScope(Activity::query(), 'mtb_cycling', 'outdoor', 'flat')->pluck('id')->all())
        ->toBe([$flatRide->id])
        ->and($registry->applyScope(Activity::query(), 'mtb_cycling', 'outdoor', 'rolling')->pluck('id')->all())
        ->toBe([$rollingRide->id])
        ->and($registry->applyScope(Activity::query(), 'mtb_cycling', 'outdoor', 'steep')->pluck('id')->all())
        ->toBe([$steepRide->id])
        ->and($registry->applyScope(Activity::query(), 'mtb_cycling', 'indoor', 'all')->pluck('id')->all())
        ->toBe([$indoorRide->id]);
});

it('resolves best effort sport keys according to indoor status and user rules', function () {
    $registry = app(SportRegistry::class);
    $registry->saveConfig([
        'mode' => 'custom',
        'best_efforts' => [
            'trail_in_running_prs' => false,
            'cycling_disciplines' => ['road', 'gravel'],
        ],
    ]);

    $roadRun = Activity::factory()->make(['sport_category' => 'running', 'sport_discipline' => 'road', 'is_indoor' => false]);
    $treadmillRun = Activity::factory()->indoor()->make(['sport_category' => 'running', 'sport_discipline' => 'road']);
    $trailRun = Activity::factory()->trailRunning()->make(['is_indoor' => false]);
    $roadRide = Activity::factory()->roadBiking()->make(['is_indoor' => false]);
    $gravelRide = Activity::factory()->gravelCycling()->make(['is_indoor' => false]);
    $mtbRide = Activity::factory()->mountainBiking()->make(['is_indoor' => false]);

    expect($registry->resolveBestEffortSport($roadRun))->toBe('running')
        ->and($registry->resolveBestEffortSport($treadmillRun))->toBeNull()
        ->and($registry->resolveBestEffortSport($trailRun))->toBe('trail_running')
        ->and($registry->resolveBestEffortSport($roadRide))->toBe('road_biking')
        ->and($registry->resolveBestEffortSport($gravelRide))->toBe('gravel_cycling')
        ->and($registry->resolveBestEffortSport($mtbRide))->toBeNull();

    $registry->saveConfig([
        'mode' => 'custom',
        'best_efforts' => [
            'trail_in_running_prs' => true,
            'cycling_disciplines' => ['road', 'mtb'],
        ],
    ]);

    expect($registry->resolveBestEffortSport($trailRun))->toBe('running')
        ->and($registry->resolveBestEffortSport($mtbRide))->toBe('mtb_cycling')
        ->and($registry->resolveBestEffortSport($gravelRide))->toBeNull();
});

it('surfaces discovery hints in settingsPayload for disabled disciplines and secondary sports with recorded activities', function () {
    Activity::factory()->gravelCycling()->count(3)->create();
    Activity::factory()->swimming()->count(2)->create();
    Activity::factory()->hiking()->create();

    $registry = app(SportRegistry::class);
    $registry->saveConfig([
        'mode' => 'custom',
        'families' => [
            'running' => ['mode' => 'unified', 'disciplines' => ['road_running']],
            'cycling' => ['mode' => 'split', 'disciplines' => ['road_cycling']],
        ],
        'secondary' => ['hiking'],
    ]);

    $payload = $registry->settingsPayload();
    $hintsByKey = collect($payload['hints'])->keyBy('key');

    expect($hintsByKey->has('gravel_cycling'))->toBeTrue()
        ->and($hintsByKey->get('gravel_cycling')['count'])->toBe(3)
        ->and($hintsByKey->has('swimming'))->toBeTrue()
        ->and($hintsByKey->get('swimming')['count'])->toBe(2)
        ->and($hintsByKey->has('hiking'))->toBeFalse();
});
