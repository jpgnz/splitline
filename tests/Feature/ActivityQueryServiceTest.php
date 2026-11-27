<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use App\Models\HeartRateZone;
use App\Models\WeightMeasurement;
use App\Services\Activities\ActivityQueryService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('builds a clean empty-state list payload when no activities exist', function () {
    $payload = app(ActivityQueryService::class)->list();

    expect($payload['summary'])->toBe([
        'count' => 0,
        'total_distance_meters' => 0.0,
        'total_duration_seconds' => 0.0,
        'total_elevation_gain' => 0.0,
        'total_calories' => 0,
        'avg_hr' => null,
        'total_load' => 0.0,
    ])
        ->and($payload['activities']['data'])->toBe([])
        ->and($payload['activities']['total'])->toBe(0)
        ->and($payload['activities']['per_page'])->toBe(25)
        ->and($payload['calendar']['month'])->toBe('2026-11')
        ->and($payload['calendar']['monthLabel'])->toBe('November 2026')
        ->and($payload['calendar']['prevMonth'])->toBe('2026-10')
        ->and($payload['calendar']['nextMonth'])->toBe('2026-12')
        ->and($payload['excluded_count'])->toBe(0)
        ->and($payload['filters'])->toBe([
            'search' => '',
            'sport' => 'all',
            'range' => 'all',
            'from' => null,
            'to' => null,
            'pr' => false,
            'favorite' => false,
            'excluded' => false,
            'view' => 'table',
            'month' => '2026-11',
            'sort' => 'date',
            'dir' => 'desc',
        ]);
});

it('filters activities by case-insensitive substring search on name and location', function () {
    $portHills = Activity::factory()->create([
        'name' => 'Port Hills Ridge Run',
        'location_name' => 'Christchurch',
    ]);

    $queenstown = Activity::factory()->mountainBiking()->create([
        'name' => 'Evening Singletrack',
        'location_name' => 'Queenstown Bike Park',
    ]);

    Activity::factory()->create([
        'name' => 'Track 100% Effort',
        'location_name' => 'Auckland',
    ]);

    $service = app(ActivityQueryService::class);

    $byName = $service->list(['search' => 'ridge']);
    expect($byName['summary']['count'])->toBe(1)
        ->and($byName['activities']['data'][0]['id'])->toBe($portHills->id)
        ->and($byName['filters']['search'])->toBe('ridge');

    $byLocation = $service->list(['search' => 'QUEENSTOWN']);
    expect($byLocation['summary']['count'])->toBe(1)
        ->and($byLocation['activities']['data'][0]['id'])->toBe($queenstown->id);

    $literalPercent = $service->list(['search' => '100%']);
    expect($literalPercent['summary']['count'])->toBe(1)
        ->and($literalPercent['activities']['data'][0]['name'])->toBe('Track 100% Effort');
});

it('filters by sport category and respects effective biking discipline overrides', function () {
    $run = Activity::factory()->create([
        'name' => 'Tempo Run',
        'sport_category' => 'running',
    ]);

    $road = Activity::factory()->roadBiking()->create([
        'name' => 'Tamaki Drive Road',
    ]);

    $mtb = Activity::factory()->mountainBiking()->create([
        'name' => 'Woodhill MTB',
    ]);

    $reclassifiedToMtb = Activity::factory()->otherBiking()->create([
        'name' => 'Generic Ride Now MTB',
        'discipline_override' => 'mtb',
    ]);

    $reclassifiedToRoad = Activity::factory()->otherBiking()->create([
        'name' => 'Generic Ride Now Road',
        'discipline_override' => 'road',
    ]);

    $otherBike = Activity::factory()->otherBiking()->create([
        'name' => 'Commute Bike',
    ]);

    $walk = Activity::factory()->create([
        'name' => 'Morning Walk',
        'sport_category' => 'walking',
        'activity_type_key' => 'walking',
    ]);

    $service = app(ActivityQueryService::class);

    $running = $service->list(['sport' => 'running']);
    expect(array_column($running['activities']['data'], 'id'))->toBe([$run->id]);

    $roadResults = $service->list(['sport' => 'road']);
    expect(array_column($roadResults['activities']['data'], 'id'))
        ->toEqualCanonicalizing([$road->id, $reclassifiedToRoad->id]);

    $mtbResults = $service->list(['sport' => 'mtb']);
    expect(array_column($mtbResults['activities']['data'], 'id'))
        ->toEqualCanonicalizing([$mtb->id, $reclassifiedToMtb->id]);

    $otherBikeResults = $service->list(['sport' => 'biking_other']);
    expect(array_column($otherBikeResults['activities']['data'], 'id'))->toBe([$otherBike->id]);

    $walkResults = $service->list(['sport' => 'walking']);
    expect(array_column($walkResults['activities']['data'], 'id'))->toBe([$walk->id]);
});

