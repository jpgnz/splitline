<?php

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Vo2MaxRecord;
use App\Services\Running\RunningHubService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('builds a clean empty-state running hub payload when no running data exists', function () {
    $payload = app(RunningHubService::class)->dashboard();

    expect($payload['summary'])->toBe([
        'ytd_distance_meters' => 0.0,
        'ytd_runs' => 0,
        'ytd_duration_seconds' => 0.0,
        'ytd_elevation_gain' => 0.0,
        'ytd_avg_speed' => null,
        'latest_vo2_max' => null,
        'weekly_streak' => 0,
        'daily_streak' => 0,
    ])
        ->and($payload['bestEfforts']['selectedYear'])->toBe(2026)
        ->and($payload['bestEfforts']['availableYears'])->toBe([2026])
        ->and($payload['bestEfforts']['distances'])->toHaveCount(14)
        ->and($payload['bestEfforts']['distances'][0]['key'])->toBe('400m')
        ->and($payload['bestEfforts']['distances'][13]['key'])->toBe('50k')
        ->and($payload['bestEfforts']['distances'][5]['label'])->toBe('5K')
        ->and($payload['bestEfforts']['distances'][5]['allTimeTop3'])->toBe([])
        ->and($payload['weeklyMileage'])->toHaveCount(12)
        ->and($payload['ytdCumulative'])->toBe([])
        ->and($payload['vo2Trend'])->toBe([])
        ->and($payload['aerobicEfficiency'])->toBe([])
        ->and($payload['hrZonePolarization']['total_seconds'])->toBe(0.0)
        ->and($payload['hrZonePolarization']['easy_pct'])->toBe(0.0)
        ->and($payload['cadenceStrideTrend'])->toBe([])
        ->and($payload['racePredictor'])->toBeNull()
        ->and($payload['recentRuns'])->toBe([])
        ->and($payload['filters'])->toBe([
            'environment' => 'all',
            'volumeRange' => '12w',
            'year' => 2026,
            'zoneRange' => '30d',
        ]);
});

