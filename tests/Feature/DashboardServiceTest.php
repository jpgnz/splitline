<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Health\DashboardService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('builds a complete empty-state dashboard payload when no data exists', function () {
    $payload = app(DashboardService::class)->forDate();

    expect($payload['date'])->toBe('2026-11-21')
        ->and($payload['dateLabel'])->toBe('Sat, 21 Nov 2026')
        ->and($payload['prevDate'])->toBe('2026-11-20')
        ->and($payload['nextDate'])->toBeNull()
        ->and($payload['isToday'])->toBeTrue()
        ->and($payload['todayDate'])->toBe('2026-11-21')
        ->and($payload['latestActivity'])->toBeNull()
        ->and($payload['bodyBattery'])->toBe([
            'current' => null,
            'high' => null,
            'low' => null,
            'charged' => null,
            'drained' => null,
            'sleep' => null,
            'dayStartMs' => 1795172400000,
            'dayEndMs' => 1795258800000,
            'wakeTimestampMs' => null,
            'wakeTimeLabel' => null,
            'series' => [],
        ])
        ->and($payload['sleep']['quality'])->toBeNull()
        ->and($payload['sleep']['trend7d'])->toBe([])
        ->and($payload['hrv']['trend28d'])->toBe([])
        ->and($payload['trainingReadiness']['score'])->toBeNull()
        ->and($payload['weeklyActivity']['days'])->toHaveCount(7)
        ->and($payload['weeklyActivity']['last4w'])->toHaveCount(28)
        ->and($payload['training7d']['activity_count'])->toBe(0)
        ->and($payload['training7d']['goals'])->toBe([])
        ->and($payload['atAGlance']['weight']['weightKg'])->toBeNull()
        ->and($payload['atAGlance']['weight']['goalKg'])->toBeNull()
        ->and($payload['atAGlance']['weight']['deltaLastKg'])->toBeNull()
        ->and($payload['atAGlance']['vo2Fitness']['runningVo2'])->toBeNull()
        ->and($payload['atAGlance']['vo2Fitness']['fitnessAgeDate'])->toBeNull()
        ->and($payload['atAGlance']['healthStatus']['status'])->toBeNull()
        ->and($payload['atAGlance']['hydration']['ml'])->toBeNull();
});

it('resolves date defaults, caps future dates at today, and navigates past dates', function () {
    DailyMetric::factory()->create(['calendar_date' => '2026-11-19', 'steps' => 9200]);

    $service = app(DashboardService::class);

    $defaultPayload = $service->forDate();
    expect($defaultPayload['date'])->toBe('2026-11-19')
        ->and($defaultPayload['dateLabel'])->toBe('Thu, 19 Nov 2026')
        ->and($defaultPayload['prevDate'])->toBe('2026-11-18')
        ->and($defaultPayload['nextDate'])->toBe('2026-11-20')
        ->and($defaultPayload['isToday'])->toBeFalse()
        ->and($defaultPayload['atAGlance']['steps']['count'])->toBe(9200);

    $futurePayload = $service->forDate('2026-12-05');
    expect($futurePayload['date'])->toBe('2026-11-21')
        ->and($futurePayload['nextDate'])->toBeNull()
        ->and($futurePayload['isToday'])->toBeTrue();

    $invalidPayload = $service->forDate('not-a-date');
    expect($invalidPayload['date'])->toBe('2026-11-19');
});

