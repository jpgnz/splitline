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

/**
 * @param  array<string, mixed>  $activity
 * @param  array<string, mixed>|null  $derived
 */
function insightsCreateSession(string $startedAtLocal, array $activity = [], ?array $derived = []): Activity
{
    $session = Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse($startedAtLocal),
        ...$activity,
    ]);

    if ($derived !== null) {
        ActivityDerivedMetric::factory()->for($session)->create($derived);
    }

    return $session;
}

/**
 * HR zone durations (1,000 s total) whose Z4–Z5 share equals `$highPct` percent.
 *
 * @return array<string, float>
 */
function insightsHrZones(float $highPct): array
{
    return [
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 1000.0 - ($highPct * 10.0),
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => $highPct * 10.0,
        'hr_zone_5_seconds' => 0.0,
    ];
}

/**
 * @param  array<string, int>  $valuesByDate
 */
function insightsCreateHrvNights(array $valuesByDate): void
{
    foreach ($valuesByDate as $date => $value) {
        HrvRecord::factory()->create([
            'calendar_date' => $date,
            'last_night_avg' => $value,
        ]);
    }
}

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
            'training_days' => 0,
            'with_derived_metrics' => 0,
            'ef_metric' => null,
            'ef_excluded_sessions' => 0,
            'with_sleep' => 0,
            'with_hrv' => 0,
            'with_weather' => 0,
            'best_sleep_bucket' => null,
            'optimal_temp_bucket' => null,
            'peak_time_of_day' => null,
        ])
        ->and($payload['sleep_vs_performance']['n'])->toBe(0)
        ->and($payload['sleep_vs_performance']['scatter'])->toBe([])
        ->and($payload['sleep_vs_performance']['correlations']['score_vs_ef'])->toBe([
            'r' => null,
            'n' => 0,
            'p_value' => null,
            'significant' => false,
            'strength' => null,
        ])
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

