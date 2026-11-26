<?php

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Goal;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Services\Sports\EnduranceHubService;
use App\Services\Sports\FamilyOverviewService;
use App\Services\Sports\GeneralSportHubService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'Pacific/Auckland'));
});

it('builds a family overview with per-discipline summaries, 24w stacked volume, goals, and reclassify inbox', function () {
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 60000.0,
        'duration_seconds' => 7200.0,
        'elevation_gain' => 700.0,
        'tss' => 115.0,
        'ftp' => 280.0,
    ]);
    Activity::factory()->gravelCycling()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 08:00:00'),
        'distance_meters' => 45000.0,
        'duration_seconds' => 6000.0,
        'elevation_gain' => 850.0,
        'training_load' => 95.0,
        'tss' => null,
    ]);
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 09:00:00'),
        'distance_meters' => 24000.0,
        'duration_seconds' => 5400.0,
        'elevation_gain' => 920.0,
        'training_load' => 130.0,
        'grit' => 62.5,
        'avg_flow' => 3.4,
    ]);
    $unclassified = Activity::factory()->otherBiking()->create([
        'name' => 'Mystery Bike Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 10:00:00'),
        'distance_meters' => 18000.0,
    ]);

    Goal::factory()->create([
        'title' => 'Yearly Cycling',
        'sport' => 'cycling',
        'metric' => 'distance_km',
        'period' => 'yearly',
        'target_value' => 5000,
        'year' => 2026,
        'is_active' => true,
    ]);

    app(SportRegistry::class)->flushMemo();

    $overview = app(FamilyOverviewService::class)->overview('cycling');
    $summariesByKey = collect($overview['disciplineSummaries'])->keyBy('key');
    $currentWeek = $overview['weeklyVolume'][23];

    expect($overview['family']['key'])->toBe('cycling')
        ->and($overview['family']['sport_category'])->toBe('biking')
        ->and(array_column($overview['family']['tabs'], 'key'))->toBe([
            'cycling',
            'road_cycling',
            'gravel_cycling',
            'mtb_cycling',
        ])
        ->and($summariesByKey->keys()->all())->toBe(['road_cycling', 'gravel_cycling', 'mtb_cycling'])
        ->and($summariesByKey->get('road_cycling')['ytd_sessions'])->toBe(1)
        ->and($summariesByKey->get('road_cycling')['latest_ftp'])->toBe(280.0)
        ->and($summariesByKey->get('mtb_cycling')['avg_grit'])->toBe(62.5)
        ->and($overview['weeklyVolume'])->toHaveCount(24)
        ->and($currentWeek['by_discipline']['road_cycling']['distance_meters'])->toBe(60000.0)
        ->and($currentWeek['by_discipline']['gravel_cycling']['elevation_gain'])->toBe(850.0)
        ->and($currentWeek['by_discipline']['mtb_cycling']['load'])->toBe(130.0)
        ->and($overview['reclassifyInbox'])->toHaveCount(1)
        ->and($overview['reclassifyInboxTotal'])->toBe(1)
        ->and($overview['reclassifyInbox'][0]['id'])->toBe($unclassified->id)
        ->and($overview['goals'])->toHaveCount(1);
});

it('excludes explicitly confirmed other rides from the reclassify inbox and reports the full count beyond 100', function () {
    Activity::factory()->roadBiking()->create();
    Activity::factory()->mountainBiking()->create();

    Activity::factory()->otherBiking()->count(102)->create([
        'discipline_override' => null,
    ]);

    Activity::factory()->otherBiking()->create([
        'discipline_override' => 'other',
    ]);

    app(SportRegistry::class)->flushMemo();

    $overview = app(FamilyOverviewService::class)->overview('cycling');

    expect($overview['reclassifyInbox'])->toHaveCount(100)
        ->and($overview['reclassifyInboxTotal'])->toBe(102);
});