it('aggregates daily metrics, trends, weight delta, vo2/fitness age, latest activity, and 7d training breakdown', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'steps' => 11842,
        'step_goal' => 10000,
        'distance_meters' => 9400.0,
        'total_calories' => 2640,
        'active_calories' => 840,
        'bmr_calories' => 1800,
        'resting_hr' => 47,
        'seven_day_avg_rhr' => 48,
        'min_hr' => 44,
        'max_hr' => 174,
        'avg_stress' => 24,
        'max_stress' => 76,
        'stress_qualifier' => 'calm',
        'rest_stress_seconds' => 30000,
        'low_stress_seconds' => 10000,
        'medium_stress_seconds' => 6000,
        'high_stress_seconds' => 4000,
        'body_battery_current' => 74,
        'body_battery_high' => 96,
        'body_battery_low' => 31,
        'body_battery_charged' => 65,
        'body_battery_drained' => 22,
        'body_battery_sleep' => 58,
        'moderate_intensity_minutes' => 30,
        'vigorous_intensity_minutes' => 25,
        'intensity_minutes_goal' => 150,
        'floors_ascended' => 14.5,
        'floors_descended' => 12.0,
        'floors_goal' => 10,
        'avg_respiration' => 14.2,
        'min_respiration' => 11.5,
        'max_respiration' => 20.0,
        'avg_spo2' => 98.1,
        'lowest_spo2' => 94.0,
        'latest_spo2' => 99.0,
        'hydration_ml' => 2450,
        'heart_rate_series' => [[1763683200000, 48], [1763686800000, 152]],
        'body_battery_series' => [[1763683200000, 96], [1763686800000, 74]],
    ]);

    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 25200,
        'deep_seconds' => 3600,
        'light_seconds' => 14400,
        'rem_seconds' => 7200,
        'sleep_score' => 78,
    ]);

    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'sleep_seconds' => 28800,
        'deep_seconds' => 5400,
        'light_seconds' => 16200,
        'rem_seconds' => 7200,
        'awake_seconds' => 900,
        'nap_seconds' => 1200,
        'sleep_score' => 86,
        'sleep_need_minutes' => 480,
        'next_sleep_need_minutes' => 470,
        'sleep_start_local' => CarbonImmutable::parse('2026-11-20 22:30:00'),
        'sleep_end_local' => CarbonImmutable::parse('2026-11-21 06:30:00'),
        'raw_payload' => [
            'sleepScores' => [
                'overall' => ['value' => 86, 'qualifierKey' => 'GOOD'],
            ],
        ],
    ]);

    // Outside 7-day sleep window (should be excluded)
    SleepRecord::factory()->create(['calendar_date' => '2026-11-10', 'sleep_seconds' => 21600]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'last_night_avg' => 60,
        'weekly_avg' => 58,
    ]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 64,
        'weekly_avg' => 59,
        'last_night_5min_high' => 94,
        'status' => 'BALANCED',
        'baseline_low_upper' => 46,
        'baseline_balanced_low' => 52,
        'baseline_balanced_upper' => 68,
        'feedback_phrase' => 'HRV_BALANCED_7',
    ]);

    // Outside 28-day HRV window (should be excluded)
    HrvRecord::factory()->create(['calendar_date' => '2026-10-01', 'last_night_avg' => 49]);

    WeightGoal::factory()->create([
        'start_date' => '2026-09-04',
        'end_date' => null,
        'goal_grams' => 74000,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-10-20',
        'weight_grams' => 76800,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-10-22',
        'weight_grams' => 76200,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-15',
        'weight_grams' => 74900,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-20',
        'weight_grams' => 75400,
        'bmi' => 22.8,
        'body_fat_pct' => 14.5,
        'body_water_pct' => 61.2,
        'muscle_mass_grams' => 38500,
        'bone_mass_grams' => 3350,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-18',
        'sport' => 'running',
        'vo2_max_precise' => 58.4,
        'vo2_max' => 58.0,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-15',
        'sport' => 'cycling',
        'vo2_max_precise' => null,
        'vo2_max' => 61.0,
    ]);

    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'chronological_age' => 36.0,
        'fitness_age' => 30.5,
    ]);

    // Activities in the 7-day window (2026-11-15..2026-11-21)
    $todayRun = Activity::factory()->create([
        'name' => 'Port Hills Loop',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 06:42:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 2700.0,
        'avg_speed' => 3.704,
        'avg_hr' => 154,
        'training_load' => 110.5,
    ]);

    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 50000.0,
        'duration_seconds' => 5400.0,
        'training_load' => 140.0,
    ]);

    Activity::factory()->otherBiking()->create([
        'discipline_override' => 'mtb',
        'started_at_local' => CarbonImmutable::parse('2026-11-17 16:30:00'),
        'distance_meters' => 24000.0,
        'elevation_gain' => 780.5,
        'duration_seconds' => 4800.0,
        'training_load' => 125.0,
    ]);

    Activity::factory()->create([
        'sport_category' => 'cardio',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 18:00:00'),
        'distance_meters' => 0.0,
        'duration_seconds' => 1800.0,
        'training_load' => 35.0,
    ]);

    // Outside 7-day window (should be excluded from training7d)
    Activity::factory()->create([
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-14 07:00:00'),
        'distance_meters' => 15000.0,
        'duration_seconds' => 4200.0,
        'training_load' => 150.0,
    ]);

    $payload = app(DashboardService::class)->forDate('2026-11-21');

    expect($payload['latestActivity'])->toBe([
        'id' => $todayRun->id,
        'name' => 'Port Hills Loop',
        'sport_category' => 'running',
        'effective_discipline' => 'road',
        'distance_meters' => 10000.0,
        'duration_seconds' => 2700.0,
        'avg_speed' => 3.704,
        'avg_hr' => 154,
        'started_at_local' => '2026-11-21 06:42:00',
        'is_today' => true,
    ]);

    expect($payload['bodyBattery'])->toBe([
        'current' => 74,
        'high' => 96,
        'low' => 31,
        'charged' => 65,
        'drained' => 22,
        'sleep' => 58,
        'dayStartMs' => 1795172400000,
        'dayEndMs' => 1795258800000,
        'wakeTimestampMs' => 1795195800000,
        'wakeTimeLabel' => '06:30',
        'series' => [[1763683200000, 96], [1763686800000, 74]],
    ]);

    expect($payload['sleep']['totalSeconds'])->toBe(28800)
        ->and($payload['sleep']['quality'])->toBe('GOOD')
        ->and($payload['sleep']['startLocal'])->toBe('2026-11-20 22:30:00')
        ->and($payload['sleep']['endLocal'])->toBe('2026-11-21 06:30:00')
        ->and($payload['sleep']['trend7d'])->toHaveCount(2)
        ->and($payload['sleep']['trend7d'][1])->toBe([
            'date' => '2026-11-21',
            'totalHours' => 8.0,
            'deepHours' => 1.5,
            'lightHours' => 4.5,
            'remHours' => 2.0,
            'score' => 86,
        ]);

    expect($payload['hrv']['lastNightAvg'])->toBe(64)
        ->and($payload['hrv']['baselineLow'])->toBe(46)
        ->and($payload['hrv']['baselineBalancedLow'])->toBe(52)
        ->and($payload['hrv']['baselineBalancedUpper'])->toBe(68)
        ->and($payload['hrv']['trend28d'])->toHaveCount(2);

    expect($payload['training7d'])->toMatchArray([
        'total_load' => 410.5,
        'total_duration_seconds' => 14700.0,
        'total_distance_meters' => 84000.0,
        'activity_count' => 4,
        'by_sport' => [
            'running' => [
                'count' => 1,
                'distance_meters' => 10000.0,
                'duration_seconds' => 2700.0,
                'load' => 110.5,
            ],
            'road' => [
                'count' => 1,
                'distance_meters' => 50000.0,
                'duration_seconds' => 5400.0,
                'load' => 140.0,
            ],
            'mtb' => [
                'count' => 1,
                'distance_meters' => 24000.0,
                'elevation_gain' => 780.5,
                'duration_seconds' => 4800.0,
                'load' => 125.0,
            ],
            'other' => [
                'count' => 1,
                'distance_meters' => 0.0,
                'duration_seconds' => 1800.0,
                'load' => 35.0,
            ],
        ],
        'goals' => [],
    ])
        ->and(array_column($payload['training7d']['by_sport_list'], 'key'))->toBe([
            'running',
            'road_cycling',
            'mtb_cycling',
            'other',
        ]);

    expect($payload['atAGlance']['stress']['restPct'])->toBe(60)
        ->and($payload['atAGlance']['intensityMinutes']['total'])->toBe(80)
        ->and($payload['atAGlance']['weight'])->toBe([
            'weightKg' => 75.4,
            'goalKg' => 74.0,
            'deltaLastKg' => 0.5,
            'delta30dKg' => -0.8,
            'bmi' => 22.8,
            'bodyFatPct' => 14.5,
            'bodyWaterPct' => 61.2,
            'muscleMassKg' => 38.5,
            'boneMassKg' => 3.35,
            'measuredDate' => '2026-11-20',
        ])
        ->and($payload['atAGlance']['vo2Fitness'])->toBe([
            'runningVo2' => 58.4,
            'cyclingVo2' => 61.0,
            'fitnessAge' => 30.5,
            'chronologicalAge' => 36.0,
            'delta' => -5.5,
            'fitnessAgeDate' => '2026-11-21',
        ])
        ->and($payload['atAGlance']['healthStatus']['status'])->toBe('In Range')
        ->and($payload['trainingReadiness']['score'])->toBeInt()
        ->and($payload['weeklyActivity']['weekLabel'])->toBe('Nov 16–22')
        ->and($payload['weeklyActivity']['totalDurationSeconds'])->toBe(12900.0)
        ->and($payload['atAGlance']['hydration']['ml'])->toBe(2450);
});

