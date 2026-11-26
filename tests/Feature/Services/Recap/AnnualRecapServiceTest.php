<?php

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Services\Recap\AnnualRecapService;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Pacific/Auckland'));
});

it('returns a clean empty state when no activities or health records exist', function () {
    $payload = app(AnnualRecapService::class)->build();

    expect($payload['selected_year'])->toBe(2026)
        ->and($payload['available_years'])->toBe([])
        ->and($payload['today'])->toBe('2026-06-15')
        ->and($payload['is_current_year'])->toBeTrue()
        ->and($payload['has_data'])->toBeFalse()
        ->and($payload['totals'])->toBe([
            'activities' => 0,
            'active_days' => 0,
            'distance_meters' => 0.0,
            'duration_seconds' => 0.0,
            'elevation_gain' => 0.0,
            'calories' => 0,
            'training_load' => 0.0,
        ])
        ->and($payload['yoy'])->toBe([
            'previous_year' => 2025,
            'is_ytd_comparison' => true,
            'ytd_cutoff_date' => '2026-06-15',
            'has_previous_data' => false,
            'distance_pct' => null,
            'duration_pct' => null,
            'elevation_pct' => null,
            'activities_pct' => null,
        ])
        ->and($payload['sport_breakdown'])->toBe([])
        ->and($payload['peaks'])->toBe([
            'biggest_week' => null,
            'biggest_month' => null,
            'longest_activity' => null,
            'longest_duration_activity' => null,
            'biggest_climb_activity' => null,
        ])
        ->and($payload['consistency'])->toBe([
            'longest_daily_streak' => ['days' => 0, 'start_date' => null, 'end_date' => null],
            'longest_weekly_streak' => ['weeks' => 0, 'start_week' => null, 'end_week' => null],
            'busiest_weekday' => null,
            'favorite_time_of_day' => null,
        ])
        ->and($payload['milestones'])->toBe([
            'all_time_prs_count' => 0,
            'best_efforts' => [],
            'matched_routes_completed' => 0,
            'most_repeated_route' => null,
            'segment_prs_count' => 0,
            'segment_efforts_count' => 0,
            'segment_prs' => [],
        ])
        ->and($payload['weather'])->toBe([
            'sample_count' => 0,
            'rainy_sessions_count' => 0,
            'coldest_activity' => null,
            'hottest_activity' => null,
            'windiest_activity' => null,
            'wettest_activity' => null,
        ])
        ->and($payload['health'])->toBe([
            'has_data' => false,
            'avg_sleep_hours' => null,
            'avg_sleep_score' => null,
            'sleep_nights' => 0,
            'avg_resting_hr' => null,
            'avg_hrv' => null,
            'total_steps' => 0,
            'avg_daily_steps' => null,
            'peak_vo2_max' => null,
            'peak_vo2_max_sport' => null,
            'peak_vo2_max_date' => null,
        ])
        ->and($payload['monthly'])->toHaveCount(12)
        ->and($payload['annual_summary']['years'])->toBe([])
        ->and($payload['annual_summary']['daily'])->toBe([]);
});

