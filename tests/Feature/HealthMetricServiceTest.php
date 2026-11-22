<?php

use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Services\Health\HealthMetricService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('supports all 13 metrics with expected metadata and rejects unknown metrics', function (
    string $metric,
    string $accent,
    string $chartType,
    string $unit,
) {
    $service = app(HealthMetricService::class);

    expect($service->isSupported($metric))->toBeTrue();

    $payload = $service->forMetric($metric, '28d');

    expect($payload['metric'])->toBe($metric)
        ->and($payload['accent'])->toBe($accent)
        ->and($payload['chartType'])->toBe($chartType)
        ->and($payload['unit'])->toBe($unit)
        ->and($payload['range'])->toBe('28d')
        ->and($payload['startDate'])->toBe('2026-10-25')
        ->and($payload['endDate'])->toBe('2026-11-21')
        ->and($payload['summary'])->toHaveKeys(['latest', 'average', 'min', 'max', 'extraLabel', 'extraValue'])
        ->and($payload['points'])->toBeArray()
        ->and($payload['columns'])->not->toBeEmpty()
        ->and($payload['rows'])->toBeArray();
})->with([
    'body-battery' => ['body-battery', 'sky', 'range-bar', 'pts'],
    'sleep' => ['sleep', 'indigo', 'sleep-stages', 'h'],
    'hrv' => ['hrv', 'emerald', 'hrv-band', 'ms'],
    'steps' => ['steps', 'sky', 'bar-goal', 'steps'],
    'heart-rate' => ['heart-rate', 'rose', 'hr-range', 'bpm'],
    'stress' => ['stress', 'amber', 'stress-breakdown', 'score'],
    'intensity-minutes' => ['intensity-minutes', 'amber', 'intensity-stacked', 'min'],
    'weight' => ['weight', 'emerald', 'weight-comp', 'kg'],
    'vo2-max' => ['vo2-max', 'indigo', 'vo2-dual', 'mL/kg/min'],
    'calories' => ['calories', 'orange', 'calories-stacked', 'kcal'],
    'floors' => ['floors', 'teal', 'bar-goal', 'floors'],
    'respiration-spo2' => ['respiration-spo2', 'teal', 'dual-line', 'brpm / %'],
    'hydration' => ['hydration', 'sky', 'bar', 'mL'],
]);

it('rejects unsupported metrics and defaults unknown ranges to 28d', function () {
    $service = app(HealthMetricService::class);

    expect($service->isSupported('unknown-metric'))->toBeFalse()
        ->and($service->normalizeRange('bogus'))->toBe('28d')
        ->and($service->normalizeRange(null))->toBe('28d')
        ->and($service->normalizeRange('7d'))->toBe('7d')
        ->and($service->normalizeRange('all'))->toBe('all');

    expect(fn () => $service->forMetric('unknown-metric'))
        ->toThrow(InvalidArgumentException::class);
});

it('filters points by range and computes accurate summaries across models', function () {
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'steps' => 12000,
        'step_goal' => 10000,
        'distance_meters' => 9500.0,
        'moderate_intensity_minutes' => 20,
        'vigorous_intensity_minutes' => 15,
        'intensity_minutes_goal' => 150,
        'hydration_ml' => 2500,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-10',
        'steps' => 8000,
        'step_goal' => 10000,
        'distance_meters' => 6200.0,
        'moderate_intensity_minutes' => 10,
        'vigorous_intensity_minutes' => 5,
        'intensity_minutes_goal' => 150,
        'hydration_ml' => 1500,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-08-01',
        'steps' => 14000,
        'step_goal' => 10000,
        'distance_meters' => 11000.0,
        'hydration_ml' => 3000,
    ]);

    $service = app(HealthMetricService::class);

    $steps7d = $service->forMetric('steps', '7d');
    expect($steps7d['points'])->toHaveCount(1)
        ->and($steps7d['summary']['latest'])->toBe('12,000')
        ->and($steps7d['summary']['extraValue'])->toBe('1/1 (100%)');

    $steps28d = $service->forMetric('steps', '28d');
    expect($steps28d['points'])->toHaveCount(2)
        ->and($steps28d['summary']['average'])->toBe('10,000')
        ->and($steps28d['summary']['min'])->toBe('8,000')
        ->and($steps28d['summary']['max'])->toBe('12,000')
        ->and($steps28d['summary']['extraValue'])->toBe('1/2 (50%)')
        ->and($steps28d['rows'][0]['date'])->toBe('2026-11-21')
        ->and($steps28d['rows'][1]['date'])->toBe('2026-11-10');

    $stepsAll = $service->forMetric('steps', 'all');
    expect($stepsAll['points'])->toHaveCount(3)
        ->and($stepsAll['summary']['max'])->toBe('14,000');

    $intensity = $service->forMetric('intensity-minutes', '28d');
    expect($intensity['points'])->toHaveCount(2)
        ->and($intensity['points'][0]['granularity'])->toBe('daily')
        ->and($intensity['points'][0]['total'])->toBe(20)
        ->and($intensity['points'][1]['total'])->toBe(50)
        ->and($intensity['summary']['extraLabel'])->toBe('Weekly Goals Hit')
        ->and($intensity['summary']['extraValue'])->toBe('0/2 (0%)');

    $hydration = $service->forMetric('hydration', '28d');
    expect($hydration['points'])->toHaveCount(2)
        ->and($hydration['summary']['average'])->toBe('2,000 mL')
        ->and($hydration['summary']['extraValue'])->toBe('2.00 L');
});