it('reports significant sleep correlations and bucket spreads with p-values', function () {
    $hoursByDay = [5.5, 5.8, 6.1, 6.7, 7.0, 7.3, 7.7, 8.0, 8.3, 8.6, 8.9, 9.2];

    foreach ($hoursByDay as $index => $hours) {
        $date = CarbonImmutable::parse('2026-11-01')->addDays($index)->format('Y-m-d');

        insightsCreateSession($date.' 07:30:00', ['avg_hr' => 150], [
            'efficiency_factor' => round(0.9 + (0.08 * $hours), 3),
            'decoupling_pct' => round(12.0 - $hours, 2),
        ]);

        SleepRecord::factory()->create([
            'calendar_date' => $date,
            'sleep_seconds' => (int) round($hours * 3600),
            'sleep_score' => (int) round(40 + (6 * $hours)),
        ]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $sleep = $payload['sleep_vs_performance'];
    $durationByKey = collect($sleep['duration_buckets'])->keyBy('key');

    expect($sleep['n'])->toBe(12)
        ->and($sleep['scatter'])->toHaveCount(12)
        ->and($durationByKey->pluck('count')->all())->toBe([3, 3, 3, 3])
        ->and($durationByKey['lt_6_5']['avg_ef'])->toBe(1.364)
        ->and($sleep['correlations']['score_vs_ef']['r'])->toBeGreaterThan(0.95)
        ->and($sleep['correlations']['score_vs_ef']['n'])->toBe(12)
        ->and($sleep['correlations']['score_vs_ef']['p_value'])->toBeLessThan(0.001)
        ->and($sleep['correlations']['score_vs_ef']['significant'])->toBeTrue()
        ->and($sleep['correlations']['score_vs_ef']['strength'])->toBe('strong')
        ->and($sleep['correlations']['hours_vs_decoupling']['r'])->toBeLessThan(-0.95)
        ->and($sleep['takeaway'])->toStartWith('Days after ≥ 8.5h sleep average 1.61 EF vs 1.36 EF after < 6.5h (+18.2%')
        ->and($sleep['takeaway'])->toContain('p < 0.001, n = 12 days')
        ->and($payload['summary']['best_sleep_bucket']['key'])->toBe('gte_8_5');
});

it('withholds correlations below 10 paired days and labels non-significant ones inconclusive', function () {
    // Six perfectly correlated days: r is withheld because n < 10, and no bucket reaches 3 days.
    foreach ([5.5, 6.0, 7.0, 7.2, 8.0, 9.0] as $index => $hours) {
        $date = CarbonImmutable::parse('2026-10-01')->addDays($index)->format('Y-m-d');

        insightsCreateSession($date.' 07:30:00', [], ['efficiency_factor' => 1.0 + (0.1 * $hours)]);
        SleepRecord::factory()->create([
            'calendar_date' => $date,
            'sleep_seconds' => (int) round($hours * 3600),
            'sleep_score' => (int) round(10 * $hours),
        ]);
    }

    $smallSample = app(CorrelationService::class)->build(sport: 'running', range: 'custom', from: '2026-10-01', to: '2026-10-06');
    $smallSleep = $smallSample['sleep_vs_performance'];

    expect($smallSleep['n'])->toBe(6)
        ->and($smallSleep['correlations']['hours_vs_ef'])->toBe([
            'r' => null,
            'n' => 6,
            'p_value' => null,
            'significant' => false,
            'strength' => null,
        ])
        ->and($smallSleep['takeaway'])->toStartWith('No statistically significant link between sleep')
        ->and($smallSample['summary']['best_sleep_bucket'])->toBeNull();

    // Twelve noisy days: r is reported but is not significant, so it is never called "strong" or directional.
    $noise = [
        [6.0, 1.50], [6.2, 1.62], [6.4, 1.44],
        [7.0, 1.58], [7.1, 1.46], [7.2, 1.55],
        [8.0, 1.49], [8.1, 1.60], [8.2, 1.52],
        [8.7, 1.57], [8.9, 1.45], [9.1, 1.53],
    ];

    foreach ($noise as $index => [$hours, $ef]) {
        $date = CarbonImmutable::parse('2026-11-01')->addDays($index)->format('Y-m-d');

        insightsCreateSession($date.' 07:30:00', [], ['efficiency_factor' => $ef]);
        SleepRecord::factory()->create([
            'calendar_date' => $date,
            'sleep_seconds' => (int) round($hours * 3600),
            'sleep_score' => (int) round(10 * $hours),
        ]);
    }

    $noisySleep = app(CorrelationService::class)
        ->build(sport: 'running', range: 'custom', from: '2026-11-01', to: '2026-11-12')['sleep_vs_performance'];

    expect($noisySleep['correlations']['hours_vs_ef']['r'])->not->toBeNull()
        ->and($noisySleep['correlations']['hours_vs_ef']['n'])->toBe(12)
        ->and($noisySleep['correlations']['hours_vs_ef']['p_value'])->toBeGreaterThan(0.05)
        ->and($noisySleep['correlations']['hours_vs_ef']['significant'])->toBeFalse()
        ->and($noisySleep['correlations']['hours_vs_ef']['strength'])->toBe('inconclusive')
        ->and($noisySleep['takeaway'])->toStartWith('No statistically significant link between sleep');
});

it('collapses multi-session days to one primary session before pairing with day-level health data', function () {
    $primaryIds = [];

    foreach ([5.6, 6.0, 6.4, 6.8, 7.2, 7.6, 8.0, 8.4, 8.8, 9.2] as $index => $hours) {
        $date = CarbonImmutable::parse('2026-11-10')->addDays($index)->format('Y-m-d');

        $primaryIds[] = insightsCreateSession($date.' 07:00:00', ['moving_duration_seconds' => 3600.0], [
            'efficiency_factor' => round(1.2 + (0.05 * $hours), 3),
        ])->id;

        // Short shake-out with the opposite trend; it must not be paired with the same night again.
        insightsCreateSession($date.' 17:00:00', ['moving_duration_seconds' => 1200.0], [
            'efficiency_factor' => round(2.5 - (0.1 * $hours), 3),
        ]);

        SleepRecord::factory()->create([
            'calendar_date' => $date,
            'sleep_seconds' => (int) round($hours * 3600),
        ]);
    }

    // The longest session lacks derived metrics, so the shorter one with EF is the day's primary session.
    insightsCreateSession('2026-11-20 06:00:00', ['moving_duration_seconds' => 7200.0], null);
    $primaryIds[] = insightsCreateSession('2026-11-20 12:00:00', ['moving_duration_seconds' => 1800.0], [
        'efficiency_factor' => 1.65,
    ])->id;
    SleepRecord::factory()->create(['calendar_date' => '2026-11-20', 'sleep_seconds' => 32400]);

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $sleep = $payload['sleep_vs_performance'];

    expect($payload['summary']['total_activities'])->toBe(22)
        ->and($payload['summary']['training_days'])->toBe(11)
        ->and($payload['summary']['with_sleep'])->toBe(11)
        ->and($sleep['n'])->toBe(11)
        ->and($sleep['correlations']['hours_vs_ef']['n'])->toBe(11)
        ->and($sleep['correlations']['hours_vs_ef']['r'])->toBeGreaterThan(0.95)
        ->and(collect($sleep['scatter'])->pluck('activity_id')->all())->toBe($primaryIds);
});

it('aggregates each training day once for next-night recovery using total load and the hardest session time', function () {
    // Easy morning + hard evening on one day → one evening/hard training day.
    insightsCreateSession('2026-11-10 07:00:00', ['training_load' => 30.0, ...insightsHrZones(2.0)]);
    insightsCreateSession('2026-11-10 19:00:00', ['training_load' => 120.0, ...insightsHrZones(25.0)]);

    // Two moderate sessions whose combined load is hard; the heavier morning session sets the time slot.
    insightsCreateSession('2026-11-12 07:00:00', ['training_load' => 60.0, ...insightsHrZones(5.0)]);
    insightsCreateSession('2026-11-12 14:00:00', ['training_load' => 50.0, ...insightsHrZones(9.0)]);

    // Low load but 30% of time in Z4–Z5 → hard from the activity's HR zones alone.
    insightsCreateSession('2026-11-14 18:30:00', ['training_load' => 20.0, ...insightsHrZones(30.0)]);

    // Low load and no HR zone data → easy.
    insightsCreateSession('2026-11-16 07:00:00', [
        'training_load' => 20.0,
        'hr_zone_1_seconds' => null,
        'hr_zone_2_seconds' => null,
        'hr_zone_3_seconds' => null,
        'hr_zone_4_seconds' => null,
        'hr_zone_5_seconds' => null,
    ]);

    SleepRecord::factory()->create(['calendar_date' => '2026-11-11', 'sleep_score' => 70]);
    SleepRecord::factory()->create(['calendar_date' => '2026-11-13', 'sleep_score' => 82]);
    SleepRecord::factory()->create(['calendar_date' => '2026-11-15', 'sleep_score' => 74]);
    SleepRecord::factory()->create(['calendar_date' => '2026-11-17', 'sleep_score' => 88]);

    $recovery = app(CorrelationService::class)->build(sport: 'running', range: '90d')['workout_to_recovery'];
    $matrix = collect($recovery['matrix'])->keyBy(fn (array $cell): string => $cell['time_key'].':'.$cell['intensity_key']);
    $byIntensity = collect($recovery['by_intensity'])->keyBy('key');

    expect($recovery['n'])->toBe(4)
        ->and($byIntensity['hard']['count'])->toBe(3)
        ->and($byIntensity['easy']['count'])->toBe(1)
        ->and($byIntensity['moderate']['count'])->toBe(0)
        ->and($matrix['evening:hard']['count'])->toBe(2)
        ->and($matrix['evening:hard']['avg_sleep_score'])->toBe(72.0)
        ->and($matrix['morning:hard']['count'])->toBe(1)
        ->and($matrix['morning:hard']['avg_sleep_score'])->toBe(82.0)
        ->and($matrix['morning:easy']['count'])->toBe(1)
        ->and($matrix['morning:easy']['avg_sleep_score'])->toBe(88.0);
});

it('never pools power-based and speed-based Efficiency Factor', function () {
    $runEfs = [1.48, 1.52, 1.50, 1.55, 1.45, 1.50];

    foreach ($runEfs as $index => $ef) {
        insightsCreateSession('2026-11-'.(10 + $index).' 08:00:00', [], [
            'efficiency_factor' => $ef,
            'decoupling_metric' => 'gap',
            'decoupling_pct' => 2.0,
        ]);
    }

    foreach (range(0, 3) as $index) {
        $ride = Activity::factory()->roadBiking()->create([
            'started_at_local' => CarbonImmutable::parse('2026-11-'.(16 + $index).' 08:00:00'),
        ]);
        ActivityDerivedMetric::factory()->for($ride)->create([
            'efficiency_factor' => 2.40,
            'decoupling_metric' => 'power',
            'decoupling_pct' => 6.0,
        ]);
    }

    $allPayload = app(CorrelationService::class)->build(sport: 'all', range: '90d');
    $morning = collect($allPayload['schedule_patterns']['by_time_of_day'])->keyBy('key')['morning'];

    expect($allPayload['summary']['ef_metric'])->toBe('speed')
        ->and($allPayload['summary']['ef_excluded_sessions'])->toBe(4)
        ->and($allPayload['summary']['with_derived_metrics'])->toBe(10)
        ->and($morning['count'])->toBe(10)
        ->and($morning['avg_ef'])->toBe(1.5)
        ->and($morning['avg_decoupling'])->toBe(3.6);

    $cyclingPayload = app(CorrelationService::class)->build(sport: 'cycling', range: '90d');

    expect($cyclingPayload['summary']['ef_metric'])->toBe('power')
        ->and($cyclingPayload['summary']['ef_excluded_sessions'])->toBe(0);
});

it('measures next-night HRV against the trailing seven nights instead of a weekly average that includes the night', function () {
    insightsCreateSession('2026-11-20 08:00:00', ['training_load' => 120.0, ...insightsHrZones(25.0)]);

    // Prior seven nights (Nov 14–20) average 60 ms; Garmin's weekly_avg is deliberately misleading.
    insightsCreateHrvNights([
        '2026-11-14' => 60, '2026-11-15' => 62, '2026-11-16' => 64, '2026-11-17' => 58,
        '2026-11-18' => 56, '2026-11-19' => 60, '2026-11-20' => 60,
    ]);
    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 70,
        'weekly_avg' => 40,
    ]);

    // Fewer than three prior nights → no delta, but the raw HRV is still reported.
    insightsCreateSession('2026-11-05 19:00:00', ['training_load' => 120.0, ...insightsHrZones(25.0)]);
    insightsCreateHrvNights(['2026-11-04' => 60, '2026-11-05' => 61]);
    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-06',
        'last_night_avg' => 66,
        'weekly_avg' => 40,
    ]);

    $recovery = app(CorrelationService::class)->build(sport: 'running', range: '90d')['workout_to_recovery'];
    $byTime = collect($recovery['by_time_of_day'])->keyBy('key');

    expect($recovery['n'])->toBe(2)
        ->and($byTime['morning']['avg_hrv'])->toBe(70.0)
        ->and($byTime['morning']['avg_hrv_delta'])->toBe(10.0)
        ->and($byTime['evening']['avg_hrv'])->toBe(66.0)
        ->and($byTime['evening']['avg_hrv_delta'])->toBeNull();
});

