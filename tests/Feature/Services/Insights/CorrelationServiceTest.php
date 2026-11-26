<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityWeather;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Services\Insights\CorrelationService;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

it('returns a clean empty state when no activities exist in the window', function () {
    $payload = app(CorrelationService::class)->build();

    expect($payload['has_data'])->toBeFalse()
        ->and($payload['filters'])->toBe([
            'sport' => 'running',
            'range' => '1y',
        ])
        ->and($payload['dateWindow']['range'])->toBe('1y')
        ->and($payload['today'])->toBe('2026-11-26')
        ->and($payload['summary'])->toBe([
            'total_activities' => 0,
            'with_derived_metrics' => 0,
            'with_sleep' => 0,
            'with_hrv' => 0,
            'with_weather' => 0,
            'best_sleep_bucket' => null,
            'optimal_temp_bucket' => null,
            'peak_time_of_day' => null,
        ])
        ->and($payload['sleep_vs_performance']['n'])->toBe(0)
        ->and($payload['sleep_vs_performance']['scatter'])->toBe([])
        ->and($payload['sleep_vs_performance']['takeaway'])->toBeNull()
        ->and($payload['hrv_vs_performance']['n'])->toBe(0)
        ->and($payload['hrv_vs_performance']['takeaway'])->toBeNull()
        ->and($payload['weather_impact']['n'])->toBe(0)
        ->and($payload['weather_impact']['takeaway'])->toBeNull()
        ->and($payload['workout_to_recovery']['n'])->toBe(0)
        ->and($payload['workout_to_recovery']['takeaway'])->toBeNull()
        ->and($payload['schedule_patterns']['n'])->toBe(0)
        ->and($payload['schedule_patterns']['by_day_of_week'])->toHaveCount(7)
        ->and($payload['schedule_patterns']['by_time_of_day'])->toHaveCount(5);
});

it('computes Pearson r accurately and returns null when sample size is below 5 or variance is zero', function () {
    $service = app(CorrelationService::class);

    expect($service->pearsonR([1.0, 2.0, 3.0, 4.0], [2.0, 4.0, 6.0, 8.0]))->toBeNull()
        ->and($service->pearsonR([5.0, 5.0, 5.0, 5.0, 5.0], [1.0, 2.0, 3.0, 4.0, 5.0]))->toBeNull()
        ->and($service->pearsonR([1.0, 2.0, 3.0, 4.0, 5.0], [2.0, 4.0, 6.0, 8.0, 10.0]))->toBe(1.0)
        ->and($service->pearsonR([1.0, 2.0, 3.0, 4.0, 5.0], [10.0, 8.0, 6.0, 4.0, 2.0]))->toBe(-1.0)
        ->and($service->pearsonR([1.0, 2.0, 3.0, 4.0, 5.0], [2.0, 3.0, 5.0, 4.0, 6.0]))->toBe(0.9);
});