it('computes sleep, hrv baseline, weight period change, and merged vo2-max data accurately', function () {
    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 25200,
        'deep_seconds' => 3600,
        'light_seconds' => 14400,
        'rem_seconds' => 7200,
        'sleep_score' => 76,
    ]);

    SleepRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'sleep_seconds' => 28800,
        'deep_seconds' => 5400,
        'light_seconds' => 16200,
        'rem_seconds' => 7200,
        'sleep_score' => 84,
    ]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 64,
        'weekly_avg' => 61,
        'last_night_5min_high' => 92,
        'status' => 'BALANCED',
        'baseline_balanced_low' => 52,
        'baseline_balanced_upper' => 70,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-01',
        'weight_grams' => 76500,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-21',
        'measured_at' => CarbonImmutable::parse('2026-11-21 07:00:00'),
        'weight_grams' => 75800,
    ]);

    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-11-21',
        'measured_at' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'weight_grams' => 75500,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-18',
        'sport' => 'running',
        'vo2_max_precise' => 57.5,
    ]);

    Vo2MaxRecord::factory()->create([
        'calendar_date' => '2026-11-18',
        'sport' => 'cycling',
        'vo2_max_precise' => 60.2,
    ]);

    FitnessAgeRecord::factory()->create([
        'calendar_date' => '2026-11-21',
        'fitness_age' => 29.5,
        'chronological_age' => 35.0,
    ]);

    $service = app(HealthMetricService::class);

    $sleep = $service->forMetric('sleep', '28d');
    expect($sleep['summary']['latest'])->toBe('8h 00m')
        ->and($sleep['summary']['average'])->toBe('7h 30m')
        ->and($sleep['summary']['extraValue'])->toBe('80 / 100');

    $hrv = $service->forMetric('hrv', '28d');
    expect($hrv['baseline'])->toBe(['low' => 52, 'high' => 70])
        ->and($hrv['summary']['latest'])->toBe('64 ms')
        ->and($hrv['summary']['extraValue'])->toBe('Balanced');

    $weight = $service->forMetric('weight', '28d');
    expect($weight['points'])->toHaveCount(2)
        ->and($weight['points'][1]['weightKg'])->toBe(75.5)
        ->and($weight['summary']['latest'])->toBe('75.5 kg')
        ->and($weight['summary']['extraValue'])->toBe('-1.0 kg');

    $vo2 = $service->forMetric('vo2-max', '28d');
    expect($vo2['points'])->toHaveCount(2)
        ->and($vo2['points'][0])->toBe([
            'date' => '2026-11-18',
            'running' => 57.5,
            'cycling' => 60.2,
            'fitnessAge' => null,
        ])
        ->and($vo2['points'][1])->toBe([
            'date' => '2026-11-21',
            'running' => null,
            'cycling' => null,
            'fitnessAge' => 29.5,
        ])
        ->and($vo2['summary']['latest'])->toBe('57.5')
        ->and($vo2['summary']['extraLabel'])->toBe('Fitness Age')
        ->and($vo2['summary']['extraValue'])->toBe('29.5 yrs');
});