it('builds all-time top 3, annual top 10, PR progression, override ranking, and excluded effort promotion', function () {
    $run2024Fast = Activity::factory()->create([
        'name' => '2024 Parkrun PB',
        'started_at_local' => CarbonImmutable::parse('2024-06-15 08:00:00'),
    ]);

    $run2025Slower = Activity::factory()->create([
        'name' => '2025 Tempo 5K',
        'started_at_local' => CarbonImmutable::parse('2025-04-10 07:00:00'),
    ]);

    $run2026Glitch = Activity::factory()->create([
        'name' => '2026 GNSS Glitch Run',
        'started_at_local' => CarbonImmutable::parse('2026-03-01 07:00:00'),
    ]);

    $run2026Race = Activity::factory()->create([
        'name' => '2026 Harbour 5K Race',
        'started_at_local' => CarbonImmutable::parse('2026-09-12 08:00:00'),
    ]);

    $run2026Third = Activity::factory()->create([
        'name' => '2026 Track 5K',
        'started_at_local' => CarbonImmutable::parse('2026-10-05 07:00:00'),
    ]);

    $run2026Fourth = Activity::factory()->create([
        'name' => '2026 Easy 5K',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
    ]);

    // 2024 effort: 1200s (20:00)
    $effort2024 = BestEffort::factory()->create([
        'activity_id' => $run2024Fast->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1200.0,
        'achieved_at' => $run2024Fast->started_at_local,
    ]);

    // 2025 effort: 1240s (slower than 2024, so NOT a PR)
    BestEffort::factory()->create([
        'activity_id' => $run2025Slower->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1240.0,
        'achieved_at' => $run2025Slower->started_at_local,
    ]);

    // 2026 GNSS glitch excluded: 900s (excluded so must NOT appear in rankings or progression, MUST appear in excluded)
    $excludedEffort = BestEffort::factory()->excluded()->create([
        'activity_id' => $run2026Glitch->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 900.0,
        'achieved_at' => $run2026Glitch->started_at_local,
    ]);

    // 2026 Race: GNSS elapsed 1210s, but official chip override 1175s -> becomes #1 All-Time and #1 in 2026!
    $overrideEffort = BestEffort::factory()->create([
        'activity_id' => $run2026Race->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1210.0,
        'override_seconds' => 1175.0,
        'achieved_at' => $run2026Race->started_at_local,
    ]);

    // 2026 Track: 1220s -> #3 All-Time (after 1175s and 1200s) and #2 in 2026
    $thirdEffort = BestEffort::factory()->create([
        'activity_id' => $run2026Third->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1220.0,
        'achieved_at' => $run2026Third->started_at_local,
    ]);

    // 2026 Easy: 1300s -> outside Top 3 All-Time, #3 in 2026
    BestEffort::factory()->create([
        'activity_id' => $run2026Fourth->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1300.0,
        'achieved_at' => $run2026Fourth->started_at_local,
    ]);

    // Non-running effort must be ignored
    BestEffort::factory()->create([
        'activity_id' => Activity::factory()->roadBiking()->create()->id,
        'sport' => 'road_biking',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 450.0,
        'achieved_at' => CarbonImmutable::parse('2026-10-01 07:00:00'),
    ]);

    $payload = app(RunningHubService::class)->dashboard(year: 2026);
    $board = $payload['bestEfforts'];

    expect($board['selectedYear'])->toBe(2026)
        ->and($board['availableYears'])->toBe([2026, 2025, 2024]);

    $fiveK = collect($board['distances'])->firstWhere('key', '5k');
    expect($fiveK)->not->toBeNull();

    // All-Time Top 3: override (1175s) -> 2024 (1200s) -> 2026 track (1220s)
    expect($fiveK['allTimeTop3'])->toHaveCount(3)
        ->and($fiveK['allTimeTop3'][0]['id'])->toBe($overrideEffort->id)
        ->and($fiveK['allTimeTop3'][0]['rank'])->toBe(1)
        ->and($fiveK['allTimeTop3'][0]['elapsed_seconds'])->toBe(1210.0)
        ->and($fiveK['allTimeTop3'][0]['effective_seconds'])->toBe(1175.0)
        ->and($fiveK['allTimeTop3'][0]['override_seconds'])->toBe(1175.0)
        ->and($fiveK['allTimeTop3'][0]['pace_seconds_per_km'])->toBe(235.0)
        ->and($fiveK['allTimeTop3'][1]['id'])->toBe($effort2024->id)
        ->and($fiveK['allTimeTop3'][1]['rank'])->toBe(2)
        ->and($fiveK['allTimeTop3'][2]['id'])->toBe($thirdEffort->id)
        ->and($fiveK['allTimeTop3'][2]['rank'])->toBe(3);

    // Annual Top 10 for 2026: only the 3 non-excluded 2026 efforts
    expect($fiveK['annualTop10'])->toHaveCount(3)
        ->and(array_column($fiveK['annualTop10'], 'effective_seconds'))->toBe([1175.0, 1220.0, 1300.0])
        ->and(array_column($fiveK['annualTop10'], 'rank'))->toBe([1, 2, 3]);

    // Chronological PR progression: 2024 (1200, PR) -> 2025 (1240, false) -> 2026-09 (1175, PR) -> 2026-10 (1220, false) -> 2026-11 (1300, false)
    expect($fiveK['progression'])->toHaveCount(5)
        ->and(array_column($fiveK['progression'], 'effective_seconds'))->toBe([1200.0, 1240.0, 1175.0, 1220.0, 1300.0])
        ->and(array_column($fiveK['progression'], 'is_pr'))->toBe([true, false, true, false, false]);

    // Excluded list contains the GNSS glitch for restoration
    expect($fiveK['excluded'])->toHaveCount(1)
        ->and($fiveK['excluded'][0]['id'])->toBe($excludedEffort->id)
        ->and($fiveK['excluded'][0]['is_excluded'])->toBeTrue();

    // Annual Top 10 capped at 10 when more than 10 exist in selected year
    for ($i = 1; $i <= 11; $i++) {
        $act = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse('2025-05-01 07:00:00')->addDays($i),
        ]);
        BestEffort::factory()->create([
            'activity_id' => $act->id,
            'sport' => 'running',
            'distance_key' => '1k',
            'distance_meters' => 1000.0,
            'elapsed_seconds' => 200.0 + ($i * 5.0),
            'achieved_at' => $act->started_at_local,
        ]);
    }

    $board2025 = app(RunningHubService::class)->dashboard(year: 2025)['bestEfforts'];
    $oneK = collect($board2025['distances'])->firstWhere('key', '1k');
    expect($oneK['annualTop10'])->toHaveCount(10)
        ->and($oneK['annualTop10'][0]['effective_seconds'])->toBe(205.0)
        ->and($oneK['annualTop10'][9]['effective_seconds'])->toBe(250.0);
});

