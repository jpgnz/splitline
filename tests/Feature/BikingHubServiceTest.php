<?php

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Services\Biking\BikingHubService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('returns clean empty-state payloads for overview, road, and mtb when no rides exist', function () {
    $service = app(BikingHubService::class);

    $overview = $service->overview();
    expect($overview['roadSummary'])->toBe([
        'ytd_rides' => 0,
        'ytd_distance_meters' => 0.0,
        'ytd_elevation_gain' => 0.0,
        'ytd_duration_seconds' => 0.0,
        'total_rides' => 0,
        'total_distance_meters' => 0.0,
        'total_elevation_gain' => 0.0,
        'total_duration_seconds' => 0.0,
        'latest_ftp' => null,
        'avg_grit' => null,
        'avg_flow' => null,
    ])
        ->and($overview['mtbSummary']['total_rides'])->toBe(0)
        ->and($overview['weeklyVolume'])->toHaveCount(24)
        ->and($overview['unclassifiedCount'])->toBe(0)
        ->and($overview['unclassifiedRides'])->toBe([]);

    $road = $service->road('invalid_env');
    expect($road['filters']['environment'])->toBe('all')
        ->and($road['summary']['ytd_rides'])->toBe(0)
        ->and($road['summary']['latest_ftp'])->toBeNull()
        ->and($road['summary']['weight_kg'])->toBeNull()
        ->and($road['summary']['w_per_kg'])->toBeNull()
        ->and($road['summary']['current_ctl'])->toBe(0.0)
        ->and($road['summary']['current_atl'])->toBe(0.0)
        ->and($road['summary']['current_tsb'])->toBe(0.0)
        ->and($road['powerCurve'])->toBe([])
        ->and($road['fitnessLoad'])->toHaveCount(180)
        ->and($road['weeklyTss'])->toHaveCount(24)
        ->and($road['ftpTrend'])->toBe([])
        ->and($road['powerZones'])->toBe([])
        ->and($road['hrZones'])->toBe([])
        ->and($road['bestEfforts'])->toHaveCount(7)
        ->and($road['bestEfforts'][0]['best'])->toBeNull()
        ->and($road['milestones']['longest_ride'])->toBeNull()
        ->and($road['recentRides'])->toBe([]);

    $mtb = $service->mtb();
    expect($mtb['summary'])->toBe([
        'ytd_elevation_gain' => 0.0,
        'ytd_elevation_loss' => 0.0,
        'ytd_rides' => 0,
        'ytd_distance_meters' => 0.0,
        'ytd_duration_seconds' => 0.0,
        'total_elevation_gain' => 0.0,
        'total_elevation_loss' => 0.0,
        'total_rides' => 0,
        'total_distance_meters' => 0.0,
        'avg_vam' => null,
        'steepness_density' => null,
        'avg_grit' => null,
        'avg_flow' => null,
        'total_jumps' => 0,
        'avg_unpaved_pct' => null,
    ])
        ->and($mtb['weeklyVertical'])->toHaveCount(24)
        ->and($mtb['dynamicsTrend'])->toBe([])
        ->and($mtb['hardestRides'])->toBe([])
        ->and($mtb['smoothestRides'])->toBe([])
        ->and($mtb['jumpHighlights'])->toBe([])
        ->and($mtb['trailRegions'])->toBe([])
        ->and($mtb['hrZones'])->toBe([])
        ->and($mtb['staminaAndFeel']['stamina_samples'])->toBe(0)
        ->and($mtb['milestones']['biggest_climb'])->toBeNull()
        ->and($mtb['recentRides'])->toBe([]);
});