it('reports a significant evening vs morning recovery gap with an 18:00 evening boundary', function () {
    $nights = [];

    foreach (CarbonImmutable::parse('2026-11-01')->daysUntil('2026-11-21') as $night) {
        $nights[$night->format('Y-m-d')] = 65;
    }

    $workouts = [
        ['date' => '2026-11-08', 'time' => '07:00:00', 'next_hrv' => 70, 'next_sleep' => 85],
        ['date' => '2026-11-10', 'time' => '07:30:00', 'next_hrv' => 71, 'next_sleep' => 84],
        ['date' => '2026-11-12', 'time' => '08:00:00', 'next_hrv' => 69, 'next_sleep' => 86],
        ['date' => '2026-11-14', 'time' => '18:30:00', 'next_hrv' => 52, 'next_sleep' => 66],
        ['date' => '2026-11-16', 'time' => '19:00:00', 'next_hrv' => 54, 'next_sleep' => 64],
        ['date' => '2026-11-18', 'time' => '19:30:00', 'next_hrv' => 53, 'next_sleep' => 65],
        ['date' => '2026-11-20', 'time' => '17:30:00', 'next_hrv' => 65, 'next_sleep' => 75],
    ];

    foreach ($workouts as $workout) {
        insightsCreateSession($workout['date'].' '.$workout['time'], ['training_load' => 130.0, ...insightsHrZones(25.0)]);

        $nextDate = CarbonImmutable::parse($workout['date'])->addDay()->format('Y-m-d');
        $nights[$nextDate] = $workout['next_hrv'];
        SleepRecord::factory()->create(['calendar_date' => $nextDate, 'sleep_score' => $workout['next_sleep']]);
    }

    insightsCreateHrvNights($nights);

    // Same-day sleep for a workout day must not be treated as that workout's recovery night.
    SleepRecord::factory()->create(['calendar_date' => '2026-11-08', 'sleep_score' => 99]);

    $recovery = app(CorrelationService::class)->build(sport: 'running', range: '90d')['workout_to_recovery'];
    $byTime = collect($recovery['by_time_of_day'])->keyBy('key');

    expect($recovery['n'])->toBe(7)
        ->and($byTime['morning']['count'])->toBe(3)
        ->and($byTime['morning']['avg_sleep_score'])->toBe(85.0)
        ->and($byTime['morning']['avg_hrv_delta'])->toBeGreaterThan(4.0)
        ->and($byTime['afternoon']['count'])->toBe(1)
        ->and($byTime['afternoon']['label'])->toBe('Afternoon (12:00–18:00)')
        ->and($byTime['evening']['count'])->toBe(3)
        ->and($byTime['evening']['avg_hrv_delta'])->toBeLessThan(-8.0)
        ->and($recovery['takeaway'])->toStartWith('Hard evening sessions average -')
        ->and($recovery['takeaway'])->toMatch('/\(p [<=] [0-9.]+, n = 7 days\)\.$/');
});