it('calculates weekly mileage buckets and accurate 4-week rolling averages across volume ranges', function () {
    // Current date is Sat 2026-11-21 -> current week starts Mon 2026-11-16
    // Seed 4 consecutive weeks ending this week: 10km, 20km, 30km, 40km
    $weeks = [
        ['2026-10-27 07:00:00', 10000.0, 120.0], // week of 2026-10-26
        ['2026-11-04 07:00:00', 20000.0, 240.0], // week of 2026-11-02
        ['2026-11-11 07:00:00', 30000.0, 360.0], // week of 2026-11-09
        ['2026-11-18 07:00:00', 25000.0, 300.0], // week of 2026-11-16 (run 1)
        ['2026-11-20 07:00:00', 15000.0, 150.0], // week of 2026-11-16 (run 2 -> total 40km, 2 runs, 450m)
    ];

    foreach ($weeks as [$date, $distance, $elevation]) {
        Activity::factory()->create([
            'sport_category' => 'running',
            'started_at_local' => CarbonImmutable::parse($date),
            'distance_meters' => $distance,
            'elevation_gain' => $elevation,
        ]);
    }

    // Seed a run 14 weeks ago (before the 12w window, inside its 3-week rolling lookback!)
    // 12w starts Mon 2026-08-31. Week -1 before window is 2026-08-24.
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-08-25 07:00:00'),
        'distance_meters' => 40000.0,
        'elevation_gain' => 500.0,
    ]);

    $service = app(RunningHubService::class);
    $weekly12w = $service->dashboard(volumeRange: '12w')['weeklyMileage'];

    expect($weekly12w)->toHaveCount(12)
        ->and($weekly12w[0]['week_start'])->toBe('2026-08-31')
        ->and($weekly12w[0]['km'])->toBe(0.0)
        // Lookback picked up 40km in 2026-08-24 -> 40 / 4 = 10.0 km rolling average on first week of 12w!
        ->and($weekly12w[0]['rolling_4w_km'])->toBe(10.0);

    $lastWeek = $weekly12w[11];
    expect($lastWeek['week_start'])->toBe('2026-11-16')
        ->and($lastWeek['km'])->toBe(40.0)
        ->and($lastWeek['runs'])->toBe(2)
        ->and($lastWeek['elevation_m'])->toBe(450.0)
        // (10 + 20 + 30 + 40) / 4 = 25.0 km
        ->and($lastWeek['rolling_4w_km'])->toBe(25.0);

    expect($service->dashboard(volumeRange: '6m')['weeklyMileage'])->toHaveCount(26)
        ->and($service->dashboard(volumeRange: '1y')['weeklyMileage'])->toHaveCount(52)
        ->and($service->dashboard(volumeRange: 'all')['weeklyMileage'])->toHaveCount(13); // 2026-08-24 .. 2026-11-16
});