it('expresses stress breakdown as percentages and annotates timezone travel days without flagging DST or today', function () {
    // 45h NZ -> US westbound travel day
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-15',
        'day_duration_seconds' => 162000,
        'avg_stress' => 19,
        'max_stress' => 85,
        'stress_qualifier' => 'CALM',
        'rest_stress_seconds' => 107580,
        'low_stress_seconds' => 19380,
        'medium_stress_seconds' => 4380,
        'high_stress_seconds' => 420,
        'steps' => 21266,
        'body_battery_high' => 98,
        'body_battery_low' => 32,
        'body_battery_charged' => 108,
        'body_battery_drained' => 112,
    ]);

    // 25h DST fall-back day (normal, should not be flagged as travel)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-16',
        'day_duration_seconds' => 90000,
        'avg_stress' => 21,
        'max_stress' => 77,
        'rest_stress_seconds' => 50000,
        'low_stress_seconds' => 10000,
        'medium_stress_seconds' => 0,
        'high_stress_seconds' => 0,
    ]);

    // 9.6h US -> NZ eastbound travel day (completed past day)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-20',
        'day_duration_seconds' => 34560,
        'avg_stress' => 7,
        'max_stress' => 53,
        'rest_stress_seconds' => 31860,
        'low_stress_seconds' => 360,
        'medium_stress_seconds' => 120,
        'high_stress_seconds' => null,
        'steps' => 451,
    ]);

    // Today in progress (21h elapsed — should not be flagged as travel)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'day_duration_seconds' => 75600,
        'avg_stress' => 17,
        'max_stress' => 87,
        'rest_stress_seconds' => 44520,
        'low_stress_seconds' => 7860,
        'medium_stress_seconds' => 2040,
        'high_stress_seconds' => 240,
        'steps' => 13118,
    ]);

    $service = app(HealthMetricService::class);
    $stress = $service->forMetric('stress', '28d');

    $nov15 = $stress['points'][0];
    $nov16 = $stress['points'][1];
    $nov20 = $stress['points'][2];
    $nov21 = $stress['points'][3];

    expect($nov15['date'])->toBe('2026-11-15')
        ->and($nov15['restPct'] + $nov15['lowPct'] + $nov15['mediumPct'] + $nov15['highPct'])->toBe(100.0)
        ->and($nov15['restPct'])->toBe(81.7)
        ->and($nov15['lowPct'])->toBe(14.7)
        ->and($nov15['mediumPct'])->toBe(3.3)
        ->and($nov15['highPct'])->toBe(0.3)
        ->and($nov15['restHours'])->toBe(29.88)
        ->and($nov15['travelDayLabel'])->toBe('45h day')
        ->and($nov16['travelDayLabel'])->toBeNull()
        ->and($nov20['travelDayLabel'])->toBe('9.6h day')
        ->and($nov21['travelDayLabel'])->toBeNull();

    // Daily log rows are reverse chronological
    expect($stress['rows'][0]['date'])->toBe('2026-11-21')
        ->and($stress['rows'][0]['dayNote'])->toBeNull()
        ->and($stress['rows'][1]['date'])->toBe('2026-11-20')
        ->and($stress['rows'][1]['dayNote'])->toBe('9.6h day')
        ->and($stress['rows'][3]['date'])->toBe('2026-11-15')
        ->and($stress['rows'][3]['dayNote'])->toBe('45h day');

    // Steps and Body Battery annotate travel days without scaling raw counts
    $steps = $service->forMetric('steps', '28d');
    expect($steps['points'][0]['value'])->toBe(21266)
        ->and($steps['points'][0]['travelDayLabel'])->toBe('45h day')
        ->and($steps['rows'][3]['dayNote'])->toBe('45h day');

    $battery = $service->forMetric('body-battery', '28d');
    expect($battery['points'][0]['charged'])->toBe(108)
        ->and($battery['points'][0]['travelDayLabel'])->toBe('45h day')
        ->and($battery['rows'][3]['dayNote'])->toBe('45h day');
});