it('buckets HRV status and baseline balance and reports significant HRV and RHR correlations', function () {
    $samples = [
        [42, 'LOW', 58, 1.35], [44, 'LOW', 57, 1.37], [46, 'LOW', 56, 1.39],
        [55, 'BALANCED', 52, 1.50], [58, 'BALANCED', 51, 1.53], [60, 'BALANCED', 50, 1.55],
        [62, 'BALANCED', 49, 1.57], [65, 'BALANCED', 48, 1.60], [68, 'BALANCED', 47, 1.62],
        [74, 'UNBALANCED', 46, 1.66], [76, 'UNBALANCED', 45, 1.68], [78, 'UNBALANCED', 44, 1.70],
    ];

    foreach ($samples as $index => [$hrv, $status, $rhr, $ef]) {
        $date = CarbonImmutable::parse('2026-11-01')->addDays($index)->format('Y-m-d');

        insightsCreateSession($date.' 08:00:00', [], ['efficiency_factor' => $ef, 'decoupling_pct' => 8.0 - ($ef * 4.0)]);
        HrvRecord::factory()->create([
            'calendar_date' => $date,
            'last_night_avg' => $hrv,
            'status' => $status,
            'baseline_balanced_low' => 50,
            'baseline_balanced_upper' => 70,
        ]);
        DailyMetric::factory()->create(['calendar_date' => $date, 'resting_hr' => $rhr]);
    }

    $hrv = app(CorrelationService::class)->build(sport: 'running', range: '90d')['hrv_vs_performance'];
    $statusByKey = collect($hrv['status_buckets'])->keyBy('key');
    $baselineByKey = collect($hrv['baseline_buckets'])->keyBy('key');

    expect($hrv['n'])->toBe(12)
        ->and($statusByKey['low']['count'])->toBe(3)
        ->and($statusByKey['balanced']['count'])->toBe(6)
        ->and($statusByKey['unbalanced']['count'])->toBe(3)
        ->and($baselineByKey['below']['count'])->toBe(3)
        ->and($baselineByKey['in_baseline']['count'])->toBe(6)
        ->and($baselineByKey['above']['count'])->toBe(3)
        ->and($baselineByKey['above']['avg_ef'])->toBe(1.68)
        ->and($hrv['correlations']['hrv_vs_ef']['r'])->toBeGreaterThan(0.95)
        ->and($hrv['correlations']['hrv_vs_ef']['significant'])->toBeTrue()
        ->and($hrv['correlations']['rhr_vs_ef']['r'])->toBeLessThan(-0.95)
        ->and($hrv['takeaway'])->toStartWith('When HRV is above baseline, sessions average 1.68 EF vs 1.37 EF when Below Baseline');
});