it('buckets sleep duration and score against same-day performance and computes correlations', function () {
    $samples = [
        ['date' => '2026-11-10', 'hours' => 5.5, 'score' => 52, 'ef' => 1.32, 'dec' => 7.2, 'hr' => 162],
        ['date' => '2026-11-11', 'hours' => 6.0, 'score' => 58, 'ef' => 1.36, 'dec' => 6.4, 'hr' => 160],
        ['date' => '2026-11-12', 'hours' => 7.0, 'score' => 70, 'ef' => 1.48, 'dec' => 4.2, 'hr' => 154],
        ['date' => '2026-11-13', 'hours' => 7.8, 'score' => 80, 'ef' => 1.60, 'dec' => 2.5, 'hr' => 149],
        ['date' => '2026-11-14', 'hours' => 8.2, 'score' => 88, 'ef' => 1.66, 'dec' => 1.8, 'hr' => 146],
        ['date' => '2026-11-15', 'hours' => 8.8, 'score' => 92, 'ef' => 1.70, 'dec' => 1.4, 'hr' => 144],
    ];

    foreach ($samples as $sample) {
        $activity = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($sample['date'].' 07:30:00'),
            'avg_hr' => $sample['hr'],
        ]);

        ActivityDerivedMetric::factory()->for($activity)->create([
            'efficiency_factor' => $sample['ef'],
            'decoupling_pct' => $sample['dec'],
        ]);

        SleepRecord::factory()->create([
            'calendar_date' => $sample['date'],
            'sleep_seconds' => (int) round($sample['hours'] * 3600),
            'sleep_score' => $sample['score'],
        ]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $sleep = $payload['sleep_vs_performance'];
    $durationByKey = collect($sleep['duration_buckets'])->keyBy('key');
    $scoreByKey = collect($sleep['score_buckets'])->keyBy('key');

    expect($sleep['n'])->toBe(6)
        ->and($sleep['scatter'])->toHaveCount(6)
        ->and($durationByKey['lt_6_5']['count'])->toBe(2)
        ->and($durationByKey['lt_6_5']['avg_ef'])->toBe(1.34)
        ->and($durationByKey['6_5_to_7_5']['count'])->toBe(1)
        ->and($durationByKey['7_5_to_8_5']['count'])->toBe(2)
        ->and($durationByKey['7_5_to_8_5']['avg_ef'])->toBe(1.63)
        ->and($durationByKey['gte_8_5']['count'])->toBe(1)
        ->and($scoreByKey['poor']['count'])->toBe(2)
        ->and($scoreByKey['fair']['count'])->toBe(1)
        ->and($scoreByKey['good']['count'])->toBe(1)
        ->and($scoreByKey['excellent']['count'])->toBe(2)
        ->and($sleep['correlations']['score_vs_ef']['r'])->toBeGreaterThan(0.95)
        ->and($sleep['correlations']['hours_vs_decoupling']['r'])->toBeLessThan(-0.95)
        ->and($sleep['takeaway'])->not->toBeNull()
        ->and($payload['summary']['best_sleep_bucket']['key'])->toBe('7_5_to_8_5');
});

it('buckets HRV status and 28-day baseline balance with resting HR against performance', function () {
    $samples = [
        ['date' => '2026-11-10', 'hrv' => 42, 'status' => 'LOW', 'rhr' => 58, 'ef' => 1.35, 'dec' => 6.8],
        ['date' => '2026-11-11', 'hrv' => 45, 'status' => 'LOW', 'rhr' => 56, 'ef' => 1.39, 'dec' => 6.0],
        ['date' => '2026-11-12', 'hrv' => 56, 'status' => 'BALANCED', 'rhr' => 51, 'ef' => 1.54, 'dec' => 3.5],
        ['date' => '2026-11-13', 'hrv' => 62, 'status' => 'BALANCED', 'rhr' => 49, 'ef' => 1.58, 'dec' => 2.8],
        ['date' => '2026-11-14', 'hrv' => 76, 'status' => 'BALANCED', 'rhr' => 47, 'ef' => 1.68, 'dec' => 1.9],
        ['date' => '2026-11-15', 'hrv' => 78, 'status' => 'UNBALANCED', 'rhr' => 46, 'ef' => 1.70, 'dec' => 1.5],
    ];

    foreach ($samples as $sample) {
        $activity = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($sample['date'].' 08:00:00'),
        ]);

        ActivityDerivedMetric::factory()->for($activity)->create([
            'efficiency_factor' => $sample['ef'],
            'decoupling_pct' => $sample['dec'],
        ]);

        HrvRecord::factory()->create([
            'calendar_date' => $sample['date'],
            'last_night_avg' => $sample['hrv'],
            'status' => $sample['status'],
            'baseline_balanced_low' => 50,
            'baseline_balanced_upper' => 70,
        ]);

        DailyMetric::factory()->create([
            'calendar_date' => $sample['date'],
            'resting_hr' => $sample['rhr'],
            'body_battery_high' => 92,
        ]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $hrv = $payload['hrv_vs_performance'];
    $statusByKey = collect($hrv['status_buckets'])->keyBy('key');
    $baselineByKey = collect($hrv['baseline_buckets'])->keyBy('key');

    expect($hrv['n'])->toBe(6)
        ->and($statusByKey['low']['count'])->toBe(2)
        ->and($statusByKey['low']['avg_ef'])->toBe(1.37)
        ->and($statusByKey['balanced']['count'])->toBe(3)
        ->and($statusByKey['unbalanced']['count'])->toBe(1)
        ->and($baselineByKey['below']['count'])->toBe(2)
        ->and($baselineByKey['in_baseline']['count'])->toBe(2)
        ->and($baselineByKey['above']['count'])->toBe(2)
        ->and($baselineByKey['above']['avg_ef'])->toBe(1.69)
        ->and($hrv['correlations']['hrv_vs_ef']['r'])->toBeGreaterThan(0.95)
        ->and($hrv['correlations']['rhr_vs_ef']['r'])->toBeLessThan(-0.95)
        ->and($hrv['takeaway'])->not->toBeNull();
});