it('builds a road running endurance hub with pace module, GAP efficiency, run/walk breakdown, best efforts, and Riegel predictor', function () {
    $run = Activity::factory()->create([
        'name' => 'Harbour 10K Tempo',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:30:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 2400.0,
        'elevation_gain' => 95.0,
        'elevation_loss' => 90.0,
        'avg_speed' => 4.167,
        'avg_grade_adjusted_speed' => 4.300,
        'avg_hr' => 160,
        'avg_cadence' => 178.0,
        'avg_stride_length' => 1.35,
        'run_duration_seconds' => 2300.0,
        'walk_duration_seconds' => 70.0,
        'idle_duration_seconds' => 30.0,
        'training_load' => 142.0,
        'hr_zone_1_seconds' => 120.0,
        'hr_zone_2_seconds' => 600.0,
        'hr_zone_3_seconds' => 480.0,
        'hr_zone_4_seconds' => 960.0,
        'hr_zone_5_seconds' => 240.0,
        'grit' => null,
        'avg_flow' => null,
        'jump_count' => null,
        'unpaved_pct' => null,
    ]);

    BestEffort::factory()->create([
        'activity_id' => $run->id,
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2400.0,
        'achieved_at' => CarbonImmutable::parse('2026-11-15 07:30:00'),
    ]);

    Vo2MaxRecord::query()->create([
        'calendar_date' => '2026-11-15',
        'sport' => 'running',
        'vo2_max' => 56,
        'vo2_max_precise' => 56.4,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('road_running');

    expect($hub['sport']['key'])->toBe('road_running')
        ->and($hub['filters']['showEnvironmentFilter'])->toBeTrue()
        ->and($hub['filters']['showTerrainFilter'])->toBeTrue()
        ->and($hub['summary']['ytd_sessions'])->toBe(1)
        ->and($hub['summary']['ytd_avg_gap_speed'])->toBe(4.3)
        ->and($hub['summary']['latest_vo2_max'])->toBe(56.4)
        ->and($hub['hrZones']['polarization']['easy_pct'])->toBe(30.0)
        ->and($hub['hrZones']['polarization']['hard_pct'])->toBe(50.0)
        ->and($hub['milestones']['longest']['distance_meters'])->toBe(10000.0)
        ->and($hub['milestones']['fastest']['avg_speed'])->toBe(4.167)
        ->and($hub['milestones']['hardest_grit'])->toBeNull()
        ->and($hub['modules']['pace'])->not->toBeNull()
        ->and($hub['modules']['pace']['efficiency'][0]['gap_speed'])->toBe(4.3)
        ->and($hub['modules']['pace']['efficiency'][0]['effective_speed'])->toBe(4.3)
        ->and($hub['modules']['pace']['runWalkBreakdown']['run_pct'])->toBe(95.8)
        ->and($hub['modules']['pace']['racePredictor'])->not->toBeNull()
        ->and($hub['modules']['pace']['racePredictor']['source_label'])->toBe('10K')
        ->and($hub['modules']['pace']['racePredictor']['predictions'])->toHaveCount(4)
        ->and($hub['modules']['climbing'])->not->toBeNull()
        ->and($hub['modules']['power'])->toBeNull()
        ->and($hub['modules']['offroad'])->toBeNull();
});

it('auto-detects power, climbing, and offroad modules on gravel and mtb hubs and applies terrain filtering', function () {
    WeightMeasurement::query()->create([
        'garmin_sample_pk' => 90001,
        'calendar_date' => '2026-11-10',
        'measured_at' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'weight_grams' => 70000,
    ]);

    // Gravel ride WITHOUT power meter
    Activity::factory()->gravelCycling()->create([
        'name' => 'Forest Gravel Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-14 08:00:00'),
        'distance_meters' => 42000.0,
        'duration_seconds' => 6300.0,
        'elevation_gain' => 630.0,
        'unpaved_pct' => 78.0,
        'avg_power' => null,
        'normalized_power' => null,
        'ftp' => null,
        'tss' => null,
        'power_curve' => null,
    ]);

    // Flat fast XC MTB ride WITH power meter
    $flatMtb = Activity::factory()->mountainBiking()->create([
        'name' => 'Flat XC Singletrack',
        'location_name' => 'River Park',
        'started_at_local' => CarbonImmutable::parse('2026-11-16 09:00:00'),
        'distance_meters' => 30000.0,
        'duration_seconds' => 5400.0,
        'elevation_gain' => 150.0, // 5 m/km -> flat
        'avg_power' => 235.0,
        'normalized_power' => 255.0,
        'ftp' => 280.0,
        'tss' => 98.0,
        'power_curve' => ['60' => 410, '1200' => 290],
        'power_zone_2_seconds' => 2400.0,
        'power_zone_4_seconds' => 1200.0,
        'grit' => 25.0,
        'avg_flow' => 2.2,
        'jump_count' => 2,
        'unpaved_pct' => 98.0,
    ]);

    // Steep backcountry MTB ride WITH jumps
    $steepMtb = Activity::factory()->mountainBiking()->create([
        'name' => 'Alpine Enduro Descent',
        'location_name' => 'Skyline Ridge',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 09:00:00'),
        'distance_meters' => 25000.0,
        'duration_seconds' => 7200.0,
        'elevation_gain' => 1250.0, // 50 m/km -> steep
        'grit' => 88.5,
        'avg_flow' => 6.4,
        'jump_count' => 14,
        'unpaved_pct' => 95.0,
    ]);

    app(SportRegistry::class)->flushMemo();
    $service = app(EnduranceHubService::class);

    $gravelHub = $service->hub('gravel_cycling');
    $mtbHub = $service->hub('mtb_cycling');
    $mtbSteepOnly = $service->hub('mtb_cycling', 'all', 'steep');

    expect($gravelHub['modules']['power'])->toBeNull()
        ->and($gravelHub['modules']['climbing'])->not->toBeNull()
        ->and($gravelHub['modules']['offroad'])->not->toBeNull()
        ->and($gravelHub['modules']['offroad']['avg_unpaved_pct'])->toBe(78.0);

    expect($mtbHub['modules']['power'])->not->toBeNull()
        ->and($mtbHub['modules']['power']['ftp'])->toBe(280.0)
        ->and($mtbHub['modules']['power']['w_per_kg'])->toBe(4.0)
        ->and($mtbHub['modules']['power']['powerCurve'])->not->toBeEmpty()
        ->and($mtbHub['modules']['power']['powerZones'])->toHaveCount(7)
        ->and($mtbHub['modules']['climbing']['regions'])->toHaveCount(2)
        ->and($mtbHub['modules']['offroad']['total_jumps'])->toBe(16)
        ->and($mtbHub['modules']['offroad']['hardestSessions'][0]['id'])->toBe($steepMtb->id)
        ->and($mtbHub['modules']['offroad']['smoothestSessions'][0]['id'])->toBe($flatMtb->id)
        ->and($mtbHub['modules']['offroad']['topJumpSessions'][0]['id'])->toBe($steepMtb->id)
        ->and($mtbHub['milestones']['hardest_grit']['grit'])->toBe(88.5);

    expect($mtbSteepOnly['summary']['total_sessions'])->toBe(1)
        ->and($mtbSteepOnly['recentActivities'][0]['id'])->toBe($steepMtb->id);
});

it('hides environment filter on hiking and hides both filters and climbing on indoor-only hubs', function () {
    Activity::factory()->hiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 09:00:00'),
        'distance_meters' => 14000.0,
        'duration_seconds' => 12600.0,
        'elevation_gain' => 980.0,
        'location_name' => 'Tararua Range',
        'grit' => null,
        'avg_flow' => null,
        'jump_count' => null,
        'unpaved_pct' => null,
    ]);
    Activity::factory()->indoor()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 8000.0,
        'duration_seconds' => 2400.0,
        'elevation_gain' => 50.0,
    ]);

    $service = app(EnduranceHubService::class);
    $hikingHub = $service->hub('hiking');
    $treadmillHub = $service->hub('treadmill_running');

    expect($hikingHub['filters']['showEnvironmentFilter'])->toBeFalse()
        ->and($hikingHub['filters']['showTerrainFilter'])->toBeTrue()
        ->and($hikingHub['modules']['pace'])->toBeNull()
        ->and($hikingHub['modules']['power'])->toBeNull()
        ->and($hikingHub['modules']['climbing'])->not->toBeNull()
        ->and($hikingHub['modules']['climbing']['regions'][0]['name'])->toBe('Tararua Range')
        ->and($hikingHub['modules']['offroad'])->toBeNull();

    expect($treadmillHub['filters']['showEnvironmentFilter'])->toBeFalse()
        ->and($treadmillHub['filters']['showTerrainFilter'])->toBeFalse()
        ->and($treadmillHub['modules']['pace'])->not->toBeNull()
        ->and($treadmillHub['modules']['pace']['bestEfforts'])->toBeNull()
        ->and($treadmillHub['modules']['pace']['racePredictor'])->toBeNull()
        ->and($treadmillHub['modules']['climbing'])->toBeNull();
});