it('filters by date range presets, custom ranges, PRs, and favorites', function () {
    $recentPr = Activity::factory()->create([
        'name' => 'Recent PR',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'is_pr' => true,
        'is_favorite' => false,
    ]);

    $earlierThisYearFav = Activity::factory()->create([
        'name' => 'Winter Favorite',
        'started_at_local' => CarbonImmutable::parse('2026-06-12 08:00:00'),
        'is_pr' => false,
        'is_favorite' => true,
    ]);

    $lastYearBoth = Activity::factory()->create([
        'name' => '2025 Race',
        'started_at_local' => CarbonImmutable::parse('2025-10-05 09:00:00'),
        'is_pr' => true,
        'is_favorite' => true,
    ]);

    $service = app(ActivityQueryService::class);

    expect(array_column($service->list(['range' => '30d'])['activities']['data'], 'id'))
        ->toBe([$recentPr->id]);

    expect(array_column($service->list(['range' => 'this_year'])['activities']['data'], 'id'))
        ->toEqualCanonicalizing([$recentPr->id, $earlierThisYearFav->id]);

    expect(array_column($service->list(['range' => 'last_year'])['activities']['data'], 'id'))
        ->toBe([$lastYearBoth->id]);

    $custom = $service->list([
        'range' => 'custom',
        'from' => '2026-06-01',
        'to' => '2026-06-30',
    ]);
    expect(array_column($custom['activities']['data'], 'id'))->toBe([$earlierThisYearFav->id])
        ->and($custom['filters']['from'])->toBe('2026-06-01')
        ->and($custom['filters']['to'])->toBe('2026-06-30');

    expect(array_column($service->list(['pr' => '1'])['activities']['data'], 'id'))
        ->toEqualCanonicalizing([$recentPr->id, $lastYearBoth->id]);

    expect(array_column($service->list(['favorite' => 'true'])['activities']['data'], 'id'))
        ->toEqualCanonicalizing([$earlierThisYearFav->id, $lastYearBoth->id]);

    expect(array_column($service->list(['pr' => true, 'favorite' => true])['activities']['data'], 'id'))
        ->toBe([$lastYearBoth->id]);
});

it('sorts activities accurately across all sortable columns and places nulls last', function () {
    $low = Activity::factory()->create([
        'name' => 'Easy Jog',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1500.0,
        'elevation_gain' => 50.0,
        'avg_hr' => 138,
        'training_load' => 45.0,
    ]);

    $high = Activity::factory()->create([
        'name' => 'Long Climb',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 25000.0,
        'duration_seconds' => 6000.0,
        'elevation_gain' => 850.0,
        'avg_hr' => 166,
        'training_load' => 210.0,
    ]);

    $noTelemetry = Activity::factory()->create([
        'name' => 'Manual Session',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'elevation_gain' => null,
        'avg_hr' => null,
        'training_load' => null,
    ]);

    $service = app(ActivityQueryService::class);

    expect(array_column($service->list(['sort' => 'distance', 'dir' => 'desc'])['activities']['data'], 'id'))
        ->toBe([$high->id, $noTelemetry->id, $low->id]);

    expect(array_column($service->list(['sort' => 'duration', 'dir' => 'asc'])['activities']['data'], 'id'))
        ->toBe([$low->id, $noTelemetry->id, $high->id]);

    expect(array_column($service->list(['sort' => 'elevation', 'dir' => 'desc'])['activities']['data'], 'id'))
        ->toBe([$high->id, $low->id, $noTelemetry->id]);

    expect(array_column($service->list(['sort' => 'hr', 'dir' => 'asc'])['activities']['data'], 'id'))
        ->toBe([$low->id, $high->id, $noTelemetry->id]);

    expect(array_column($service->list(['sort' => 'load', 'dir' => 'desc'])['activities']['data'], 'id'))
        ->toBe([$high->id, $low->id, $noTelemetry->id]);
});