it('normalizes calories to a 24-hour equivalent on completed travel days while preserving raw values and ignoring partial today or tiny wear days', function () {
    // 45h westbound travel day
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-15',
        'day_duration_seconds' => 162000,
        'bmr_calories' => 4153,
        'active_calories' => 847,
        'total_calories' => 5000,
    ]);

    // Normal 24h day
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-16',
        'day_duration_seconds' => 86400,
        'bmr_calories' => 2215,
        'active_calories' => 352,
        'total_calories' => 2567,
    ]);

    // Tiny 1h partial day (< 4h floor — should not be extrapolated)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-18',
        'day_duration_seconds' => 3600,
        'bmr_calories' => 100,
        'active_calories' => 26,
        'total_calories' => 126,
    ]);

    // 9.6h eastbound return travel day
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-20',
        'day_duration_seconds' => 34560,
        'bmr_calories' => 886,
        'active_calories' => 4,
        'total_calories' => 890,
    ]);

    // Today in progress (21h — should remain raw)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'day_duration_seconds' => 75600,
        'bmr_calories' => 1936,
        'active_calories' => 652,
        'total_calories' => 2588,
    ]);

    $calories = app(HealthMetricService::class)->forMetric('calories', '28d');

    $nov15 = $calories['points'][0];
    $nov16 = $calories['points'][1];
    $nov18 = $calories['points'][2];
    $nov20 = $calories['points'][3];
    $nov21 = $calories['points'][4];

    expect($nov15)->toMatchArray([
        'date' => '2026-11-15',
        'bmr' => 2215,
        'active' => 452,
        'total' => 2667,
        'rawBmr' => 4153,
        'rawActive' => 847,
        'rawTotal' => 5000,
        'dayHours' => 45.0,
        'travelDayLabel' => '45h day',
    ])
        ->and($nov16)->toMatchArray([
            'date' => '2026-11-16',
            'bmr' => 2215,
            'active' => 352,
            'total' => 2567,
            'rawTotal' => null,
            'travelDayLabel' => null,
        ])
        ->and($nov18)->toMatchArray([
            'date' => '2026-11-18',
            'bmr' => 100,
            'active' => 26,
            'total' => 126,
            'rawTotal' => null,
            'travelDayLabel' => null,
        ])
        ->and($nov20)->toMatchArray([
            'date' => '2026-11-20',
            'bmr' => 2215,
            'active' => 10,
            'total' => 2225,
            'rawBmr' => 886,
            'rawActive' => 4,
            'rawTotal' => 890,
            'dayHours' => 9.6,
            'travelDayLabel' => '9.6h day',
        ])
        ->and($nov21)->toMatchArray([
            'date' => '2026-11-21',
            'bmr' => 1936,
            'active' => 652,
            'total' => 2588,
            'rawTotal' => null,
            'travelDayLabel' => null,
        ]);

    expect($calories['summary']['max'])->toBe('2,667 kcal')
        ->and($calories['rows'][4]['date'])->toBe('2026-11-15')
        ->and($calories['rows'][4]['total'])->toBe('2,667 kcal')
        ->and($calories['rows'][4]['dayNote'])->toBe('45h day → 24h')
        ->and($calories['rows'][1]['date'])->toBe('2026-11-20')
        ->and($calories['rows'][1]['total'])->toBe('2,225 kcal')
        ->and($calories['rows'][1]['dayNote'])->toBe('9.6h day → 24h');
});

it('returns the full requested date window even when metric data is empty or sparse', function (
    string $range,
    ?string $expectedStart,
    string $expectedEnd,
) {
    WeightMeasurement::factory()->create([
        'calendar_date' => '2026-08-15',
        'weight_grams' => 82000,
    ]);

    $service = app(HealthMetricService::class);
    $weight = $service->forMetric('weight', $range);
    $hydration = $service->forMetric('hydration', $range);

    expect($weight['startDate'])->toBe($expectedStart)
        ->and($weight['endDate'])->toBe($expectedEnd)
        ->and($hydration['points'])->toBeEmpty()
        ->and($hydration['startDate'])->toBe($expectedStart)
        ->and($hydration['endDate'])->toBe($expectedEnd);
})->with([
    '7d' => ['7d', '2026-11-15', '2026-11-21'],
    '28d' => ['28d', '2026-10-25', '2026-11-21'],
    '90d' => ['90d', '2026-08-24', '2026-11-21'],
    '1y' => ['1y', '2025-11-22', '2026-11-21'],
    'all' => ['all', null, '2026-11-21'],
]);