it('builds general sport consistency hubs for swimming and cardio with streaks and HR zones', function () {
    // 3 consecutive weekly swim sessions (current week, -1w, -2w)
    Activity::factory()->swimming()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 2000.0,
        'duration_seconds' => 2400.0,
        'training_load' => 55.0,
        'avg_hr' => 142,
        'hr_zone_2_seconds' => 1200.0,
        'hr_zone_3_seconds' => 800.0,
        'hr_zone_4_seconds' => 400.0,
    ]);
    Activity::factory()->swimming()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 1800.0,
        'duration_seconds' => 2100.0,
        'training_load' => 48.0,
        'avg_hr' => 138,
    ]);
    Activity::factory()->swimming()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 1500.0,
        'duration_seconds' => 1800.0,
        'training_load' => 40.0,
        'avg_hr' => 135,
    ]);

    Activity::factory()->create([
        'name' => 'Strength Circuit',
        'activity_type_key' => 'strength_training',
        'sport_category' => 'cardio',
        'sport_discipline' => null,
        'is_indoor' => true,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 17:30:00'),
        'distance_meters' => 0.0,
        'duration_seconds' => 3000.0,
        'training_load' => 72.0,
        'avg_hr' => 128,
        'hr_zone_1_seconds' => 600.0,
        'hr_zone_2_seconds' => 1800.0,
        'hr_zone_3_seconds' => 600.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $service = app(GeneralSportHubService::class);
    $swimHub = $service->hub('swimming');
    $cardioHub = $service->hub('cardio');

    expect($swimHub['sport']['key'])->toBe('swimming')
        ->and($swimHub['sport']['metric_mode'])->toBe('pace_100m')
        ->and($swimHub['summary']['ytd_sessions'])->toBe(3)
        ->and($swimHub['summary']['ytd_distance_meters'])->toBe(5300.0)
        ->and($swimHub['summary']['ytd_load'])->toBe(143.0)
        ->and($swimHub['streak'])->toBe(['current_weeks' => 3, 'longest_weeks' => 3])
        ->and($swimHub['weeklyVolume'])->toHaveCount(12)
        ->and($swimHub['recentActivities'])->toHaveCount(3);

    expect($cardioHub['sport']['key'])->toBe('cardio')
        ->and($cardioHub['sport']['metric_mode'])->toBe('duration')
        ->and($cardioHub['summary']['ytd_sessions'])->toBe(1)
        ->and($cardioHub['summary']['ytd_duration_seconds'])->toBe(3000.0)
        ->and($cardioHub['summary']['ytd_load'])->toBe(72.0)
        ->and($cardioHub['hrZones']['total_seconds'])->toBe(3000.0)
        ->and($cardioHub['hrZones']['polarization']['easy_pct'])->toBe(80.0)
        ->and($cardioHub['streak'])->toBe(['current_weeks' => 1, 'longest_weeks' => 1]);
});