it('falls back to a 28-night rolling HRV baseline and then to the weekly average when Garmin bounds are missing', function () {
    $nights = [];

    foreach (CarbonImmutable::parse('2026-10-20')->daysUntil('2026-11-16') as $offset => $night) {
        $nights[$night->format('Y-m-d')] = $offset % 2 === 0 ? 58 : 62;
    }

    foreach ($nights as $date => $value) {
        HrvRecord::factory()->create([
            'calendar_date' => $date,
            'last_night_avg' => $value,
            'baseline_balanced_low' => null,
            'baseline_balanced_upper' => null,
        ]);
    }

    foreach (['2026-11-17' => 50, '2026-11-18' => 70, '2026-11-19' => 61] as $date => $value) {
        insightsCreateSession($date.' 08:00:00');
        HrvRecord::factory()->create([
            'calendar_date' => $date,
            'last_night_avg' => $value,
            'baseline_balanced_low' => null,
            'baseline_balanced_upper' => null,
        ]);
    }

    // No prior nights within 28 days → classify against Garmin's weekly average (60 ± 5%).
    insightsCreateSession('2026-09-15 08:00:00');
    HrvRecord::factory()->create([
        'calendar_date' => '2026-09-15',
        'last_night_avg' => 50,
        'weekly_avg' => 60,
        'baseline_balanced_low' => null,
        'baseline_balanced_upper' => null,
    ]);

    $scatter = collect(app(CorrelationService::class)->build(sport: 'running', range: '90d')['hrv_vs_performance']['scatter'])
        ->keyBy('date');

    expect($scatter['2026-11-17']['baseline_zone'])->toBe('Below Baseline')
        ->and($scatter['2026-11-18']['baseline_zone'])->toBe('Above Baseline')
        ->and($scatter['2026-11-19']['baseline_zone'])->toBe('In Baseline')
        ->and($scatter['2026-09-15']['baseline_zone'])->toBe('Below Baseline');
});