it('builds multi-year YTD cumulative overlay stopping current year cleanly at today week index', function () {
    // 5 years of runs -> should only include 4 most recent years (2026, 2025, 2024, 2023)
    foreach ([2022, 2023, 2024, 2025] as $year) {
        Activity::factory()->create([
            'sport_category' => 'running',
            'started_at_local' => CarbonImmutable::parse("{$year}-01-05 07:00:00"),
            'distance_meters' => 10000.0,
        ]);
        Activity::factory()->create([
            'sport_category' => 'running',
            'started_at_local' => CarbonImmutable::parse("{$year}-06-15 07:00:00"),
            'distance_meters' => 15000.0,
        ]);
    }

    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-01-02 07:00:00'),
        'distance_meters' => 12000.0,
    ]);
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 8000.0,
    ]);

    $ytd = app(RunningHubService::class)->dashboard()['ytdCumulative'];

    expect($ytd)->toHaveCount(4)
        ->and(array_column($ytd, 'year'))->toBe([2026, 2025, 2024, 2023]);

    // 2026-11-21 is dayOfYear 325 -> intdiv(324, 7) = 46 -> 47 points (indices 0..46)
    expect($ytd[0]['year'])->toBe(2026)
        ->and($ytd[0]['total_km'])->toBe(20.0)
        ->and($ytd[0]['points'])->toHaveCount(47)
        ->and($ytd[0]['points'][0])->toBe(12.0)
        ->and($ytd[0]['points'][46])->toBe(20.0);

    // Past years always have full 52 cumulative points
    expect($ytd[1]['year'])->toBe(2025)
        ->and($ytd[1]['total_km'])->toBe(25.0)
        ->and($ytd[1]['points'])->toHaveCount(52)
        ->and($ytd[1]['points'][0])->toBe(10.0)
        ->and($ytd[1]['points'][51])->toBe(25.0);
});