it('builds overview road vs mtb splits, weekly stacked volume, and unclassified queue respecting discipline overrides', function () {
    // Non-biking activity must be ignored
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 15000.0,
    ]);

    // 2026 native road ride (current week: Mon 2026-11-16 .. Sun 2026-11-22)
    Activity::factory()->roadBiking()->create([
        'name' => 'Tamaki Drive Fast Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 06:30:00'),
        'distance_meters' => 45000.0,
        'elevation_gain' => 350.0,
        'duration_seconds' => 4500.0,
        'ftp' => 285.0,
    ]);

    // 2025 generic cycling ride overridden to road
    Activity::factory()->otherBiking()->create([
        'name' => '2025 Coastal Fondo',
        'discipline_override' => 'road',
        'started_at_local' => CarbonImmutable::parse('2025-09-10 08:00:00'),
        'distance_meters' => 80000.0,
        'elevation_gain' => 900.0,
        'duration_seconds' => 9000.0,
        'ftp' => 260.0,
    ]);

    // 2026 native MTB ride (current week)
    Activity::factory()->mountainBiking()->create([
        'name' => 'Woodhill Enduro',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 16:00:00'),
        'distance_meters' => 22000.0,
        'elevation_gain' => 700.0,
        'duration_seconds' => 5400.0,
        'grit' => 60.0,
        'avg_flow' => 3.0,
    ]);

    // 2026 generic cycling ride overridden to MTB
    Activity::factory()->otherBiking()->create([
        'name' => 'Riverhead Singletrack',
        'discipline_override' => 'mtb',
        'started_at_local' => CarbonImmutable::parse('2026-11-17 17:00:00'),
        'distance_meters' => 18000.0,
        'elevation_gain' => 500.0,
        'duration_seconds' => 4200.0,
        'grit' => 40.0,
        'avg_flow' => 5.0,
    ]);

    // Still unclassified generic cycling ride (current week)
    $unclassified = Activity::factory()->otherBiking()->create([
        'name' => 'Mystery Commute',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:15:00'),
        'distance_meters' => 12500.0,
        'elevation_gain' => 120.0,
        'duration_seconds' => 2100.0,
        'avg_power' => 195.0,
        'grit' => 12.5,
        'unpaved_pct' => 15.0,
    ]);

    $overview = app(BikingHubService::class)->overview();

    expect($overview['roadSummary'])->toBe([
        'ytd_rides' => 1,
        'ytd_distance_meters' => 45000.0,
        'ytd_elevation_gain' => 350.0,
        'ytd_duration_seconds' => 4500.0,
        'total_rides' => 2,
        'total_distance_meters' => 125000.0,
        'total_elevation_gain' => 1250.0,
        'total_duration_seconds' => 13500.0,
        'latest_ftp' => 285.0,
        'avg_grit' => null,
        'avg_flow' => null,
    ]);

    expect($overview['mtbSummary'])->toBe([
        'ytd_rides' => 2,
        'ytd_distance_meters' => 40000.0,
        'ytd_elevation_gain' => 1200.0,
        'ytd_duration_seconds' => 9600.0,
        'total_rides' => 2,
        'total_distance_meters' => 40000.0,
        'total_elevation_gain' => 1200.0,
        'total_duration_seconds' => 9600.0,
        'latest_ftp' => null,
        'avg_grit' => 50.0,
        'avg_flow' => 4.0,
    ]);

    $currentWeek = $overview['weeklyVolume'][23];
    expect($currentWeek['week_start'])->toBe('2026-11-16')
        ->and($currentWeek['road_km'])->toBe(45.0)
        ->and($currentWeek['mtb_km'])->toBe(40.0)
        ->and($currentWeek['other_km'])->toBe(12.5)
        ->and($currentWeek['total_elevation_m'])->toBe(1670.0);

    expect($overview['unclassifiedCount'])->toBe(1)
        ->and($overview['unclassifiedRides'])->toHaveCount(1)
        ->and($overview['unclassifiedRides'][0])->toMatchArray([
            'id' => $unclassified->id,
            'name' => 'Mystery Commute',
            'location_name' => 'Auckland',
            'activity_type_key' => 'cycling',
            'distance_meters' => 12500.0,
            'avg_power' => 195.0,
            'grit' => 12.5,
            'unpaved_pct' => 15.0,
        ]);
});