it('computes headline totals and apples-to-apples YTD YoY deltas for the current year and full-year YoY for past years', function () {
    // 2026 YTD (on/before 2026-06-15): 2 activities, 30 km, 7200 s, 600 m gain
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-02-10 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'elevation_gain' => 200.0,
        'calories' => 650,
        'tss' => null,
        'training_load' => 85.0,
    ]);
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-05-20 07:30:00'),
        'distance_meters' => 20000.0,
        'duration_seconds' => 4200.0,
        'elevation_gain' => 400.0,
        'calories' => 1150,
        'tss' => 120.0,
        'training_load' => 90.0,
    ]);

    // 2025 YTD (on/before 2025-06-15): 1 activity, 20 km, 4800 s, 400 m gain
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2025-04-01 08:00:00'),
        'distance_meters' => 20000.0,
        'duration_seconds' => 4800.0,
        'elevation_gain' => 400.0,
        'calories' => 900,
        'tss' => null,
        'training_load' => 100.0,
    ]);

    // 2025 AFTER YTD cutoff (2025-09-10): must NOT affect 2026 YTD YoY, MUST count in full-year 2025
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2025-09-10 08:00:00'),
        'distance_meters' => 50000.0,
        'duration_seconds' => 9600.0,
        'elevation_gain' => 1000.0,
        'calories' => 2200,
        'tss' => null,
        'training_load' => 210.0,
    ]);

    // 2024 full year: 1 activity, 35 km, 7200 s, 700 m gain
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2024-11-15 09:00:00'),
        'distance_meters' => 35000.0,
        'duration_seconds' => 7200.0,
        'elevation_gain' => 700.0,
        'calories' => 1500,
        'tss' => null,
        'training_load' => 140.0,
    ]);

    $service = app(AnnualRecapService::class);
    $recap2026 = $service->build(2026);

    expect($recap2026['available_years'])->toBe([2026, 2025, 2024])
        ->and($recap2026['totals'])->toBe([
            'activities' => 2,
            'active_days' => 2,
            'distance_meters' => 30000.0,
            'duration_seconds' => 7200.0,
            'elevation_gain' => 600.0,
            'calories' => 1800,
            'training_load' => 205.0,
        ])
        ->and($recap2026['yoy'])->toBe([
            'previous_year' => 2025,
            'is_ytd_comparison' => true,
            'ytd_cutoff_date' => '2026-06-15',
            'has_previous_data' => true,
            'distance_pct' => 50.0,
            'duration_pct' => 50.0,
            'elevation_pct' => 50.0,
            'activities_pct' => 100.0,
        ]);

    // Full-year comparison when viewing 2025 (2025 total = 70km vs 2024 total = 35km -> +100%)
    $recap2025 = $service->build(2025);

    expect($recap2025['is_current_year'])->toBeFalse()
        ->and($recap2025['totals']['distance_meters'])->toBe(70000.0)
        ->and($recap2025['yoy'])->toBe([
            'previous_year' => 2024,
            'is_ytd_comparison' => false,
            'ytd_cutoff_date' => null,
            'has_previous_data' => true,
            'distance_pct' => 100.0,
            'duration_pct' => 100.0,
            'elevation_pct' => 100.0,
            'activities_pct' => 100.0,
        ]);
});