it('buckets ambient temperature and identifies a significantly better temperature band', function () {
    $samples = [
        [7.5, 1.55], [8.5, 1.57],
        [11.0, 1.66], [12.5, 1.67], [14.0, 1.65],
        [16.0, 1.58], [18.0, 1.52],
        [21.0, 1.45], [23.0, 1.42],
        [25.5, 1.36], [27.0, 1.34], [29.0, 1.32],
    ];

    foreach ($samples as $index => [$temp, $ef]) {
        $session = insightsCreateSession(
            CarbonImmutable::parse('2026-11-01')->addDays($index)->format('Y-m-d').' 08:00:00',
            [],
            ['efficiency_factor' => $ef, 'decoupling_pct' => round(($temp / 4.0), 2)],
        );
        ActivityWeather::factory()->for($session)->create(['temperature_c' => $temp]);
    }

    $payload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $weather = $payload['weather_impact'];
    $tempByKey = collect($weather['temperature_buckets'])->keyBy('key');

    expect($weather['n'])->toBe(12)
        ->and($tempByKey->pluck('count')->all())->toBe([2, 3, 2, 2, 3])
        ->and($tempByKey['10_to_15']['avg_ef'])->toBe(1.66)
        ->and($weather['correlations']['temp_vs_ef']['r'])->toBeLessThan(-0.8)
        ->and($weather['correlations']['temp_vs_ef']['significant'])->toBeTrue()
        ->and($weather['correlations']['temp_vs_decoupling']['r'])->toBeGreaterThan(0.8)
        ->and($payload['summary']['optimal_temp_bucket']['key'])->toBe('10_to_15')
        ->and($weather['takeaway'])->toStartWith('Aerobic efficiency peaks in 10–15°C (1.66 EF) and is 19.3% lower in ≥ 25°C (1.34 EF');
});