it('calculates dynamic summary totals over the full filtered query before pagination and flags synced streams', function () {
    $activities = Activity::factory()
        ->count(28)
        ->sequence(fn ($sequence) => [
            'started_at_local' => CarbonImmutable::parse('2026-11-21 06:00:00')->subDays($sequence->index),
            'distance_meters' => 10000.0,
            'duration_seconds' => 3000.0,
            'elevation_gain' => 100.0,
            'calories' => 600,
            'avg_hr' => $sequence->index % 2 === 0 ? 150 : 160,
            'training_load' => 80.5,
            'streams_synced_at' => $sequence->index === 0 ? CarbonImmutable::parse('2026-11-21 08:00:00') : null,
        ])
        ->create();

    ActivityStream::factory()->create([
        'activity_id' => $activities[1]->id,
    ]);

    $payload = app(ActivityQueryService::class)->list();

    expect($payload['summary'])->toBe([
        'count' => 28,
        'total_distance_meters' => 280000.0,
        'total_duration_seconds' => 84000.0,
        'total_elevation_gain' => 2800.0,
        'total_calories' => 16800,
        'avg_hr' => 155.0,
        'total_load' => 2254.0,
    ])
        ->and($payload['activities']['total'])->toBe(28)
        ->and($payload['activities']['per_page'])->toBe(25)
        ->and($payload['activities']['data'])->toHaveCount(25)
        ->and($payload['activities']['data'][0]['has_streams'])->toBeTrue()
        ->and($payload['activities']['data'][1]['has_streams'])->toBeTrue()
        ->and($payload['activities']['data'][2]['has_streams'])->toBeFalse();
});

it('builds a Mon-Sun calendar grid with weekly totals and defaults month to latest activity month', function () {
    // Nov 1 2026 is a Sunday, so week 1 starts Mon 2026-10-26 and ends Sun 2026-11-01.
    Activity::factory()->create([
        'name' => 'Boundary Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-10-28 07:00:00'),
        'distance_meters' => 8000.0,
        'duration_seconds' => 2400.0,
        'elevation_gain' => 90.0,
        'training_load' => 70.0,
    ]);

    Activity::factory()->create([
        'name' => 'Sunday Long Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:30:00'),
        'distance_meters' => 16000.0,
        'duration_seconds' => 5000.0,
        'elevation_gain' => 210.0,
        'training_load' => 150.0,
    ]);

    Activity::factory()->mountainBiking()->create([
        'name' => 'Saturday Trail Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'distance_meters' => 24000.0,
        'duration_seconds' => 5400.0,
        'elevation_gain' => 820.0,
        'training_load' => 145.5,
    ]);

    $payload = app(ActivityQueryService::class)->list(['view' => 'calendar']);
    $calendar = $payload['calendar'];

    expect($calendar['month'])->toBe('2026-11')
        ->and($calendar['monthLabel'])->toBe('November 2026')
        ->and($calendar['prevMonth'])->toBe('2026-10')
        ->and($calendar['nextMonth'])->toBe('2026-12')
        ->and($calendar['weeks'])->toHaveCount(6);

    $firstWeek = $calendar['weeks'][0];
    expect($firstWeek['days'])->toHaveCount(7)
        ->and($firstWeek['days'][0]['date'])->toBe('2026-10-26')
        ->and($firstWeek['days'][0]['inMonth'])->toBeFalse()
        ->and($firstWeek['days'][6]['date'])->toBe('2026-11-01')
        ->and($firstWeek['days'][6]['inMonth'])->toBeTrue()
        ->and($firstWeek['days'][6]['activities'])->toHaveCount(1)
        ->and($firstWeek['days'][6]['total_distance_meters'])->toBe(16000.0)
        ->and($firstWeek['totals'])->toBe([
            'count' => 2,
            'distance_meters' => 24000.0,
            'duration_seconds' => 7400.0,
            'elevation_gain' => 300.0,
            'load' => 220.0,
        ]);

    // Today (2026-11-21) is Saturday of week 4 (index 3, day index 5)
    $todayCell = $calendar['weeks'][3]['days'][5];
    expect($todayCell['date'])->toBe('2026-11-21')
        ->and($todayCell['isToday'])->toBeTrue()
        ->and($todayCell['activities'][0]['effective_discipline'])->toBe('mtb')
        ->and($calendar['weeks'][0]['isFuture'])->toBeFalse()
        ->and($calendar['weeks'][0]['isCurrent'])->toBeFalse()
        ->and($calendar['weeks'][3]['isFuture'])->toBeFalse()
        ->and($calendar['weeks'][3]['isCurrent'])->toBeTrue()
        ->and($calendar['weeks'][4]['isFuture'])->toBeTrue()
        ->and($calendar['weeks'][4]['isCurrent'])->toBeFalse()
        ->and($calendar['weeks'][5]['isFuture'])->toBeTrue()
        ->and($calendar['weeks'][5]['isCurrent'])->toBeFalse();

    // Facet filtering applies to calendar activities too
    $mtbOnly = app(ActivityQueryService::class)->list([
        'view' => 'calendar',
        'month' => '2026-11',
        'sport' => 'mtb',
    ]);
    expect($mtbOnly['calendar']['weeks'][0]['totals']['count'])->toBe(0)
        ->and($mtbOnly['calendar']['weeks'][3]['totals']['count'])->toBe(1);
});