it('falls back to the most recent prior activity when no activity occurred on the selected date', function () {
    $priorActivity = Activity::factory()->roadBiking()->create([
        'name' => 'Yesterday Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:30:00'),
    ]);

    $payload = app(DashboardService::class)->forDate('2026-11-21');

    expect($payload['latestActivity'])->not->toBeNull()
        ->and($payload['latestActivity']['id'])->toBe($priorActivity->id)
        ->and($payload['latestActivity']['effective_discipline'])->toBe('road')
        ->and($payload['latestActivity']['is_today'])->toBeFalse();
});

it('normalizes dashboard calories on completed travel days without scaling in-progress today', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-15',
        'day_duration_seconds' => 162000,
        'bmr_calories' => 4153,
        'active_calories' => 847,
        'total_calories' => 5000,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'day_duration_seconds' => 75600,
        'bmr_calories' => 1936,
        'active_calories' => 652,
        'total_calories' => 2588,
    ]);

    $service = app(DashboardService::class);

    $travelPayload = $service->forDate('2026-11-15');
    expect($travelPayload['atAGlance']['calories'])->toBe([
        'total' => 2667,
        'active' => 452,
        'bmr' => 2215,
        'rawTotal' => 5000,
        'rawActive' => 847,
        'rawBmr' => 4153,
        'dayNote' => '45h day → 24h',
    ]);

    $todayPayload = $service->forDate('2026-11-21');
    expect($todayPayload['atAGlance']['calories'])->toBe([
        'total' => 2588,
        'active' => 652,
        'bmr' => 1936,
        'rawTotal' => null,
        'rawActive' => null,
        'rawBmr' => null,
        'dayNote' => null,
    ]);
});