it('tracks daily intensity minutes with Mon-Sun weekly cumulative progress on 7d/28d/90d and groups by week on 1y/all', function () {
    // Week 1: Mon 2026-11-09 – Sun 2026-11-15 (total: 10 + 40*2 + 20 + 60*2 = 230 vs goal 300 -> miss)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-10',
        'moderate_intensity_minutes' => 10,
        'vigorous_intensity_minutes' => 40,
        'intensity_minutes_goal' => 300,
    ]);
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-15',
        'moderate_intensity_minutes' => 20,
        'vigorous_intensity_minutes' => 60,
        'intensity_minutes_goal' => 300,
    ]);

    // Week 2: Mon 2026-11-16 – Sat 2026-11-21 (total: 4 + 70*2 + 6 + 90*2 = 330 vs goal 300 -> hit)
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-16',
        'moderate_intensity_minutes' => 4,
        'vigorous_intensity_minutes' => 70,
        'intensity_minutes_goal' => 300,
    ]);
    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-21',
        'moderate_intensity_minutes' => 6,
        'vigorous_intensity_minutes' => 90,
        'intensity_minutes_goal' => 300,
    ]);

    $service = app(HealthMetricService::class);

    // 28d -> daily bars + Mon–Sun cumulative weekly progress line + Weekly Goals Hit KPI
    $daily28d = $service->forMetric('intensity-minutes', '28d');
    expect($daily28d['points'])->toHaveCount(4)
        ->and($daily28d['points'][0])->toMatchArray([
            'date' => '2026-11-10',
            'weekStart' => '2026-11-09',
            'granularity' => 'daily',
            'total' => 90,
            'weeklyCumulative' => 90,
            'goal' => 300,
        ])
        ->and($daily28d['points'][1])->toMatchArray([
            'date' => '2026-11-15',
            'weekStart' => '2026-11-09',
            'granularity' => 'daily',
            'total' => 140,
            'weeklyCumulative' => 230,
            'goal' => 300,
        ])
        ->and($daily28d['points'][2])->toMatchArray([
            'date' => '2026-11-16',
            'weekStart' => '2026-11-16',
            'granularity' => 'daily',
            'total' => 144,
            'weeklyCumulative' => 144,
            'goal' => 300,
        ])
        ->and($daily28d['points'][3])->toMatchArray([
            'date' => '2026-11-21',
            'weekStart' => '2026-11-16',
            'granularity' => 'daily',
            'total' => 186,
            'weeklyCumulative' => 330,
            'goal' => 300,
        ])
        ->and($daily28d['summary'])->toMatchArray([
            'latest' => '186 min',
            'average' => '140 min/day',
            'min' => '90 min',
            'max' => '186 min',
            'extraLabel' => 'Weekly Goals Hit',
            'extraValue' => '1/2 (50%)',
        ]);

    // 7d (2026-11-15 Sun through 2026-11-21 Sat) -> Sun 15 includes Tue 10 from the same ISO week (90 + 140 = 230)
    $daily7d = $service->forMetric('intensity-minutes', '7d');
    expect($daily7d['points'])->toHaveCount(3)
        ->and($daily7d['points'][0])->toMatchArray([
            'date' => '2026-11-15',
            'weekStart' => '2026-11-09',
            'granularity' => 'daily',
            'total' => 140,
            'weeklyCumulative' => 230,
            'goal' => 300,
        ])
        ->and($daily7d['points'][1])->toMatchArray([
            'date' => '2026-11-16',
            'weekStart' => '2026-11-16',
            'granularity' => 'daily',
            'total' => 144,
            'weeklyCumulative' => 144,
            'goal' => 300,
        ])
        ->and($daily7d['summary']['extraLabel'])->toBe('Week Progress')
        ->and($daily7d['summary']['extraValue'])->toBe('330 / 300 min');

    // 1y -> weekly bars directly comparable against the weekly target
    $weekly1y = $service->forMetric('intensity-minutes', '1y');
    expect($weekly1y['points'])->toHaveCount(2)
        ->and($weekly1y['points'][0])->toMatchArray([
            'date' => '2026-11-09',
            'weekEnd' => '2026-11-15',
            'granularity' => 'weekly',
            'moderate' => 30,
            'vigorous' => 100,
            'vigorousWeighted' => 200,
            'total' => 230,
            'goal' => 300,
        ])
        ->and($weekly1y['points'][1])->toMatchArray([
            'date' => '2026-11-16',
            'weekEnd' => '2026-11-21',
            'granularity' => 'weekly',
            'moderate' => 10,
            'vigorous' => 160,
            'vigorousWeighted' => 320,
            'total' => 330,
            'goal' => 300,
        ])
        ->and($weekly1y['summary'])->toMatchArray([
            'latest' => '330 min',
            'average' => '280 min/wk',
            'min' => '230 min',
            'max' => '330 min',
            'extraLabel' => 'Goal Hit Rate',
            'extraValue' => '1/2 (50%)',
        ])
        ->and($weekly1y['rows'][0])->toMatchArray([
            'week' => '16 Nov – 21 Nov 2026',
            'total' => '330 min',
            'goal' => '300 min',
            'goalPct' => '110%',
        ]);
});