it('calculates running summary KPIs, streaks, VO2 trend, aerobic efficiency, HR zone polarization, and cadence/stride trends', function () {
    // Consecutive days: Nov 18, Nov 19, Nov 20 (yesterday) -> daily streak = 3
    // Consecutive weeks: week of Nov 16, week of Nov 9, week of Nov 2 -> weekly streak = 3
    Activity::factory()->create([
        'name' => 'Base Run W1',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'elevation_gain' => 100.0,
        'avg_speed' => 3.333,
        'avg_hr' => 145,
        'avg_cadence' => 174.0,
        'avg_stride_length' => 1.15, // meters -> normalized to 115.0 cm
        'hr_zone_1_seconds' => 300.0,
        'hr_zone_2_seconds' => 1800.0,
        'hr_zone_3_seconds' => 600.0,
        'hr_zone_4_seconds' => 240.0,
        'hr_zone_5_seconds' => 60.0,
    ]);

    Activity::factory()->create([
        'name' => 'Threshold Run W2',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-12 07:00:00'),
        'distance_meters' => 8000.0,
        'duration_seconds' => 2000.0,
        'elevation_gain' => 80.0,
        'avg_speed' => 4.0,
        'avg_hr' => 160,
        'avg_cadence' => 180.5,
        'avg_stride_length' => 133.4, // already cm -> stays 133.4 cm
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 600.0,
        'hr_zone_3_seconds' => 400.0,
        'hr_zone_4_seconds' => 800.0,
        'hr_zone_5_seconds' => 200.0,
    ]);

    foreach (['2026-11-18', '2026-11-19', '2026-11-20'] as $date) {
        Activity::factory()->create([
            'name' => "Streak Run {$date}",
            'sport_category' => 'running',
            'started_at_local' => CarbonImmutable::parse("{$date} 07:00:00"),
            'distance_meters' => 5000.0,
            'duration_seconds' => 1500.0,
            'elevation_gain' => 40.0,
            'avg_speed' => 3.5,
            'avg_hr' => 150,
            'avg_cadence' => 176.0,
            'avg_stride_length' => 1.20,
            'hr_zone_1_seconds' => 0.0,
            'hr_zone_2_seconds' => 0.0,
            'hr_zone_3_seconds' => 0.0,
            'hr_zone_4_seconds' => 0.0,
            'hr_zone_5_seconds' => 0.0,
        ]);
    }

    // Run 60 days ago: included in 90d HR zones, excluded from 30d HR zones
    Activity::factory()->create([
        'name' => 'September Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-09-20 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'elevation_gain' => 50.0,
        'avg_speed' => null,
        'avg_hr' => null,
        'avg_cadence' => null,
        'avg_stride_length' => null,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 5000.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-10-01',
        'sport' => 'running',
        'vo2_max_precise' => 55.2,
    ]);
    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-15',
        'sport' => 'running',
        'vo2_max_precise' => 57.4,
    ]);
    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'sport' => 'cycling',
        'vo2_max_precise' => 62.0,
    ]);

    $service = app(RunningHubService::class);
    $payload30d = $service->dashboard(zoneRange: '30d');

    expect($payload30d['summary']['ytd_runs'])->toBe(6)
        ->and($payload30d['summary']['ytd_distance_meters'])->toBe(43000.0)
        ->and($payload30d['summary']['ytd_duration_seconds'])->toBe(12500.0)
        ->and($payload30d['summary']['ytd_elevation_gain'])->toBe(350.0)
        ->and($payload30d['summary']['ytd_avg_speed'])->toBe(round(43000.0 / 12500.0, 3))
        ->and($payload30d['summary']['latest_vo2_max'])->toBe(57.4)
        ->and($payload30d['summary']['weekly_streak'])->toBe(3)
        ->and($payload30d['summary']['daily_streak'])->toBe(3);

    expect($payload30d['vo2Trend'])->toBe([
        ['date' => '2026-10-01', 'value' => 55.2],
        ['date' => '2026-11-15', 'value' => 57.4],
    ]);

    // Aerobic efficiency: chronological, skips September run with null speed/HR
    // Threshold Run W2: avg_speed 4.0, avg_hr 160 -> efficiency = (4.0 * 60) / 160 = 1.5, pace = 250.0 s/km
    expect($payload30d['aerobicEfficiency'])->toHaveCount(5)
        ->and($payload30d['aerobicEfficiency'][0]['name'])->toBe('Base Run W1')
        ->and($payload30d['aerobicEfficiency'][1]['name'])->toBe('Threshold Run W2')
        ->and($payload30d['aerobicEfficiency'][1]['pace_sec_per_km'])->toBe(250.0)
        ->and($payload30d['aerobicEfficiency'][1]['avg_hr'])->toBe(160)
        ->and($payload30d['aerobicEfficiency'][1]['efficiency'])->toBe(1.5);

    // HR zone polarization (30d): Z1=300, Z2=2400, Z3=1000, Z4=1040, Z5=260 -> Total=5000s
    // Easy (Z1+Z2) = 2700/5000 = 54.0%, Moderate (Z3) = 1000/5000 = 20.0%, Hard (Z4+Z5) = 1300/5000 = 26.0%
    $hr30 = $payload30d['hrZonePolarization'];
    expect($hr30['range'])->toBe('30d')
        ->and($hr30['total_seconds'])->toBe(5000.0)
        ->and($hr30['zones'])->toHaveCount(5)
        ->and($hr30['zones'][1]['pct'])->toBe(48.0)
        ->and($hr30['easy_pct'])->toBe(54.0)
        ->and($hr30['moderate_pct'])->toBe(20.0)
        ->and($hr30['hard_pct'])->toBe(26.0);

    // HR zone polarization (90d): adds 5000s in Z2 -> Total=10000s, Easy=7700/10000=77.0%
    $hr90 = $service->dashboard(zoneRange: '90d')['hrZonePolarization'];
    expect($hr90['range'])->toBe('90d')
        ->and($hr90['total_seconds'])->toBe(10000.0)
        ->and($hr90['easy_pct'])->toBe(77.0);

    // Cadence & stride length normalization
    expect($payload30d['cadenceStrideTrend'])->toHaveCount(5)
        ->and($payload30d['cadenceStrideTrend'][0]['cadence'])->toBe(174.0)
        ->and($payload30d['cadenceStrideTrend'][0]['stride_length_cm'])->toBe(115.0)
        ->and($payload30d['cadenceStrideTrend'][1]['cadence'])->toBe(180.5)
        ->and($payload30d['cadenceStrideTrend'][1]['stride_length_cm'])->toBe(133.4);
});