it('aggregates week-to-date Mon-Sun intensity minutes on the dashboard against the weekly goal', function () {
    // Prior week Sunday (2026-11-15) — must be excluded from week starting Mon 2026-11-16
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-15',
        'moderate_intensity_minutes' => 10,
        'vigorous_intensity_minutes' => 40,
        'intensity_minutes_goal' => 600,
    ]);

    // Current week Mon–Sat
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-16',
        'moderate_intensity_minutes' => 3,
        'vigorous_intensity_minutes' => 25,
        'intensity_minutes_goal' => 300,
    ]);
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-18',
        'moderate_intensity_minutes' => 3,
        'vigorous_intensity_minutes' => 64,
        'intensity_minutes_goal' => 300,
    ]);
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'moderate_intensity_minutes' => 0,
        'vigorous_intensity_minutes' => 0,
        'intensity_minutes_goal' => null,
    ]);

    $service = app(DashboardService::class);

    // Viewing Sat 2026-11-21: sums Mon 16 + Wed 18 + Sat 21 = Mod 6, Vig 89 -> 184 total, goal fallback 300
    $satPayload = $service->forDate('2026-11-21');
    expect($satPayload['atAGlance']['intensityMinutes'])->toBe([
        'moderate' => 6,
        'vigorous' => 89,
        'total' => 184,
        'goal' => 300,
        'dailyTotal' => 0,
        'weekStart' => '2026-11-16',
    ]);

    // Viewing Mon 2026-11-16: only includes Monday (excludes Wed 18 & Sat 21)
    $monPayload = $service->forDate('2026-11-16');
    expect($monPayload['atAGlance']['intensityMinutes'])->toBe([
        'moderate' => 3,
        'vigorous' => 25,
        'total' => 53,
        'goal' => 300,
        'dailyTotal' => 53,
        'weekStart' => '2026-11-16',
    ]);
});

it('omits UNKNOWN stress qualifier on the dashboard payload', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'avg_stress' => 17,
        'stress_qualifier' => 'UNKNOWN',
    ]);

    $payload = app(DashboardService::class)->forDate('2026-11-21');

    expect($payload['atAGlance']['stress']['avg'])->toBe(17)
        ->and($payload['atAGlance']['stress']['qualifier'])->toBeNull();
});

it('selects the highest-calorie activity on the most recent active day over a later cooldown walk', function () {
    $run = Activity::factory()->create([
        'name' => 'Christchurch - Base',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 16:15:55'),
        'duration_seconds' => 2559.0,
        'calories' => 612,
    ]);

    Activity::factory()->create([
        'name' => 'Christchurch Walking',
        'sport_category' => 'walking',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 16:59:02'),
        'duration_seconds' => 108.0,
        'calories' => 14,
    ]);

    $service = app(DashboardService::class);

    // On the activity day itself: picks the run over the later cooldown walk
    $sameDay = $service->forDate('2026-11-20');
    expect($sameDay['latestActivity']['id'])->toBe($run->id)
        ->and($sameDay['latestActivity']['name'])->toBe('Christchurch - Base')
        ->and($sameDay['latestActivity']['is_today'])->toBeTrue();

    // On the next day with no activities: still picks the highest-calorie activity from the latest active day
    $nextDay = $service->forDate('2026-11-21');
    expect($nextDay['latestActivity']['id'])->toBe($run->id)
        ->and($nextDay['latestActivity']['name'])->toBe('Christchurch - Base')
        ->and($nextDay['latestActivity']['is_today'])->toBeFalse();
});