it('filters road biking by outdoor/indoor, builds power curve across periods, and computes fitness load, zones, efforts, and milestones', function () {
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-10',
        'weight_grams' => 75000,
    ]);
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-20',
        'weight_grams' => 70000,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-18',
        'sport' => 'cycling',
        'vo2_max_precise' => 58.4,
        'vo2_max' => 58.0,
    ]);
    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-19',
        'sport' => 'running',
        'vo2_max_precise' => 62.0,
        'vo2_max' => 62.0,
    ]);

    // 2025 outdoor road ride (All-Time power peak at 5s)
    $oldOutdoor = Activity::factory()->roadBiking()->create([
        'name' => '2025 Waitakere Century',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2025-10-15 07:00:00'),
        'distance_meters' => 105000.0,
        'duration_seconds' => 12600.0,
        'elevation_gain' => 1850.0,
        'avg_speed' => 8.333,
        'avg_power' => 200.0,
        'normalized_power' => 220.0,
        'intensity_factor' => 0.80,
        'left_balance' => 51.0,
        'ftp' => 265.0,
        'tss' => 240.0,
        'power_curve' => ['1' => 1100, '5' => 950, '60' => 420, '1200' => 280],
        'power_zone_1_seconds' => 1000.0,
        'power_zone_2_seconds' => 3000.0,
        'hr_zone_1_seconds' => 600.0,
        'hr_zone_2_seconds' => 2400.0,
    ]);

    // 2026 season (>90d ago) outdoor ride (Season peak at 60s)
    Activity::factory()->roadBiking()->create([
        'name' => 'Autumn Hill Repeats',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-04-10 07:00:00'),
        'distance_meters' => 50000.0,
        'duration_seconds' => 5400.0,
        'elevation_gain' => 950.0,
        'avg_speed' => 9.259,
        'avg_power' => 220.0,
        'normalized_power' => 264.0,
        'intensity_factor' => 0.90,
        'left_balance' => 49.0,
        'ftp' => 275.0,
        'tss' => 145.0,
        'power_curve' => ['1' => 980, '5' => 860, '60' => 460, '1200' => 290],
        'power_zone_1_seconds' => null,
        'power_zone_2_seconds' => null,
    ]);

    // Short (<20km) very fast ride — must NOT qualify for fastest_avg_speed milestone!
    Activity::factory()->roadBiking()->create([
        'name' => '10K Sprint',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 800.0,
        'elevation_gain' => 20.0,
        'avg_speed' => 12.5,
        'avg_power' => 250.0,
        'normalized_power' => 275.0,
        'intensity_factor' => 0.95,
        'left_balance' => 50.0,
        'ftp' => null,
        'max_20min_power' => 295.0,
        'tss' => null,
        'training_load' => 60.0,
        'power_curve' => ['1' => 1020, '5' => 840, '60' => 440, '1200' => 295],
        'power_zone_2_seconds' => 400.0,
        'power_zone_4_seconds' => 400.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 300.0,
        'hr_zone_4_seconds' => 500.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // Recent outdoor road ride (>=20km) — yesterday (2026-11-20), TSS = 100
    $fastQualifying = Activity::factory()->roadBiking()->create([
        'name' => 'Tamaki TT 40K',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:30:00'),
        'distance_meters' => 42000.0,
        'duration_seconds' => 3780.0,
        'elevation_gain' => 220.0,
        'avg_speed' => 11.111, // 40.0 km/h
        'avg_power' => 260.0,
        'normalized_power' => 286.0,
        'intensity_factor' => 1.00,
        'left_balance' => 50.0,
        'ftp' => 280.0,
        'tss' => 100.0,
        'max_20min_power' => 300.0,
        'power_curve' => ['1' => 900, '5' => 800, '60' => 410, '1200' => 300],
        'power_zone_2_seconds' => 200.0,
        'power_zone_4_seconds' => 1000.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 200.0,
        'hr_zone_4_seconds' => 1000.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // Indoor trainer ride (no explicit FTP, uses 0.95 * max_20min_power fallback when filtered to indoor)
    $indoorRide = Activity::factory()->roadBiking()->indoor()->create([
        'name' => 'Zwift Threshold Intervals',
        'activity_type_key' => 'virtual_ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 06:00:00'),
        'distance_meters' => 32000.0,
        'duration_seconds' => 3600.0,
        'elevation_gain' => 280.0,
        'avg_speed' => 8.889,
        'avg_power' => 240.0,
        'normalized_power' => 252.0,
        'intensity_factor' => 0.88,
        'left_balance' => 52.0,
        'ftp' => null,
        'max_20min_power' => 300.0,
        'tss' => 84.0,
        'power_curve' => ['1' => 700, '15' => 620, '1200' => 300],
        'power_zone_1_seconds' => null,
        'power_zone_2_seconds' => null,
        'power_zone_3_seconds' => null,
        'power_zone_4_seconds' => null,
        'power_zone_5_seconds' => null,
        'power_zone_6_seconds' => null,
        'power_zone_7_seconds' => null,
        'hr_zone_1_seconds' => null,
        'hr_zone_2_seconds' => null,
        'hr_zone_3_seconds' => null,
        'hr_zone_4_seconds' => null,
        'hr_zone_5_seconds' => null,
    ]);

    // Road best efforts (including an excluded effort and a chip-time override)
    BestEffort::factory()->create([
        'activity_id' => $fastQualifying->id,
        'sport' => 'road_biking',
        'distance_key' => '40k',
        'distance_meters' => 40000.0,
        'elapsed_seconds' => 3600.0,
        'override_seconds' => 3500.0,
        'achieved_at' => CarbonImmutable::parse('2026-11-20 06:30:00'),
    ]);
    BestEffort::factory()->excluded()->create([
        'activity_id' => $oldOutdoor->id,
        'sport' => 'road_biking',
        'distance_key' => '40k',
        'distance_meters' => 40000.0,
        'elapsed_seconds' => 3000.0,
        'achieved_at' => CarbonImmutable::parse('2025-10-15 07:00:00'),
    ]);
    BestEffort::factory()->create([
        'activity_id' => $indoorRide->id,
        'sport' => 'road_biking',
        'distance_key' => '20k',
        'distance_meters' => 20000.0,
        'elapsed_seconds' => 1800.0,
        'achieved_at' => CarbonImmutable::parse('2026-11-21 06:00:00'),
    ]);

    $service = app(BikingHubService::class);
    $outdoor = $service->road('outdoor');

    expect($outdoor['filters']['environment'])->toBe('outdoor')
        ->and($outdoor['summary']['ytd_rides'])->toBe(3)
        ->and($outdoor['summary']['ytd_distance_meters'])->toBe(102000.0)
        ->and($outdoor['summary']['latest_ftp'])->toBe(280.0)
        ->and($outdoor['summary']['weight_kg'])->toBe(70.0)
        ->and($outdoor['summary']['w_per_kg'])->toBe(4.0)
        ->and($outdoor['summary']['latest_vo2_max'])->toBe(58.4)
        ->and($outdoor['recentRides'])->toHaveCount(4);

    // Power curve: 15s was only on indoor ride, so outdoor skips 15s and has [1, 5, 60, 1200]
    $curveBySeconds = [];
    foreach ($outdoor['powerCurve'] as $point) {
        $curveBySeconds[$point['seconds']] = $point;
    }

    expect(array_keys($curveBySeconds))->toBe([1, 5, 60, 1200])
        ->and($curveBySeconds[5]['allTime'])->toBe(950.0)
        ->and($curveBySeconds[5]['season'])->toBe(860.0)
        ->and($curveBySeconds[5]['last90d'])->toBe(840.0)
        ->and($curveBySeconds[60]['allTime'])->toBe(460.0)
        ->and($curveBySeconds[60]['season'])->toBe(460.0)
        ->and($curveBySeconds[60]['last90d'])->toBe(440.0)
        ->and($curveBySeconds[1200]['last90d'])->toBe(300.0)
        ->and($curveBySeconds[1200]['last90dWkg'])->toBe(4.29);

    // Best efforts: outdoor sees 40k (with override 3500s, excluding 3000s glitch), does NOT see indoor 20k
    $effortsByKey = [];
    foreach ($outdoor['bestEfforts'] as $effort) {
        $effortsByKey[$effort['key']] = $effort;
    }

    expect($effortsByKey['40k']['best']['effective_seconds'])->toBe(3500.0)
        ->and($effortsByKey['40k']['best']['activity_id'])->toBe($fastQualifying->id)
        ->and($effortsByKey['40k']['best']['avg_speed_kmh'])->toBe(41.1)
        ->and($effortsByKey['20k']['best'])->toBeNull();

    // Milestones: fastest_avg_speed skips 10K Sprint (<20km) and picks Tamaki TT 40K
    expect($outdoor['milestones']['longest_ride']['activity_id'])->toBe($oldOutdoor->id)
        ->and($outdoor['milestones']['biggest_climb']['activity_id'])->toBe($oldOutdoor->id)
        ->and($outdoor['milestones']['fastest_avg_speed']['activity_id'])->toBe($fastQualifying->id)
        ->and($outdoor['milestones']['fastest_avg_speed']['value_formatted'])->toBe('40.0 km/h')
        ->and($outdoor['milestones']['highest_np']['activity_id'])->toBe($fastQualifying->id)
        ->and($outdoor['milestones']['highest_tss']['activity_id'])->toBe($oldOutdoor->id);

    // Power & HR zones use last 90d (2000s total power zones across 10K Sprint + Tamaki TT)
    expect($outdoor['powerZones'])->toHaveCount(7)
        ->and($outdoor['powerZones'][1]['seconds'])->toBe(600.0)
        ->and($outdoor['powerZones'][1]['pct'])->toBe(30.0)
        ->and($outdoor['powerZones'][3]['seconds'])->toBe(1400.0)
        ->and($outdoor['powerZones'][3]['pct'])->toBe(70.0)
        ->and($outdoor['hrZones'])->toHaveCount(5)
        ->and($outdoor['hrZones'][2]['pct'])->toBe(25.0)
        ->and($outdoor['hrZones'][3]['pct'])->toBe(75.0);

    // Indoor filter: verifies 0.95 * max_20min_power FTP fallback (0.95 * 300 = 285.0) and 15s curve point
    $indoor = $service->road('indoor');
    expect($indoor['filters']['environment'])->toBe('indoor')
        ->and($indoor['summary']['ytd_rides'])->toBe(1)
        ->and($indoor['summary']['latest_ftp'])->toBe(285.0)
        ->and($indoor['summary']['w_per_kg'])->toBe(4.07)
        ->and($indoor['recentRides'])->toHaveCount(1)
        ->and(array_column($indoor['powerCurve'], 'seconds'))->toBe([1, 15, 1200]);
});

it('computes exact CTL, ATL, TSB exponential moving averages with training_load fallback and falls back power/HR zones to all-time', function () {
    // Old ride (>90d ago) with zone data to verify 90d -> all-time fallback
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2025-05-01 07:00:00'),
        'tss' => 100.0,
        'power_zone_2_seconds' => 1200.0,
        'power_zone_3_seconds' => 600.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 1800.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // Ride yesterday using training_load fallback (tss = null)
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'tss' => null,
        'training_load' => 100.0,
        'power_zone_1_seconds' => null,
        'power_zone_2_seconds' => null,
        'power_zone_3_seconds' => null,
        'power_zone_4_seconds' => null,
        'power_zone_5_seconds' => null,
        'power_zone_6_seconds' => null,
        'power_zone_7_seconds' => null,
        'hr_zone_1_seconds' => null,
        'hr_zone_2_seconds' => null,
        'hr_zone_3_seconds' => null,
        'hr_zone_4_seconds' => null,
        'hr_zone_5_seconds' => null,
    ]);

    $road = app(BikingHubService::class)->road();
    $series = $road['fitnessLoad'];
    $count = count($series);

    expect($count)->toBe(180);

    $yesterday = $series[$count - 2];
    $today = $series[$count - 1];

    // Yesterday: prior CTL=0, ATL=0 => TSB=0, CTL=100/42=2.381->2.4, ATL=100/7=14.286->14.3
    expect($yesterday)->toBe([
        'date' => '2026-11-20',
        'tss' => 100.0,
        'ctl' => 2.4,
        'atl' => 14.3,
        'tsb' => 0.0,
    ]);

    // Today: tss=0 => TSB = 2.38095 - 14.28571 = -11.9, CTL = 2.324->2.3, ATL = 12.245->12.2
    expect($today)->toBe([
        'date' => '2026-11-21',
        'tss' => 0.0,
        'ctl' => 2.3,
        'atl' => 12.2,
        'tsb' => -11.9,
    ])
        ->and($road['summary']['current_ctl'])->toBe(2.3)
        ->and($road['summary']['current_atl'])->toBe(12.2)
        ->and($road['summary']['current_tsb'])->toBe(-11.9);

    // 90d had no zone data, so falls back to all-time (2025 ride)
    expect($road['powerZones'][1]['seconds'])->toBe(1200.0)
        ->and($road['powerZones'][2]['seconds'])->toBe(600.0)
        ->and($road['hrZones'][1]['seconds'])->toBe(1800.0)
        ->and($road['hrZones'][1]['pct'])->toBe(100.0);
});