it('computes Riegel race predictions preferring recent key distances and falling back to all-time qualifying efforts', function () {
    $oldFast10k = Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2024-05-01 07:00:00'),
    ]);
    BestEffort::factory()->create([
        'activity_id' => $oldFast10k->id,
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2300.0, // faster, but > 12 months old
        'achieved_at' => $oldFast10k->started_at_local,
    ]);

    $recent5k = Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-08-01 07:00:00'),
    ]);
    $recent5kEffort = BestEffort::factory()->create([
        'activity_id' => $recent5k->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1200.0, // 20:00 5K within last 12 months
        'achieved_at' => $recent5k->started_at_local,
    ]);

    $predictor = app(RunningHubService::class)->dashboard()['racePredictor'];

    expect($predictor)->not->toBeNull()
        ->and($predictor['source_label'])->toBe('5K')
        ->and($predictor['source_time_seconds'])->toBe(1200.0)
        ->and($predictor['source_date'])->toBe('2026-08-01')
        ->and($predictor['predictions'])->toHaveCount(4);

    // 10K prediction from 1200s 5K: 1200 * (10000 / 5000)^1.06 = 2501.8
    $expected10k = round(1200.0 * (2.0 ** 1.06), 1);
    $expectedHm = round(1200.0 * ((21097.5 / 5000.0) ** 1.06), 1);
    $expectedMarathon = round(1200.0 * ((42195.0 / 5000.0) ** 1.06), 1);

    expect($predictor['predictions'][0]['key'])->toBe('5k')
        ->and($predictor['predictions'][0]['predicted_seconds'])->toBe(1200.0)
        ->and($predictor['predictions'][1]['key'])->toBe('10k')
        ->and($predictor['predictions'][1]['predicted_seconds'])->toBe($expected10k)
        ->and($predictor['predictions'][2]['key'])->toBe('half_marathon')
        ->and($predictor['predictions'][2]['predicted_seconds'])->toBe($expectedHm)
        ->and($predictor['predictions'][3]['key'])->toBe('marathon')
        ->and($predictor['predictions'][3]['predicted_seconds'])->toBe($expectedMarathon);

    // When the recent 5K is excluded, predictor falls back to the all-time 2024 10K effort
    $recent5kEffort->update(['is_excluded' => true]);

    $fallbackPredictor = app(RunningHubService::class)->dashboard()['racePredictor'];
    expect($fallbackPredictor)->not->toBeNull()
        ->and($fallbackPredictor['source_label'])->toBe('10K')
        ->and($fallbackPredictor['source_time_seconds'])->toBe(2300.0)
        ->and($fallbackPredictor['source_date'])->toBe('2024-05-01');
});

it('filters running telemetry by outdoor vs treadmill environment and ignores non-running sports', function () {
    $outdoorRun = Activity::factory()->create([
        'name' => 'Waterfront Outdoor Run',
        'sport_category' => 'running',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3600.0,
        'avg_speed' => 3.333,
        'avg_hr' => 150,
        'avg_cadence' => 176.0,
    ]);

    $treadmillRun = Activity::factory()->indoor()->create([
        'name' => 'Gym Treadmill Intervals',
        'activity_type_key' => 'treadmill_running',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 8000.0,
        'duration_seconds' => 2400.0,
        'avg_speed' => 3.333,
        'avg_hr' => 162,
        'avg_cadence' => 182.0,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Road Ride Should Be Ignored',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 07:00:00'),
        'distance_meters' => 60000.0,
    ]);

    $service = app(RunningHubService::class);

    $all = $service->dashboard(environment: 'all');
    expect($all['summary']['ytd_runs'])->toBe(2)
        ->and($all['summary']['ytd_distance_meters'])->toBe(20000.0)
        ->and($all['recentRuns'])->toHaveCount(2);

    $outdoor = $service->dashboard(environment: 'outdoor');
    expect($outdoor['filters']['environment'])->toBe('outdoor')
        ->and($outdoor['summary']['ytd_runs'])->toBe(1)
        ->and($outdoor['summary']['ytd_distance_meters'])->toBe(12000.0)
        ->and(array_column($outdoor['recentRuns'], 'id'))->toBe([$outdoorRun->id])
        ->and(array_column($outdoor['aerobicEfficiency'], 'activity_id'))->toBe([$outdoorRun->id]);

    $treadmill = $service->dashboard(environment: 'treadmill');
    expect($treadmill['filters']['environment'])->toBe('treadmill')
        ->and($treadmill['summary']['ytd_runs'])->toBe(1)
        ->and($treadmill['summary']['ytd_distance_meters'])->toBe(8000.0)
        ->and(array_column($treadmill['recentRuns'], 'id'))->toBe([$treadmillRun->id])
        ->and(array_column($treadmill['aerobicEfficiency'], 'activity_id'))->toBe([$treadmillRun->id]);
});