it('builds a running family overview and respects trail race predictor settings', function () {
    Activity::factory()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3000.0,
    ]);

    $trailRun = Activity::factory()->trailRunning()->create([
        'name' => 'Ridgeline Trail 10K',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 08:00:00'),
        'distance_meters' => 10500.0,
        'duration_seconds' => 3300.0,
        'elevation_gain' => 520.0,
        'unpaved_pct' => 92.0,
    ]);

    $unclassifiedRun = Activity::factory()->create([
        'name' => 'Unclassified Run',
        'sport_category' => 'running',
        'sport_discipline' => 'other',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:30:00'),
    ]);

    BestEffort::factory()->create([
        'activity_id' => $trailRun->id,
        'sport' => 'trail_running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 3100.0,
        'achieved_at' => CarbonImmutable::parse('2026-11-19 08:00:00'),
    ]);

    $registry = app(SportRegistry::class);
    $registry->flushMemo();

    $overview = app(FamilyOverviewService::class)->overview('running');
    $endurance = app(EnduranceHubService::class);

    // Auto-detected split mode sets trail_in_running_prs = false -> racePredictor hidden on trail hub
    $trailHubExcluded = $endurance->hub('trail_running');

    expect($overview['family']['key'])->toBe('running')
        ->and(array_column($overview['disciplineSummaries'], 'key'))->toBe(['road_running', 'trail_running'])
        ->and($overview['reclassifyInbox'])->toHaveCount(1)
        ->and($overview['reclassifyInbox'][0]['id'])->toBe($unclassifiedRun->id)
        ->and($trailHubExcluded['modules']['pace'])->not->toBeNull()
        ->and($trailHubExcluded['modules']['pace']['racePredictor'])->toBeNull()
        ->and($trailHubExcluded['modules']['climbing'])->not->toBeNull()
        ->and($trailHubExcluded['modules']['offroad'])->not->toBeNull();

    $registry->saveConfig([
        'mode' => 'custom',
        'families' => [
            'running' => ['mode' => 'split', 'disciplines' => ['road_running', 'trail_running']],
            'cycling' => ['mode' => 'off', 'disciplines' => []],
        ],
        'best_efforts' => [
            'trail_in_running_prs' => true,
            'cycling_disciplines' => ['road'],
        ],
    ]);

    $trailHubIncluded = $endurance->hub('trail_running');

    expect($trailHubIncluded['modules']['pace']['racePredictor'])->not->toBeNull()
        ->and($trailHubIncluded['modules']['pace']['racePredictor']['source_label'])->toBe('10K');
});