it('builds the complete activity detail payload with sport-matched HR zones, power zones, streams, best efforts, and prev/next links', function () {
    HeartRateZone::factory()->create([
        'sport' => 'DEFAULT',
        'max_hr' => 188,
        'zone1_floor' => 100,
        'zone2_floor' => 120,
        'zone3_floor' => 140,
        'zone4_floor' => 160,
        'zone5_floor' => 175,
    ]);

    HeartRateZone::factory()->create([
        'sport' => 'CYCLING',
        'max_hr' => 185,
        'zone1_floor' => 105,
        'zone2_floor' => 125,
        'zone3_floor' => 142,
        'zone4_floor' => 158,
        'zone5_floor' => 172,
    ]);

    $older = Activity::factory()->create([
        'name' => 'Older Activity',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
    ]);

    $activity = Activity::factory()->roadBiking()->create([
        'name' => 'Waterfront Power Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-18 18:00:00', 'UTC'),
        'hr_zone_1_seconds' => 300.0,
        'hr_zone_2_seconds' => 900.0,
        'hr_zone_3_seconds' => 600.0,
        'hr_zone_4_seconds' => 150.0,
        'hr_zone_5_seconds' => 50.0,
        'power_zone_1_seconds' => 200.0,
        'power_zone_2_seconds' => 800.0,
        'power_zone_3_seconds' => 500.0,
        'power_zone_4_seconds' => 300.0,
        'power_zone_5_seconds' => 100.0,
        'power_zone_6_seconds' => 60.0,
        'power_zone_7_seconds' => 40.0,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-19 09:00:00', 'UTC'),
    ]);

    $newer = Activity::factory()->create([
        'name' => 'Newer Activity',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'laps' => [['lap' => 1, 'distance' => 10000.0]],
        'km_splits' => [['km' => 1, 'elapsed_seconds' => 105.0]],
    ]);

    BestEffort::factory()->create([
        'activity_id' => $activity->id,
        'sport' => 'road_biking',
        'distance_key' => '40k',
        'distance_meters' => 40000.0,
        'elapsed_seconds' => 3900.0,
        'override_seconds' => 3885.0,
    ]);

    BestEffort::factory()->create([
        'activity_id' => $activity->id,
        'sport' => 'road_biking',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 920.0,
        'override_seconds' => null,
    ]);

    $detail = app(ActivityQueryService::class)->detail($activity);

    expect($detail['activity']['id'])->toBe($activity->id)
        ->and($detail['activity']['name'])->toBe('Waterfront Power Ride')
        ->and($detail['activity']['effective_discipline'])->toBe('road')
        ->and($detail['activity']['started_at_local'])->toBe('2026-11-19 07:00:00')
        ->and($detail['prevActivity'])->toBe(['id' => $older->id, 'name' => 'Older Activity'])
        ->and($detail['nextActivity'])->toBe(['id' => $newer->id, 'name' => 'Newer Activity'])
        ->and($detail['hrZones'])->toBe([
            ['zone' => 1, 'label' => 'Warm Up', 'min_hr' => 105, 'max_hr' => 124, 'seconds' => 300.0, 'pct' => 15.0],
            ['zone' => 2, 'label' => 'Easy', 'min_hr' => 125, 'max_hr' => 141, 'seconds' => 900.0, 'pct' => 45.0],
            ['zone' => 3, 'label' => 'Aerobic', 'min_hr' => 142, 'max_hr' => 157, 'seconds' => 600.0, 'pct' => 30.0],
            ['zone' => 4, 'label' => 'Threshold', 'min_hr' => 158, 'max_hr' => 171, 'seconds' => 150.0, 'pct' => 7.5],
            ['zone' => 5, 'label' => 'Maximum', 'min_hr' => 172, 'max_hr' => 185, 'seconds' => 50.0, 'pct' => 2.5],
        ])
        ->and($detail['powerZones'])->toHaveCount(7)
        ->and($detail['powerZones'][1])->toBe([
            'zone' => 2,
            'label' => 'Endurance',
            'min_watts' => null,
            'max_watts' => null,
            'seconds' => 800.0,
            'pct' => 40.0,
        ])
        ->and($detail['stream'])->not->toBeNull()
        ->and($detail['stream']['laps'])->toHaveCount(1)
        ->and($detail['stream']['km_splits'])->toHaveCount(1)
        ->and($detail['bestEfforts'])->toHaveCount(2)
        ->and($detail['bestEfforts'][0]['distance_key'])->toBe('10k')
        ->and($detail['bestEfforts'][0]['label'])->toBe('10K')
        ->and($detail['bestEfforts'][0]['effective_seconds'])->toBe(920.0)
        ->and($detail['bestEfforts'][1]['distance_key'])->toBe('40k')
        ->and($detail['bestEfforts'][1]['label'])->toBe('40K')
        ->and($detail['bestEfforts'][1]['effective_seconds'])->toBe(3885.0);
});