it('buckets ambient temperature and wind exposure and identifies optimal temperature band', function () {
    $samples = [
        ['date' => '2026-11-10', 'temp' => 7.5, 'wind' => 4.2, 'hw' => 15.0, 'tw' => 65.0, 'ef' => 1.56, 'dec' => 2.5, 'speed' => 3.55],
        ['date' => '2026-11-11', 'temp' => 12.0, 'wind' => 3.1, 'hw' => 20.0, 'tw' => 55.0, 'ef' => 1.66, 'dec' => 1.8, 'speed' => 3.70],
        ['date' => '2026-11-12', 'temp' => 13.5, 'wind' => 0.2, 'hw' => 30.0, 'tw' => 30.0, 'ef' => 1.64, 'dec' => 2.0, 'speed' => 3.65],
        ['date' => '2026-11-13', 'temp' => 18.0, 'wind' => -0.4, 'hw' => 35.0, 'tw' => 30.0, 'ef' => 1.52, 'dec' => 3.8, 'speed' => 3.45],
        ['date' => '2026-11-14', 'temp' => 23.0, 'wind' => -4.5, 'hw' => 60.0, 'tw' => 15.0, 'ef' => 1.42, 'dec' => 5.6, 'speed' => 3.25],
        ['date' => '2026-11-15', 'temp' => 27.5, 'wind' => -5.8, 'hw' => 70.0, 'tw' => 10.0, 'ef' => 1.34, 'dec' => 7.4, 'speed' => 3.10],
    ];

    foreach ($samples as $sample) {
        $activity = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($sample['date'].' 08:00:00'),
            'avg_speed' => $sample['speed'],
        ]);

        ActivityDerivedMetric::factory()->for($activity)->create([
            'efficiency_factor' => $sample['ef'],
            'decoupling_pct' => $sample['dec'],
        ]);

        ActivityWeather::factory()->for($activity)->create([
            'temperature_c' => $sample['temp'],
            'dew_point_c' => $sample['temp'] - 5.0,
            'avg_effective_wind_kmh' => $sample['wind'],
            'headwind_pct' => $sample['hw'],
            'tailwind_pct' => $sample['tw'],
        ]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $weather = $payload['weather_impact'];
    $tempByKey = collect($weather['temperature_buckets'])->keyBy('key');
    $windByKey = collect($weather['wind_buckets'])->keyBy('key');

    expect($weather['n'])->toBe(6)
        ->and($weather['wind_n'])->toBe(6)
        ->and($tempByKey['lt_10']['count'])->toBe(1)
        ->and($tempByKey['10_to_15']['count'])->toBe(2)
        ->and($tempByKey['10_to_15']['avg_ef'])->toBe(1.65)
        ->and($tempByKey['15_to_20']['count'])->toBe(1)
        ->and($tempByKey['20_to_25']['count'])->toBe(1)
        ->and($tempByKey['gte_25']['count'])->toBe(1)
        ->and($windByKey['tailwind']['count'])->toBe(2)
        ->and($windByKey['balanced']['count'])->toBe(2)
        ->and($windByKey['headwind']['count'])->toBe(2)
        ->and($weather['correlations']['temp_vs_ef']['r'])->toBeLessThan(-0.8)
        ->and($weather['correlations']['temp_vs_decoupling']['r'])->toBeGreaterThan(0.8)
        ->and($payload['summary']['optimal_temp_bucket']['key'])->toBe('10_to_15')
        ->and($weather['takeaway'])->not->toBeNull();
});

