<?php

use App\Models\Activity;
use App\Services\Sports\EddingtonService;
use App\Services\Sports\EnduranceHubService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'Pacific/Auckland'));
});

it('returns a clean zeroed Eddington payload when no activities exist', function () {
    $payload = app(EddingtonService::class)->forQuery(Activity::query());

    expect($payload['active_days'])->toBe(0)
        ->and($payload['max_single_day_meters'])->toBe(0.0)
        ->and($payload['metric']['unit'])->toBe('km')
        ->and($payload['metric']['number'])->toBe(0)
        ->and($payload['metric']['next_target'])->toBe(1)
        ->and($payload['metric']['completed_for_next'])->toBe(0)
        ->and($payload['metric']['days_needed_for_next'])->toBe(1)
        ->and($payload['metric']['next_targets'])->toBe([
            ['target' => 1, 'completed_days' => 0, 'remaining_days' => 1],
            ['target' => 2, 'completed_days' => 0, 'remaining_days' => 2],
            ['target' => 3, 'completed_days' => 0, 'remaining_days' => 3],
        ])
        ->and($payload['imperial']['unit'])->toBe('mi')
        ->and($payload['imperial']['number'])->toBe(0)
        ->and($payload['imperial']['days_needed_for_next'])->toBe(1);
});

it('aggregates multiple activities on the same local calendar day before computing Eddington number', function () {
    // Day 1: two 3.5 km runs -> 7 km total on a single day
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'distance_meters' => 3500.0,
    ]);
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 18:00:00'),
        'distance_meters' => 3500.0,
    ]);

    // Day 2: 6 km run
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-11 07:00:00'),
        'distance_meters' => 6000.0,
    ]);

    // Day 3: 5 km run
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 5000.0,
    ]);

    // Day 4: 4 km run -> 4 days >= 4 km, so E = 4 km (NOT 3 days if Day 1 wasn't combined)
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-13 07:00:00'),
        'distance_meters' => 4000.0,
    ]);

    $payload = app(EddingtonService::class)->forQuery(Activity::query());

    expect($payload['active_days'])->toBe(4)
        ->and($payload['max_single_day_meters'])->toBe(7000.0)
        ->and($payload['metric']['number'])->toBe(4)
        ->and($payload['metric']['completed_for_next'])->toBe(3)
        ->and($payload['metric']['days_needed_for_next'])->toBe(2)
        ->and($payload['metric']['next_targets'])->toBe([
            ['target' => 5, 'completed_days' => 3, 'remaining_days' => 2],
            ['target' => 6, 'completed_days' => 2, 'remaining_days' => 4],
            ['target' => 7, 'completed_days' => 1, 'remaining_days' => 6],
        ]);
});

it('computes exact metric and imperial Eddington numbers and next targets', function () {
    // Daily distances: 10 km, 9 km, 8 km, 6 km, 5 km, 4 km, 3 km
    // Metric (km):
    //   5 days >= 5 km (10, 9, 8, 6, 5) -> E = 5 km
    //   For E = 6 km: 4 days >= 6 km (10, 9, 8, 6) -> needs 2 more days
    // Imperial (mi): 6.21, 5.59, 4.97, 3.73, 3.11, 2.49, 1.86
    //   3 days >= 3 mi (6.21, 5.59, 4.97, 3.73, 3.11 -> actually 5 days >= 3 mi! And 3 days >= 4 mi: 6.21, 5.59, 4.97 -> 3 < 4)
    //   So E = 3 mi; for E = 4 mi: 3 days >= 4 mi -> needs 1 more day!
    $payload = app(EddingtonService::class)->fromDailyDistances([
        10000.0,
        9000.0,
        8000.0,
        6000.0,
        5000.0,
        4000.0,
        3000.0,
    ]);

    expect($payload['active_days'])->toBe(7)
        ->and($payload['max_single_day_meters'])->toBe(10000.0)
        ->and($payload['metric']['number'])->toBe(5)
        ->and($payload['metric']['next_target'])->toBe(6)
        ->and($payload['metric']['completed_for_next'])->toBe(4)
        ->and($payload['metric']['days_needed_for_next'])->toBe(2)
        ->and($payload['metric']['next_targets'])->toBe([
            ['target' => 6, 'completed_days' => 4, 'remaining_days' => 2],
            ['target' => 7, 'completed_days' => 3, 'remaining_days' => 4],
            ['target' => 8, 'completed_days' => 3, 'remaining_days' => 5],
        ])
        ->and($payload['imperial']['number'])->toBe(3)
        ->and($payload['imperial']['next_target'])->toBe(4)
        ->and($payload['imperial']['completed_for_next'])->toBe(3)
        ->and($payload['imperial']['days_needed_for_next'])->toBe(1)
        ->and($payload['imperial']['next_targets'])->toBe([
            ['target' => 4, 'completed_days' => 3, 'remaining_days' => 1],
            ['target' => 5, 'completed_days' => 2, 'remaining_days' => 3],
            ['target' => 6, 'completed_days' => 1, 'remaining_days' => 5],
        ]);
});

it('includes scoped Eddington numbers in EnduranceHubService payloads for running and cycling hubs', function () {
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 40000.0,
        'is_indoor' => false,
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 55000.0,
        'is_indoor' => false,
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 30000.0,
        'is_indoor' => true,
    ]);

    Activity::factory()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:00:00'),
        'distance_meters' => 10000.0,
    ]);

    app(SportRegistry::class)->flushMemo();
    $hubService = app(EnduranceHubService::class);

    $allRoadCycling = $hubService->hub('road_cycling');
    $outdoorRoadCycling = $hubService->hub('road_cycling', environment: 'outdoor');
    $roadRunning = $hubService->hub('road_running');

    expect($allRoadCycling['eddington'])->not->toBeNull()
        ->and($allRoadCycling['eddington']['active_days'])->toBe(3)
        ->and($allRoadCycling['eddington']['max_single_day_meters'])->toBe(55000.0)
        ->and($allRoadCycling['eddington']['metric']['number'])->toBe(3)
        ->and($allRoadCycling['eddington']['imperial']['number'])->toBe(3)
        ->and($outdoorRoadCycling['eddington']['active_days'])->toBe(2)
        ->and($outdoorRoadCycling['eddington']['metric']['number'])->toBe(2)
        ->and($roadRunning['eddington']['active_days'])->toBe(1)
        ->and($roadRunning['eddington']['metric']['number'])->toBe(1);
});