it('falls back to DEFAULT heart rate zones and returns empty arrays when zone or stream data is absent', function () {
    HeartRateZone::factory()->create([
        'sport' => 'DEFAULT',
        'max_hr' => 190,
        'zone1_floor' => 110,
        'zone2_floor' => 130,
        'zone3_floor' => 150,
        'zone4_floor' => 165,
        'zone5_floor' => 178,
    ]);

    $run = Activity::factory()->create([
        'sport_category' => 'running',
        'hr_zone_1_seconds' => 100.0,
        'hr_zone_2_seconds' => 300.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $detail = app(ActivityQueryService::class)->detail($run);

    expect($detail['hrZones'][0]['min_hr'])->toBe(110)
        ->and($detail['hrZones'][4]['max_hr'])->toBe(190)
        ->and($detail['powerZones'])->toBe([])
        ->and($detail['stream'])->toBeNull()
        ->and($detail['bestEfforts'])->toBe([])
        ->and($detail['prevActivity'])->toBeNull()
        ->and($detail['nextActivity'])->toBeNull();
});

it('computes 5-zone running power bounds, GAP pace distribution, all-time PR ranks, and vendor-neutral TRIMP effort context', function () {
    HeartRateZone::factory()->create([
        'sport' => 'DEFAULT',
        'max_hr' => 195,
        'resting_hr' => 47,
        'zone1_floor' => 100,
        'zone2_floor' => 120,
        'zone3_floor' => 140,
        'zone4_floor' => 160,
        'zone5_floor' => 178,
    ]);

    // Prior week activity to establish rolling typical band
    Activity::factory()->create([
        'name' => 'Prior Week Base',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'duration_seconds' => 3000.0,
        'training_load' => 110.0,
        'hr_zone_1_seconds' => 120.0,
        'hr_zone_2_seconds' => 1800.0,
        'hr_zone_3_seconds' => 1080.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // Same-week sibling activity
    $sibling = Activity::factory()->create([
        'name' => 'Tuesday Easy Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-17 07:00:00'),
        'duration_seconds' => 1800.0,
        'training_load' => 60.0,
        'hr_zone_1_seconds' => 300.0,
        'hr_zone_2_seconds' => 1500.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // Target run (Wednesday Nov 18 2026)
    $run = Activity::factory()->create([
        'name' => 'Lake Oswego Long Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 06:43:41'),
        'duration_seconds' => 3846.0,
        'avg_speed' => 2.61,
        'avg_grade_adjusted_speed' => 2.69,
        'training_load' => 131.0,
        'hr_zone_1_seconds' => 194.0,
        'hr_zone_2_seconds' => 2175.0,
        'hr_zone_3_seconds' => 1370.0,
        'hr_zone_4_seconds' => 29.0,
        'hr_zone_5_seconds' => 0.0,
        'power_zone_1_seconds' => 600.0,
        'power_zone_2_seconds' => 1600.0,
        'power_zone_3_seconds' => 1150.0,
        'power_zone_4_seconds' => 340.0,
        'power_zone_5_seconds' => 10.0,
        'power_zone_6_seconds' => null,
        'power_zone_7_seconds' => null,
        'power_zone_bounds' => [255, 318, 353, 394, 455],
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $run->id,
        'series' => [
            ['time' => 0.0, 'distance' => 0.0, 'speed' => 2.6, 'gap_speed' => 2.75],
            ['time' => 30.0, 'distance' => 78.0, 'speed' => 2.6, 'gap_speed' => 2.75],
            ['time' => 60.0, 'distance' => 156.0, 'speed' => 3.1, 'gap_speed' => 3.2],
        ],
    ]);

    BestEffort::factory()->create([
        'activity_id' => $run->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1500.0,
        'achieved_at' => CarbonImmutable::parse('2026-11-18 06:43:41'),
    ]);

    $detail = app(ActivityQueryService::class)->detail($run);

    // 5-zone running power with watt bounds from FIT MSG 216
    expect($detail['powerZones'])->toHaveCount(5)
        ->and($detail['powerZones'][0])->toMatchArray([
            'zone' => 1,
            'label' => 'Easy',
            'min_watts' => 255,
            'max_watts' => 317,
        ])
        ->and($detail['powerZones'][4])->toMatchArray([
            'zone' => 5,
            'label' => 'Repetition',
            'min_watts' => 455,
            'max_watts' => null,
        ]);

    // Pace distribution calibrated to 1500s (25:00) 5K
    expect($detail['paceDistribution'])->not->toBeNull()
        ->and($detail['paceDistribution']['anchor_5k_seconds'])->toBe(1500)
        ->and($detail['paceDistribution']['gap_zones'])->toHaveCount(6)
        ->and($detail['paceDistribution']['pace_zones'])->toHaveCount(6);

    // Best effort all-time rank #1
    expect($detail['bestEfforts'][0]['all_time_rank'])->toBe(1);

    // Effort Context (vendor-neutral TRIMP + Garmin Load, 7 daily bars, 12-week trend, same-week activities)
    $effort = $detail['effortContext'];
    expect($effort['trimp']['activity_score'])->toBe(102)
        ->and($effort['garmin_load']['activity_score'])->toBe(131)
        ->and($effort['daily_bars'])->toHaveCount(7)
        ->and($effort['daily_bars'][2]['is_activity_day'])->toBeTrue()
        ->and($effort['twelve_week_trend'])->toHaveCount(12)
        ->and($effort['twelve_week_trend'][11]['is_current_week'])->toBeTrue()
        ->and(array_column($effort['week_activities'], 'id'))->toBe([$sibling->id, $run->id]);
});

it('resolves sport-scoped top-3 power effort ranks and date-matched athlete weight in activity detail', function () {
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-10',
        'weight_grams' => 72400,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-18',
        'weight_grams' => 71500,
    ]);

    // Later measurement should not be used for the Nov 19 ride
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-25',
        'weight_grams' => 70100,
    ]);

    // Running activity with higher 5s power must not affect cycling ranks
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
        'power_curve' => ['5' => 999, '300' => 500],
    ]);

    // Three stronger 1200s peers push the target ride out of Top 3 for 1200s
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'power_curve' => ['5' => 760, '300' => 360, '1200' => 310],
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'power_curve' => ['5' => 720, '300' => 320, '1200' => 305],
    ]);
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'power_curve' => ['5' => 690, '300' => 300, '1200' => 300],
    ]);

    // Excluded rides never count towards all-time ranks
    Activity::factory()->roadBiking()->excluded()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'power_curve' => ['5' => 999, '300' => 450, '1200' => 400],
    ]);

    $targetRide = Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'power_curve' => ['5' => 810, '300' => 340, '1200' => 280],
    ]);

    $detail = app(ActivityQueryService::class)->detail($targetRide);

    expect($detail['athleteWeightKg'])->toBe(71.5)
        ->and($detail['powerEffortRanks'])->toBe([
            5 => 1,
            300 => 2,
        ]);
});