it('matches workouts against the following night (date + 1) sleep and HRV recovery', function () {
    // Same-day morning records (2026-11-10) should NOT be used for 2026-11-10 workout's overnight recovery!
    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-10',
        'sleep_score' => 99,
        'deep_seconds' => 7200,
    ]);
    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-10',
        'last_night_avg' => 95,
        'weekly_avg' => 65,
    ]);

    // 2 Morning Hard workouts + 3 Evening Hard workouts, each followed by date + 1 recovery records
    $workouts = [
        ['date' => '2026-11-10', 'time' => '07:00:00', 'load' => 130.0, 'next_sleep' => 86, 'next_deep' => 5400, 'next_hrv' => 70, 'weekly' => 65],
        ['date' => '2026-11-12', 'time' => '08:30:00', 'load' => 125.0, 'next_sleep' => 84, 'next_deep' => 5100, 'next_hrv' => 68, 'weekly' => 65],
        ['date' => '2026-11-14', 'time' => '18:30:00', 'load' => 135.0, 'next_sleep' => 68, 'next_deep' => 3000, 'next_hrv' => 56, 'weekly' => 65],
        ['date' => '2026-11-16', 'time' => '19:15:00', 'load' => 140.0, 'next_sleep' => 64, 'next_deep' => 2700, 'next_hrv' => 54, 'weekly' => 65],
        ['date' => '2026-11-18', 'time' => '13:00:00', 'load' => 42.0, 'next_sleep' => 80, 'next_deep' => 4500, 'next_hrv' => 66, 'weekly' => 65],
    ];

    foreach ($workouts as $workout) {
        $activity = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($workout['date'].' '.$workout['time']),
            'training_load' => $workout['load'],
            'tss' => null,
        ]);

        ActivityDerivedMetric::factory()->for($activity)->create([
            'hr_high_pct' => $workout['load'] >= 100 ? 25.0 : 3.0,
        ]);

        $nextDate = CarbonImmutable::parse($workout['date'])->addDay()->format('Y-m-d');

        SleepRecord::factory()->create([
            'calendar_date' => $nextDate,
            'sleep_score' => $workout['next_sleep'],
            'deep_seconds' => $workout['next_deep'],
        ]);

        HrvRecord::factory()->create([
            'calendar_date' => $nextDate,
            'last_night_avg' => $workout['next_hrv'],
            'weekly_avg' => $workout['weekly'],
        ]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $recovery = $payload['workout_to_recovery'];
    $byTime = collect($recovery['by_time_of_day'])->keyBy('key');
    $byIntensity = collect($recovery['by_intensity'])->keyBy('key');

    expect($recovery['n'])->toBe(5)
        ->and($byTime['morning']['count'])->toBe(2)
        ->and($byTime['morning']['avg_sleep_score'])->toBe(85.0)
        ->and($byTime['morning']['avg_hrv_delta'])->toBe(4.0)
        ->and($byTime['afternoon']['count'])->toBe(1)
        ->and($byTime['afternoon']['avg_hrv_delta'])->toBe(1.0)
        ->and($byTime['evening']['count'])->toBe(2)
        ->and($byTime['evening']['avg_sleep_score'])->toBe(66.0)
        ->and($byTime['evening']['avg_hrv_delta'])->toBe(-10.0)
        ->and($byIntensity['easy']['count'])->toBe(1)
        ->and($byIntensity['hard']['count'])->toBe(4)
        ->and($recovery['correlations']['start_hour_vs_hrv_delta']['r'])->toBeLessThan(-0.9)
        ->and($recovery['takeaway'])->toContain('Hard evening sessions');
});