it('classifies wind exposure from effective wind first and headwind/tailwind shares second', function () {
    $cases = [
        ['avg_effective_wind_kmh' => -2.0, 'headwind_pct' => 10.0, 'tailwind_pct' => 80.0],
        ['avg_effective_wind_kmh' => 0.5, 'headwind_pct' => 50.0, 'tailwind_pct' => 30.0],
        ['avg_effective_wind_kmh' => 2.0, 'headwind_pct' => 80.0, 'tailwind_pct' => 10.0],
        ['avg_effective_wind_kmh' => null, 'headwind_pct' => 20.0, 'tailwind_pct' => 60.0],
        ['avg_effective_wind_kmh' => 0.0, 'headwind_pct' => 40.0, 'tailwind_pct' => 40.0],
        ['avg_effective_wind_kmh' => null, 'headwind_pct' => null, 'tailwind_pct' => null],
    ];

    foreach ($cases as $index => $wind) {
        $session = insightsCreateSession('2026-11-'.(10 + $index).' 08:00:00');
        ActivityWeather::factory()->for($session)->create($wind);
    }

    $weather = app(CorrelationService::class)->build(sport: 'running', range: '90d')['weather_impact'];
    $windByKey = collect($weather['wind_buckets'])->keyBy('key');

    expect($weather['wind_n'])->toBe(5)
        ->and($windByKey['headwind']['count'])->toBe(2)
        ->and($windByKey['tailwind']['count'])->toBe(2)
        ->and($windByKey['balanced']['count'])->toBe(1);
});

it('isolates health records strictly through HealthSourceRegistry primary provider scoping', function () {
    insightsCreateSession('2026-11-20 07:30:00', [], [
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
        ['datetime' => '2026-11-17 08:30:00', 'distance' => 10000.0, 'ef' => 1.68], // Tue Morning
        ['datetime' => '2026-11-18 08:30:00', 'distance' => 10000.0, 'ef' => 1.70], // Wed Morning
        ['datetime' => '2026-11-20 09:00:00', 'distance' => 12000.0, 'ef' => 1.72], // Fri Morning
        ['datetime' => '2026-11-21 12:30:00', 'distance' => 15000.0, 'ef' => 1.54], // Sat Midday
        ['datetime' => '2026-11-21 16:00:00', 'distance' => 6000.0, 'ef' => 1.48],  // Sat Afternoon
        ['datetime' => '2026-11-19 19:00:00', 'distance' => 9000.0, 'ef' => 1.40],  // Thu Evening
        ['datetime' => '2026-11-22 19:00:00', 'distance' => 9000.0, 'ef' => 1.42],  // Sun Evening
        ['datetime' => '2026-11-23 19:30:00', 'distance' => 9000.0, 'ef' => 1.38],  // Mon Evening
    ];

    foreach ($runs as $run) {
        insightsCreateSession($run['datetime'], ['distance_meters' => $run['distance']], ['efficiency_factor' => $run['ef']]);
    }

    // Cycling activity that must be excluded when filtering to running
    $ride = Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'distance_meters' => 50000.0,
    ]);
    ActivityDerivedMetric::factory()->for($ride)->create(['efficiency_factor' => 2.40]);

    $runPayload = app(CorrelationService::class)->build(sport: 'running', range: '90d');
    $daysByKey = collect($runPayload['schedule_patterns']['by_day_of_week'])->keyBy('key');
    $timesByKey = collect($runPayload['schedule_patterns']['by_time_of_day'])->keyBy('key');

    expect($runPayload['summary']['total_activities'])->toBe(9)
        ->and($daysByKey['mon']['count'])->toBe(2)
        ->and($daysByKey['sat']['count'])->toBe(2)
        ->and($daysByKey['sat']['total_distance_meters'])->toBe(21000.0)
        ->and($timesByKey['early']['count'])->toBe(1)
        ->and($timesByKey['morning']['count'])->toBe(3)
        ->and($timesByKey['morning']['avg_ef'])->toBe(1.7)
        ->and($timesByKey['midday']['count'])->toBe(1)
        ->and($timesByKey['afternoon']['count'])->toBe(1)
        ->and($timesByKey['evening']['count'])->toBe(3)
        ->and($runPayload['summary']['peak_time_of_day']['key'])->toBe('morning')
        ->and($runPayload['schedule_patterns']['takeaway'])->toStartWith('Efficiency Factor is highest during Morning (07:00–11:00) (1.70 EF) vs Evening (18:00+) (1.40 EF');

    $allPayload = app(CorrelationService::class)->build(sport: 'all', range: '90d');

    expect($allPayload['summary']['total_activities'])->toBe(10);
});