it('builds sport breakdown, peak moments, monthly progression, and daily and weekly streaks', function () {
    // Week 1 (Mon Mar 2 – Sun Mar 8, 2026): 3 consecutive days -> 3-day streak
    Activity::factory()->create([
        'name' => 'Monday Shakeout',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-03-02 06:30:00'),
        'distance_meters' => 8000.0,
        'duration_seconds' => 2400.0,
        'elevation_gain' => 60.0,
    ]);
    Activity::factory()->create([
        'name' => 'Tuesday Tempo',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-03-03 06:45:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3600.0,
        'elevation_gain' => 120.0,
    ]);
    $climbHike = Activity::factory()->hiking()->create([
        'name' => 'Summit Ridge Hike',
        'started_at_local' => CarbonImmutable::parse('2026-03-04 06:15:00'),
        'distance_meters' => 14000.0,
        'duration_seconds' => 10800.0,
        'elevation_gain' => 1250.0,
    ]);

    // Week 2 (Mon Mar 9 – Sun Mar 15, 2026): biggest week by distance (60 km)
    $longRide = Activity::factory()->roadBiking()->create([
        'name' => 'Century Loop',
        'started_at_local' => CarbonImmutable::parse('2026-03-14 07:00:00'),
        'distance_meters' => 60000.0,
        'duration_seconds' => 7200.0,
        'elevation_gain' => 780.0,
    ]);

    // Week 3 (Mon Mar 16 – Sun Mar 22, 2026): extends weekly streak to 3 consecutive weeks
    Activity::factory()->create([
        'name' => 'Recovery Jog',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-03-16 06:20:00'),
        'distance_meters' => 6000.0,
        'duration_seconds' => 2000.0,
        'elevation_gain' => 40.0,
    ]);

    // Gap week, then May activity
    Activity::factory()->create([
        'name' => 'May Easy Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-05-04 18:15:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
        'elevation_gain' => 80.0,
    ]);

    $payload = app(AnnualRecapService::class)->build(2026);

    // Sports sorted by duration desc: running (11000s), hiking (10800s), biking (7200s) -> total 29000s
    expect(array_column($payload['sport_breakdown'], 'key'))->toBe(['running', 'hiking', 'biking'])
        ->and($payload['sport_breakdown'][0]['label'])->toBe('Running')
        ->and($payload['sport_breakdown'][0]['count'])->toBe(4)
        ->and($payload['sport_breakdown'][0]['distance_meters'])->toBe(36000.0)
        ->and($payload['sport_breakdown'][2]['label'])->toBe('Cycling')
        ->and($payload['sport_breakdown'][2]['distance_meters'])->toBe(60000.0);

    // Peak moments
    expect($payload['peaks']['biggest_week'])->toMatchArray([
        'week_start' => '2026-03-09',
        'week_end' => '2026-03-15',
        'distance_meters' => 60000.0,
        'count' => 1,
    ])
        ->and($payload['peaks']['biggest_month'])->toMatchArray([
            'month' => 3,
            'label' => 'March',
            'distance_meters' => 100000.0,
            'count' => 5,
        ])
        ->and($payload['peaks']['longest_activity']['id'])->toBe($longRide->id)
        ->and($payload['peaks']['longest_duration_activity']['id'])->toBe($climbHike->id)
        ->and($payload['peaks']['biggest_climb_activity']['id'])->toBe($climbHike->id);

    // Streaks & habits (3 Mondays: Mar 2, Mar 16, May 4; 5 Early Morning 5-8 AM sessions)
    expect($payload['consistency']['longest_daily_streak'])->toBe([
        'days' => 3,
        'start_date' => '2026-03-02',
        'end_date' => '2026-03-04',
    ])
        ->and($payload['consistency']['longest_weekly_streak'])->toBe([
            'weeks' => 3,
            'start_week' => '2026-03-02',
            'end_week' => '2026-03-16',
        ])
        ->and($payload['consistency']['busiest_weekday']['day'])->toBe('Monday')
        ->and($payload['consistency']['busiest_weekday']['count'])->toBe(3)
        ->and($payload['consistency']['favorite_time_of_day']['bucket'])->toBe('early_morning')
        ->and($payload['consistency']['favorite_time_of_day']['count'])->toBe(5);

    // Monthly bucket 3 (index 2)
    expect($payload['monthly'][2]['label'])->toBe('Mar')
        ->and($payload['monthly'][2]['activities'])->toBe(5)
        ->and($payload['monthly'][2]['distance_meters'])->toBe(100000.0);
});