it('isolates health records strictly through HealthSourceRegistry primary provider scoping', function () {
    $activity = Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:30:00'),
    ]);

    ActivityDerivedMetric::factory()->for($activity)->create([
        'efficiency_factor' => 1.55,
        'decoupling_pct' => 2.2,
    ]);

    // Primary provider: garmin_personal
    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 28800, // 8.0h
        'sleep_score' => 88,
    ]);
    HrvRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-20',
        'last_night_avg' => 72,
        'status' => 'BALANCED',
    ]);
    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-20',
        'resting_hr' => 48,
    ]);

    // Secondary provider: apple_health with completely different values on the same date
    SleepRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 18000, // 5.0h
        'sleep_score' => 42,
    ]);
    HrvRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-20',
        'last_night_avg' => 35,
        'status' => 'POOR',
    ]);
    DailyMetric::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-20',
        'resting_hr' => 66,
    ]);

    $registry = app(HealthSourceRegistry::class);
    $registry->saveConfig([
        'sleep' => 'garmin_personal',
        'hrv' => 'garmin_personal',
        'daily_metrics' => 'garmin_personal',
    ]);

    $garminPayload = app(CorrelationService::class)->build(sport: 'running', range: '90d');

    expect($garminPayload['sleep_vs_performance']['scatter'][0]['sleep_score'])->toBe(88)
        ->and($garminPayload['sleep_vs_performance']['scatter'][0]['sleep_hours'])->toBe(8.0)
        ->and($garminPayload['hrv_vs_performance']['scatter'][0]['hrv'])->toBe(72)
        ->and($garminPayload['hrv_vs_performance']['scatter'][0]['rhr'])->toBe(48);

    // Switch primary sources to apple_health and verify readings flip cleanly
    $registry->saveConfig([
        'sleep' => 'apple_health',
        'hrv' => 'apple_health',
        'daily_metrics' => 'apple_health',
    ]);

    $applePayload = app(CorrelationService::class)->build(sport: 'running', range: '90d');

    expect($applePayload['sleep_vs_performance']['scatter'][0]['sleep_score'])->toBe(42)
        ->and($applePayload['sleep_vs_performance']['scatter'][0]['sleep_hours'])->toBe(5.0)
        ->and($applePayload['hrv_vs_performance']['scatter'][0]['hrv'])->toBe(35)
        ->and($applePayload['hrv_vs_performance']['scatter'][0]['rhr'])->toBe(66);
});

it('aggregates day-of-week and time-of-day patterns and respects sport filtering', function () {
    // 2026-11-16 is Monday, 2026-11-21 is Saturday
    $runs = [
        ['datetime' => '2026-11-16 06:15:00', 'distance' => 8000.0, 'ef' => 1.50],  // Mon Early
        ['datetime' => '2026-11-18 08:30:00', 'distance' => 10000.0, 'ef' => 1.68], // Wed Morning
        ['datetime' => '2026-11-20 09:00:00', 'distance' => 12000.0, 'ef' => 1.72], // Fri Morning
        ['datetime' => '2026-11-21 12:30:00', 'distance' => 15000.0, 'ef' => 1.54], // Sat Midday
        ['datetime' => '2026-11-21 16:00:00', 'distance' => 6000.0, 'ef' => 1.48],  // Sat Afternoon
        ['datetime' => '2026-11-22 19:00:00', 'distance' => 9000.0, 'ef' => 1.40],  // Sun Evening
    ];

    foreach ($runs as $run) {
        $activity = Activity::factory()->create([
            'started_at_local' => CarbonImmutable::parse($run['datetime']),
            'distance_meters' => $run['distance'],
        ]);

        ActivityDerivedMetric::factory()->for($activity)->create([
            'efficiency_factor' => $run['ef'],
        ]);
    }

    // Cycling activity that must be excluded when filtering to running
    $ride = Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'distance_meters' => 50000.0,
    ]);
    ActivityDerivedMetric::factory()->for($ride)->create([
        'efficiency_factor' => 2.40,
    ]);

    $runPayload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $daysByKey = collect($runPayload['schedule_patterns']['by_day_of_week'])->keyBy('key');
    $timesByKey = collect($runPayload['schedule_patterns']['by_time_of_day'])->keyBy('key');

    expect($runPayload['summary']['total_activities'])->toBe(6)
        ->and($daysByKey['mon']['count'])->toBe(1)
        ->and($daysByKey['sat']['count'])->toBe(2)
        ->and($daysByKey['sat']['total_distance_meters'])->toBe(21000.0)
        ->and($timesByKey['early']['count'])->toBe(1)
        ->and($timesByKey['morning']['count'])->toBe(2)
        ->and($timesByKey['morning']['avg_ef'])->toBe(1.7)
        ->and($timesByKey['midday']['count'])->toBe(1)
        ->and($timesByKey['afternoon']['count'])->toBe(1)
        ->and($timesByKey['evening']['count'])->toBe(1)
        ->and($runPayload['summary']['peak_time_of_day']['key'])->toBe('morning');

    $allPayload = app(CorrelationService::class)->build(sport: 'all', range: '90d');

    expect($allPayload['summary']['total_activities'])->toBe(7);
});