it('builds the MTB hub with vertical stats, VAM, steepness, dynamics leaderboards, trail regions, stamina, and milestones', function () {
    // Non-MTB ride must be ignored
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'elevation_gain' => 2000.0,
    ]);

    $rotoruaHard = Activity::factory()->mountainBiking()->create([
        'name' => 'Whakarewarewa Enduro',
        'location_name' => 'Rotorua',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 09:00:00'),
        'distance_meters' => 30000.0,
        'duration_seconds' => 7500.0,
        'moving_duration_seconds' => 7200.0, // 2h
        'elevation_gain' => 1200.0,
        'elevation_loss' => 1180.0,
        'grit' => 88.5,
        'avg_flow' => 6.4,
        'jump_count' => 18,
        'unpaved_pct' => 96.0,
        'begin_stamina' => 100,
        'end_stamina' => 40,
        'workout_feel' => 75,
        'workout_rpe' => 80,
        'hr_zone_1_seconds' => 200.0,
        'hr_zone_2_seconds' => 800.0,
        'hr_zone_3_seconds' => 600.0,
        'hr_zone_4_seconds' => 400.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $rotoruaSmooth = Activity::factory()->mountainBiking()->create([
        'name' => 'Corners Flow Session',
        'location_name' => 'Rotorua',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 15:00:00'),
        'distance_meters' => 15000.0,
        'duration_seconds' => 3600.0,
        'moving_duration_seconds' => 3600.0, // 1h
        'elevation_gain' => 600.0,
        'elevation_loss' => 610.0,
        'grit' => 32.0,
        'avg_flow' => 1.8, // lowest = smoothest!
        'jump_count' => 25,
        'unpaved_pct' => 92.0,
        'begin_stamina' => 98,
        'end_stamina' => 68,
        'workout_feel' => 100,
        'workout_rpe' => 60,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // 2025 MTB ride with null location (grouped into Unknown Location) and no grit/flow
    $oldUnknown = Activity::factory()->mountainBiking()->create([
        'name' => 'Old Forest Epic',
        'location_name' => null,
        'started_at_local' => CarbonImmutable::parse('2025-08-14 08:00:00'),
        'distance_meters' => 45000.0,
        'duration_seconds' => 10800.0,
        'moving_duration_seconds' => 10800.0, // 3h
        'elevation_gain' => 1800.0,
        'elevation_loss' => 1750.0,
        'grit' => null,
        'avg_flow' => null,
        'jump_count' => 0,
        'unpaved_pct' => 82.0,
        'begin_stamina' => null,
        'end_stamina' => null,
        'workout_feel' => null,
        'workout_rpe' => null,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $mtb = app(BikingHubService::class)->mtb();

    // Total elevation = 1200 + 600 + 1800 = 3600m; moving hours = 2 + 1 + 3 = 6h => VAM = 600.0 m/h
    // Total distance = 90km => steepness = 3600 / 90 = 40.0 m/km
    expect($mtb['summary'])->toBe([
        'ytd_elevation_gain' => 1800.0,
        'ytd_elevation_loss' => 1790.0,
        'ytd_rides' => 2,
        'ytd_distance_meters' => 45000.0,
        'ytd_duration_seconds' => 11100.0,
        'total_elevation_gain' => 3600.0,
        'total_elevation_loss' => 3540.0,
        'total_rides' => 3,
        'total_distance_meters' => 90000.0,
        'avg_vam' => 600.0,
        'steepness_density' => 40.0,
        'avg_grit' => 60.3,
        'avg_flow' => 4.1,
        'total_jumps' => 43,
        'avg_unpaved_pct' => 90.0,
    ]);

    // Current week vertical (Mon 2026-11-16)
    $currentWeek = $mtb['weeklyVertical'][23];
    expect($currentWeek['week_start'])->toBe('2026-11-16')
        ->and($currentWeek['elevation_gain'])->toBe(1800.0)
        ->and($currentWeek['elevation_loss'])->toBe(1790.0)
        ->and($currentWeek['distance_km'])->toBe(45.0)
        ->and($currentWeek['rides'])->toBe(2);

    // Dynamics trend: chronological (oldest first), excludes $oldUnknown which has null grit & avg_flow
    expect($mtb['dynamicsTrend'])->toHaveCount(2)
        ->and($mtb['dynamicsTrend'][0]['activity_id'])->toBe($rotoruaHard->id)
        ->and($mtb['dynamicsTrend'][1]['activity_id'])->toBe($rotoruaSmooth->id);

    // Leaderboards
    expect($mtb['hardestRides'][0]['id'])->toBe($rotoruaHard->id)
        ->and($mtb['smoothestRides'][0]['id'])->toBe($rotoruaSmooth->id)
        ->and($mtb['jumpHighlights'])->toHaveCount(2)
        ->and($mtb['jumpHighlights'][0]['id'])->toBe($rotoruaSmooth->id)
        ->and($mtb['jumpHighlights'][0]['jump_count'])->toBe(25);

    // Trail regions: Rotorua (1800m, 2 rides, 50%) and Unknown Location (1800m, 1 ride, 50%)
    expect($mtb['trailRegions'])->toHaveCount(2)
        ->and($mtb['trailRegions'][0])->toBe([
            'name' => 'Rotorua',
            'rides' => 2,
            'elevation_gain' => 1800.0,
            'distance_meters' => 45000.0,
            'avg_grit' => 60.3,
            'pct' => 50.0,
        ])
        ->and($mtb['trailRegions'][1])->toBe([
            'name' => 'Unknown Location',
            'rides' => 1,
            'elevation_gain' => 1800.0,
            'distance_meters' => 45000.0,
            'avg_grit' => null,
            'pct' => 50.0,
        ]);

    // Stamina & Feel: begin avg=(100+98)/2=99, end avg=(40+68)/2=54, drain avg=(60+30)/2=45
    expect($mtb['staminaAndFeel'])->toBe([
        'avg_begin_stamina' => 99.0,
        'avg_end_stamina' => 54.0,
        'avg_stamina_drain' => 45.0,
        'avg_feel' => 87.5,
        'avg_rpe' => 70.0,
        'stamina_samples' => 2,
        'feel_samples' => 2,
    ]);

    // Milestones
    expect($mtb['milestones']['biggest_climb']['activity_id'])->toBe($oldUnknown->id)
        ->and($mtb['milestones']['longest_ride']['activity_id'])->toBe($oldUnknown->id)
        ->and($mtb['milestones']['hardest_grit']['activity_id'])->toBe($rotoruaHard->id)
        ->and($mtb['milestones']['smoothest_flow']['activity_id'])->toBe($rotoruaSmooth->id)
        ->and($mtb['milestones']['most_jumps']['activity_id'])->toBe($rotoruaSmooth->id)
        ->and($mtb['hrZones'])->toHaveCount(5)
        ->and($mtb['hrZones'][1]['pct'])->toBe(40.0)
        ->and($mtb['recentRides'])->toHaveCount(3);
});