it('identifies best efforts, all-time PRs, matched routes, and segment PRs for the year', function () {
    $run2025 = Activity::factory()->create([
        'name' => 'Fast 2025 10K',
        'started_at_local' => CarbonImmutable::parse('2025-05-10 07:00:00'),
    ]);
    $run2026Fast = Activity::factory()->create([
        'name' => 'Spring 5K PR',
        'started_at_local' => CarbonImmutable::parse('2026-04-12 07:00:00'),
    ]);
    $run2026Repeat = Activity::factory()->create([
        'name' => 'Waterfront Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-04-20 07:00:00'),
    ]);

    // 5K: 2026 is an all-time PR (1180s vs 1250s in 2025); excluded faster effort must be ignored
    BestEffort::factory()->for($run2025)->create([
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1250.0,
        'achieved_at' => CarbonImmutable::parse('2025-05-10 07:00:00'),
    ]);
    BestEffort::factory()->for($run2026Fast)->create([
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1180.0,
        'achieved_at' => CarbonImmutable::parse('2026-04-12 07:00:00'),
    ]);
    BestEffort::factory()->for($run2026Repeat)->excluded()->create([
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 900.0,
        'achieved_at' => CarbonImmutable::parse('2026-04-20 07:00:00'),
    ]);

    // 10K: 2025 was faster (2400s), so 2026 (2550s) is year-best but NOT all-time PR
    BestEffort::factory()->for($run2025)->create([
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2400.0,
        'achieved_at' => CarbonImmutable::parse('2025-05-10 07:00:00'),
    ]);
    BestEffort::factory()->for($run2026Repeat)->create([
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2550.0,
        'achieved_at' => CarbonImmutable::parse('2026-04-20 07:00:00'),
    ]);

    // Matched route visited twice in 2026
    $cluster = RouteCluster::factory()->create([
        'name' => 'Waterfront Loop',
        'sport_category' => 'running',
        'activity_count' => 3,
        'distance_meters' => 10200.0,
    ]);
    ActivityRouteFingerprint::factory()->for($run2026Fast)->for($cluster, 'cluster')->create(['is_unlinked' => false]);
    ActivityRouteFingerprint::factory()->for($run2026Repeat)->for($cluster, 'cluster')->create(['is_unlinked' => false]);

    // Segment PR in 2026 + non-PR in 2026 + PR in 2025
    $segment = Segment::factory()->climb('cat3')->create([
        'name' => 'Harbour Bridge Climb',
    ]);
    SegmentEffort::factory()->for($segment)->for($run2026Fast)->create([
        'started_at_local' => CarbonImmutable::parse('2026-04-12 07:15:00'),
        'elapsed_seconds' => 210.0,
        'is_pr' => true,
    ]);
    SegmentEffort::factory()->for($segment)->for($run2026Repeat)->create([
        'started_at_local' => CarbonImmutable::parse('2026-04-20 07:15:00'),
        'elapsed_seconds' => 235.0,
        'is_pr' => false,
    ]);
    SegmentEffort::factory()->for($segment)->for($run2025)->create([
        'started_at_local' => CarbonImmutable::parse('2025-05-10 07:15:00'),
        'elapsed_seconds' => 220.0,
        'is_pr' => true,
    ]);

    $payload = app(AnnualRecapService::class)->build(2026);
    $milestones = $payload['milestones'];

    expect($milestones['all_time_prs_count'])->toBe(1)
        ->and($milestones['best_efforts'])->toHaveCount(2)
        ->and($milestones['best_efforts'][0]['distance_key'])->toBe('5k')
        ->and($milestones['best_efforts'][0]['is_all_time_pr'])->toBeTrue()
        ->and($milestones['best_efforts'][0]['elapsed_seconds'])->toBe(1180.0)
        ->and($milestones['best_efforts'][1]['distance_key'])->toBe('10k')
        ->and($milestones['best_efforts'][1]['is_all_time_pr'])->toBeFalse()
        ->and($milestones['matched_routes_completed'])->toBe(1)
        ->and($milestones['most_repeated_route']['id'])->toBe($cluster->id)
        ->and($milestones['most_repeated_route']['visits_in_year'])->toBe(2)
        ->and($milestones['segment_prs_count'])->toBe(1)
        ->and($milestones['segment_efforts_count'])->toBe(2)
        ->and($milestones['segment_prs'])->toHaveCount(1)
        ->and($milestones['segment_prs'][0]['segment_name'])->toBe('Harbour Bridge Climb');
});