it('keeps the offroad module null for road cycling rides with low unpaved splits and no grit, flow, or jumps', function () {
    Activity::factory()->roadBiking()->create([
        'name' => 'Paved Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'grit' => 0.0,
        'avg_flow' => 0.0,
        'jump_count' => 0,
        'unpaved_pct' => 0.0,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Mostly Paved Coastal Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'grit' => null,
        'avg_flow' => null,
        'jump_count' => null,
        'unpaved_pct' => 7.4,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('road_cycling');

    expect($hub['modules']['offroad'])->toBeNull();
});

it('enables both power and offroad modules on mtb hubs when rides have power telemetry and grit or flow', function () {
    Activity::factory()->mountainBiking()->create([
        'name' => 'Power Meter Singletrack',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:30:00'),
        'avg_power' => 225.0,
        'normalized_power' => null,
        'ftp' => null,
        'tss' => null,
        'power_curve' => null,
        'grit' => 42.5,
        'avg_flow' => 3.8,
        'jump_count' => 4,
        'unpaved_pct' => 94.0,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('mtb_cycling');

    expect($hub['modules']['power'])->not->toBeNull()
        ->and($hub['modules']['offroad'])->not->toBeNull()
        ->and($hub['modules']['offroad']['avg_grit'])->toBe(42.5)
        ->and($hub['modules']['offroad']['avg_flow'])->toBe(3.8);
});

it('only includes activities with positive grit or flow in offroad dynamicsTrend', function () {
    Activity::factory()->gravelCycling()->create([
        'name' => 'Unpaved Only Gravel',
        'started_at_local' => CarbonImmutable::parse('2026-11-16 08:00:00'),
        'grit' => null,
        'avg_flow' => null,
        'jump_count' => null,
        'unpaved_pct' => 85.0,
    ]);

    Activity::factory()->gravelCycling()->create([
        'name' => 'Zero Grit And Flow Gravel',
        'started_at_local' => CarbonImmutable::parse('2026-11-17 08:00:00'),
        'grit' => 0.0,
        'avg_flow' => 0.0,
        'jump_count' => 0,
        'unpaved_pct' => 60.0,
    ]);

    $withGrit = Activity::factory()->gravelCycling()->create([
        'name' => 'Technical Gravel Climb',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 08:00:00'),
        'grit' => 34.2,
        'avg_flow' => null,
        'unpaved_pct' => 75.0,
    ]);

    $withFlow = Activity::factory()->gravelCycling()->create([
        'name' => 'Flowy Gravel Descent',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 08:00:00'),
        'grit' => null,
        'avg_flow' => 4.6,
        'unpaved_pct' => 80.0,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('gravel_cycling');

    expect($hub['modules']['offroad'])->not->toBeNull()
        ->and($hub['modules']['offroad']['avg_unpaved_pct'])->toBe(75.0)
        ->and($hub['modules']['offroad']['dynamicsTrend'])->toHaveCount(2)
        ->and(array_column($hub['modules']['offroad']['dynamicsTrend'], 'activity_id'))->toBe([
            $withGrit->id,
            $withFlow->id,
        ]);
});

it('keeps the power module null for cycling rides with only ftp and tss and no power telemetry', function () {
    Activity::factory()->roadBiking()->create([
        'name' => 'HR-Only Road Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:30:00'),
        'avg_power' => null,
        'max_power' => null,
        'normalized_power' => null,
        'intensity_factor' => null,
        'power_curve' => null,
        'power_zone_1_seconds' => 0.0,
        'ftp' => 280.0,
        'tss' => 95.0,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('road_cycling');

    expect($hub['modules']['power'])->toBeNull();
});

it('ranks PR overrides, promotes around excluded efforts, and falls back Riegel predictions to all-time efforts', function () {
    $run2024 = Activity::factory()->create([
        'name' => '2024 Fast 10K',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2024-05-01 07:00:00'),
    ]);
    $run2026Race = Activity::factory()->create([
        'name' => '2026 Chip 5K',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-08-01 07:00:00'),
    ]);
    $run2026Glitch = Activity::factory()->create([
        'name' => '2026 Glitch 5K',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-09-01 07:00:00'),
    ]);

    BestEffort::factory()->create([
        'activity_id' => $run2024->id,
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2300.0,
        'achieved_at' => $run2024->started_at_local,
    ]);

    $overrideEffort = BestEffort::factory()->create([
        'activity_id' => $run2026Race->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1210.0,
        'override_seconds' => 1175.0,
        'achieved_at' => $run2026Race->started_at_local,
    ]);

    $excludedEffort = BestEffort::factory()->excluded()->create([
        'activity_id' => $run2026Glitch->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 900.0,
        'achieved_at' => $run2026Glitch->started_at_local,
    ]);

    app(SportRegistry::class)->flushMemo();
    $service = app(EnduranceHubService::class);

    $hub = $service->hub('road_running', year: 2026);
    $fiveK = collect($hub['modules']['pace']['bestEfforts']['distances'])->firstWhere('key', '5k');

    expect($fiveK['allTimeTop3'])->toHaveCount(1)
        ->and($fiveK['allTimeTop3'][0]['id'])->toBe($overrideEffort->id)
        ->and($fiveK['allTimeTop3'][0]['effective_seconds'])->toBe(1175.0)
        ->and($fiveK['excluded'])->toHaveCount(1)
        ->and($fiveK['excluded'][0]['id'])->toBe($excludedEffort->id)
        ->and($hub['modules']['pace']['racePredictor']['source_label'])->toBe('5K')
        ->and($hub['modules']['pace']['racePredictor']['source_time_seconds'])->toBe(1175.0);

    $overrideEffort->update(['is_excluded' => true]);

    $fallbackHub = $service->hub('road_running', year: 2026);

    expect($fallbackHub['modules']['pace']['racePredictor']['source_label'])->toBe('10K')
        ->and($fallbackHub['modules']['pace']['racePredictor']['source_time_seconds'])->toBe(2300.0);
});

it('computes cycling power CTL/ATL/TSB with training_load fallback and 0.95 max 20min FTP fallback', function () {
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-20',
        'weight_grams' => 70000,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Yesterday Threshold Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 07:00:00'),
        'avg_power' => 255.0,
        'normalized_power' => 270.0,
        'ftp' => null,
        'max_20min_power' => 300.0,
        'tss' => null,
        'training_load' => 100.0,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('road_cycling');
    $power = $hub['modules']['power'];
    $series = $power['fitnessSeries'];
    $today = $series[count($series) - 1];

    expect($power['ftp'])->toBe(285.0)
        ->and($power['w_per_kg'])->toBe(4.07)
        ->and($today['date'])->toBe('2026-11-22')
        ->and($today['ctl'])->toBe(2.3)
        ->and($today['atl'])->toBe(12.2)
        ->and($today['tsb'])->toBe(-11.9);
});