it('captures weather extremes and rainy session count for the selected year', function () {
    $frostRun = Activity::factory()->create([
        'name' => 'Frosty Dawn Run',
        'started_at_local' => CarbonImmutable::parse('2026-06-01 06:00:00'),
    ]);
    $heatRide = Activity::factory()->roadBiking()->create([
        'name' => 'Midday Scorcher',
        'started_at_local' => CarbonImmutable::parse('2026-01-25 13:00:00'),
    ]);
    $stormRun = Activity::factory()->create([
        'name' => 'Gale Force Squall',
        'started_at_local' => CarbonImmutable::parse('2026-05-05 17:00:00'),
    ]);

    ActivityWeather::factory()->for($frostRun)->create([
        'condition' => 'Clear',
        'temperature_c' => 1.2,
        'wind_speed_kmh' => 6.0,
        'precipitation_mm' => 0.0,
    ]);
    ActivityWeather::factory()->for($heatRide)->create([
        'condition' => 'Sunny',
        'temperature_c' => 31.8,
        'wind_speed_kmh' => 11.0,
        'precipitation_mm' => 0.0,
    ]);
    ActivityWeather::factory()->for($stormRun)->create([
        'condition' => 'Heavy Rain',
        'temperature_c' => 12.5,
        'wind_speed_kmh' => 42.6,
        'precipitation_mm' => 14.2,
    ]);

    $weather = app(AnnualRecapService::class)->build(2026)['weather'];

    expect($weather['sample_count'])->toBe(3)
        ->and($weather['rainy_sessions_count'])->toBe(1)
        ->and($weather['coldest_activity']['activity_id'])->toBe($frostRun->id)
        ->and($weather['coldest_activity']['temperature_c'])->toBe(1.2)
        ->and($weather['hottest_activity']['activity_id'])->toBe($heatRide->id)
        ->and($weather['hottest_activity']['temperature_c'])->toBe(31.8)
        ->and($weather['windiest_activity']['activity_id'])->toBe($stormRun->id)
        ->and($weather['windiest_activity']['wind_speed_kmh'])->toBe(42.6)
        ->and($weather['wettest_activity']['activity_id'])->toBe($stormRun->id)
        ->and($weather['wettest_activity']['precipitation_mm'])->toBe(14.2);
});

it('scopes annual health highlights strictly through HealthSourceRegistry primary providers', function () {
    // Primary provider: garmin_personal; secondary provider: apple_health (should be excluded when garmin_personal is configured)
    app(HealthSourceRegistry::class)->saveConfig([
        'sleep' => 'garmin_personal',
        'daily_metrics' => 'garmin_personal',
        'hrv' => 'garmin_personal',
        'vo2_max' => 'garmin_personal',
    ]);

    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-03-01',
        'sleep_seconds' => 28800, // 8.0h
        'sleep_score' => 88,
    ]);
    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-03-02',
        'sleep_seconds' => 25200, // 7.0h
        'sleep_score' => 80,
    ]);
    SleepRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-03-01',
        'sleep_seconds' => 14400, // 4.0h - should be excluded
        'sleep_score' => 40,
    ]);

    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-03-01',
        'resting_hr' => 48,
        'steps' => 12000,
    ]);
    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-03-02',
        'resting_hr' => 52,
        'steps' => 8000,
    ]);
    DailyMetric::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-03-01',
        'resting_hr' => 70,
        'steps' => 99999,
    ]);

    HrvRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-03-01',
        'last_night_avg' => 64,
    ]);
    HrvRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-03-01',
        'last_night_avg' => 20,
    ]);

    Vo2MaxRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-04-10',
        'sport' => 'running',
        'vo2_max_precise' => 57.4,
        'vo2_max' => 57.0,
    ]);
    Vo2MaxRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-04-11',
        'sport' => 'running',
        'vo2_max_precise' => 72.0,
        'vo2_max' => 72.0,
    ]);

    $health = app(AnnualRecapService::class)->build(2026)['health'];

    expect($health)->toBe([
        'has_data' => true,
        'avg_sleep_hours' => 7.5,
        'avg_sleep_score' => 84.0,
        'sleep_nights' => 2,
        'avg_resting_hr' => 50.0,
        'avg_hrv' => 64.0,
        'total_steps' => 20000,
        'avg_daily_steps' => 10000,
        'peak_vo2_max' => 57.4,
        'peak_vo2_max_sport' => 'running',
        'peak_vo2_max_date' => '2026-04-10',
    ]);
});
