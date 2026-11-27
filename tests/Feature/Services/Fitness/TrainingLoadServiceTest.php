<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Services\Fitness\TrainingLoadService;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

it('returns a clean empty state when no activities or health records exist', function () {
    $payload = app(TrainingLoadService::class)->build();

    expect($payload['has_data'])->toBeFalse()
        ->and($payload['filters'])->toBe([
            'sport' => 'all',
            'range' => '180d',
            'projection_days' => 14,
        ])
        ->and($payload['daily'])->toBe([])
        ->and($payload['projections'])->toBe(['rest' => [], 'maintenance' => []])
        ->and($payload['weekly_sport_load'])->toBe([])
        ->and($payload['recent_sessions'])->toBe([])
        ->and($payload['summary']['ctl'])->toBe(0.0)
        ->and($payload['summary']['atl'])->toBe(0.0)
        ->and($payload['summary']['tsb'])->toBe(0.0)
        ->and($payload['summary']['acwr'])->toBeNull()
        ->and($payload['summary']['ramp_rate_7d'])->toBe(0.0)
        ->and($payload['summary']['load_7d'])->toBe(0.0)
        ->and($payload['summary']['load_28d'])->toBe(0.0)
        ->and($payload['summary']['projected_peak_date'])->toBeNull()
        ->and($payload['summary']['form_zone']['key'])->toBe('grey_zone')
        ->and($payload['summary']['acwr_status']['key'])->toBe('insufficient')
        ->and($payload['summary']['load_source_mix']['total'])->toBe(0);
});

it('resolves activity load with strict TSS > Garmin Load > HR-zone/Banister TRIMP priority and tracks source mix', function () {
    HeartRateZone::factory()->create([
        'provider' => 'garmin_personal',
        'sport' => 'DEFAULT',
        'max_hr' => 190,
        'resting_hr' => 50,
    ]);

    // 1. Has TSS, Garmin Load, and HR zones -> must use TSS (120.0)
    $tssRide = Activity::factory()->roadBiking()->create([
        'name' => 'Power Tempo Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-26 07:00:00'),
        'tss' => 120.0,
        'training_load' => 95.0,
        'hr_zone_3_seconds' => 3600.0,
    ]);

    // 2. No TSS, has Garmin Load and HR zones -> must use Garmin Load (88.5)
    $garminRun = Activity::factory()->create([
        'name' => 'Garmin Load Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-25 07:00:00'),
        'tss' => null,
        'training_load' => 88.5,
        'hr_zone_3_seconds' => 3600.0,
    ]);

    // 3. No TSS, no Garmin Load, has HR zones -> per-zone hrTSS rate (1 h in Z3 = 71.0)
    $hrZoneRun = Activity::factory()->create([
        'name' => 'HR Zone Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-24 07:00:00'),
        'tss' => null,
        'training_load' => null,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 3600.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    // 4. No TSS, no Garmin Load, no HR zones -> Banister fallback from duration + avg_hr
    //    60 min, avg_hr = 155, HRR = (155 - 50) / (190 - 50) = 0.75
    //    Banister = 60 * 0.75 * 0.64 * exp(1.92 * 0.75) = 121.55 -> round(121.6 * 0.6, 1) = 73.0
    $banisterHike = Activity::factory()->hiking()->create([
        'name' => 'HR-Only Hike',
        'started_at_local' => CarbonImmutable::parse('2026-11-23 07:00:00'),
        'duration_seconds' => 3600.0,
        'avg_hr' => 155,
        'tss' => null,
        'training_load' => null,
        'hr_zone_1_seconds' => null,
        'hr_zone_2_seconds' => null,
        'hr_zone_3_seconds' => null,
        'hr_zone_4_seconds' => null,
        'hr_zone_5_seconds' => null,
    ]);

    $payload = app(TrainingLoadService::class)->build(range: '90d');

    $sessionsById = collect($payload['recent_sessions'])->keyBy('id');

    expect($sessionsById[$tssRide->id]['load'])->toBe(120.0)
        ->and($sessionsById[$tssRide->id]['load_source'])->toBe('tss')
        ->and($sessionsById[$garminRun->id]['load'])->toBe(88.5)
        ->and($sessionsById[$garminRun->id]['load_source'])->toBe('garmin_load')
        ->and($sessionsById[$hrZoneRun->id]['load'])->toBe(71.0)
        ->and($sessionsById[$hrZoneRun->id]['load_source'])->toBe('trimp')
        ->and($sessionsById[$banisterHike->id]['load'])->toBe(73.0)
        ->and($sessionsById[$banisterHike->id]['load_source'])->toBe('trimp');

    expect($payload['summary']['load_source_mix'])->toBe([
        'total' => 4,
        'tss_count' => 1,
        'garmin_load_count' => 1,
        'trimp_count' => 2,
        'tss_pct' => 25.0,
        'garmin_load_pct' => 25.0,
        'trimp_pct' => 50.0,
    ]);
});

it('computes CTL, ATL, TSB, ACWR, and 7-day ramp rate from history that starts before the window', function () {
    // 10-day custom window: 2026-11-17 to 2026-11-26, with training history starting 84 days earlier.
    // Seed constant 100 daily load for the 84 days before the window (2026-08-25 .. 2026-11-16)
    // plus the first 3 visible days (2026-11-17 .. 2026-11-19), then 160/day for the last 7 visible days (2026-11-20 .. 2026-11-26).
    $warmupStart = CarbonImmutable::parse('2026-08-25', 'Pacific/Auckland');
    $end = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland');

    $cursor = $warmupStart;

    while ($cursor->lessThanOrEqualTo($end)) {
        $load = $cursor->greaterThanOrEqualTo(CarbonImmutable::parse('2026-11-20', 'Pacific/Auckland'))
            ? 160.0
            : 100.0;

        Activity::factory()->create([
            'started_at_local' => $cursor->setTime(8, 0),
            'tss' => $load,
            'training_load' => null,
        ]);

        $cursor = $cursor->addDay();
    }

    $payload = app(TrainingLoadService::class)->build(
        range: 'custom',
        from: '2026-11-17',
        to: '2026-11-26',
    );

    expect($payload['daily'])->toHaveCount(10);

    // After 84 days of 100/day, CTL is ~86.8 and ATL is ~100.0 on day 1 of the visible window.
    // Exact check: 100 * (1 - (41/42)^84) = 86.79 -> after day 1 (85th day) CTL = 87.1, ATL = 100.0, TSB entering day 1 = 86.79 - 100.0 = -13.2
    $day1 = $payload['daily'][0];
    $lastDay = $payload['daily'][9];

    expect($day1['date'])->toBe('2026-11-17')
        ->and($day1['ctl'])->toBe(87.1)
        ->and($day1['atl'])->toBe(100.0)
        ->and($day1['tsb'])->toBe(-13.2)
        ->and($day1['acwr'])->toBe(1.15);

    // By 2026-11-26 (after 7 days at 160/day):
    // CTL rises noticeably above 2026-11-19 (~87.7 -> ~98.6), ramp_rate_7d ≈ +10.9, ATL ≈ 139.6, ACWR ≈ 1.42 (caution)
    expect($lastDay['date'])->toBe('2026-11-26')
        ->and($lastDay['ctl'])->toBeGreaterThan(95.0)
        ->and($lastDay['atl'])->toBeGreaterThan(135.0)
        ->and($lastDay['tsb'])->toBeLessThan(-30.0)
        ->and($lastDay['acwr'])->toBeGreaterThan(1.3)
        ->and($lastDay['acwr'])->toBeLessThanOrEqual(1.5)
        ->and($lastDay['ramp_rate'])->toBeGreaterThan(9.0)
        ->and($payload['summary']['load_7d'])->toBe(1120.0)
        ->and($payload['summary']['load_28d'])->toBe(3220.0)
        ->and($payload['summary']['form_zone']['key'])->toBe('high_risk')
        ->and($payload['summary']['acwr_status']['key'])->toBe('caution');
});

it('returns null ACWR when CTL is below 5', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-26 08:00:00'),
        'tss' => 42.0,
        'training_load' => null,
    ]);

    $payload = app(TrainingLoadService::class)->build(range: '90d');
    $today = $payload['daily'][count($payload['daily']) - 1];

    // Single day of 42 load -> CTL = 1.0 (< 5), ATL = 6.0 -> ACWR must be null
    expect($today['ctl'])->toBe(1.0)
        ->and($today['atl'])->toBe(6.0)
        ->and($today['acwr'])->toBeNull()
        ->and($payload['summary']['acwr'])->toBeNull()
        ->and($payload['summary']['acwr_status']['key'])->toBe('insufficient');
});

it('filters by sport and stacks cross-sport loads across running, cycling, and other buckets', function () {
    Activity::factory()->create([
        'name' => 'Morning Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-26 07:00:00'),
        'tss' => 60.0,
        'training_load' => null,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Lunch Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-26 12:00:00'),
        'tss' => 110.0,
        'training_load' => null,
    ]);

    Activity::factory()->hiking()->create([
        'name' => 'Evening Hike',
        'started_at_local' => CarbonImmutable::parse('2026-11-26 17:00:00'),
        'tss' => 30.0,
        'training_load' => null,
    ]);

    $service = app(TrainingLoadService::class);
    $allPayload = $service->build(sport: 'all', range: '90d');
    $allToday = $allPayload['daily'][count($allPayload['daily']) - 1];

    expect($allToday['load'])->toBe(200.0)
        ->and($allToday['activity_count'])->toBe(3)
        ->and($allToday['by_sport'])->toBe([
            'running' => 60.0,
            'cycling' => 110.0,
            'other' => 30.0,
        ])
        ->and($allPayload['weekly_sport_load'])->toHaveCount(12);

    $currentWeek = $allPayload['weekly_sport_load'][11];

    expect($currentWeek['running'])->toBe(60.0)
        ->and($currentWeek['cycling'])->toBe(110.0)
        ->and($currentWeek['other'])->toBe(30.0)
        ->and($currentWeek['total'])->toBe(200.0)
        ->and($currentWeek['activity_count'])->toBe(3);

    $cyclingPayload = $service->build(sport: 'cycling', range: '90d');
    $cyclingToday = $cyclingPayload['daily'][count($cyclingPayload['daily']) - 1];

    expect($cyclingPayload['filters']['sport'])->toBe('cycling')
        ->and($cyclingToday['load'])->toBe(110.0)
        ->and($cyclingToday['activity_count'])->toBe(1)
        ->and($cyclingToday['by_sport'])->toBe([
            'running' => 0.0,
            'cycling' => 110.0,
            'other' => 0.0,
        ])
        ->and($cyclingPayload['recent_sessions'])->toHaveCount(1)
        ->and($cyclingPayload['recent_sessions'][0]['name'])->toBe('Lunch Ride');
});

it('isolates health overlays strictly through HealthSourceRegistry primary providers', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-26 07:00:00'),
        'tss' => 80.0,
        'training_load' => null,
    ]);

    HrvRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-26',
        'last_night_avg' => 62,
        'weekly_avg' => 60,
    ]);
    HrvRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-26',
        'last_night_avg' => 41,
        'weekly_avg' => 42,
    ]);

    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-26',
        'resting_hr' => 48,
    ]);
    DailyMetric::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-26',
        'resting_hr' => 57,
    ]);

    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-26',
        'sleep_score' => 86,
    ]);
    SleepRecord::factory()->create([
        'provider' => 'oura',
        'calendar_date' => '2026-11-26',
        'sleep_score' => 71,
    ]);

    // A weekly average must not stand in for a missing nightly reading
    HrvRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-25',
        'last_night_avg' => null,
        'weekly_avg' => 44,
    ]);

    app(HealthSourceRegistry::class)->saveConfig([
        'hrv' => 'apple_health',
        'daily_metrics' => 'garmin_personal',
        'sleep' => 'oura',
    ]);

    $payload = app(TrainingLoadService::class)->build(range: '90d');
    $today = $payload['daily'][count($payload['daily']) - 1];
    $yesterday = $payload['daily'][count($payload['daily']) - 2];

    expect($yesterday['hrv'])->toBeNull()
        ->and($today['hrv'])->toBe(41)
        ->and($today['resting_hr'])->toBe(48)
        ->and($today['sleep_score'])->toBe(71)
        ->and($payload['health_availability'])->toBe([
            'hrv' => true,
            'resting_hr' => true,
            'sleep_score' => true,
        ]);
});

it('projects rest and maintenance taper curves with clamped horizon and finds projected peak date', function () {
    // Build solid chronic load (80/day for 42 days) followed by a hard 7-day overload (140/day)
    // so the athlete ends fatigued (TSB < 0) and reaches Fresh (TSB >= +5) during a 14-day taper.
    $start = CarbonImmutable::parse('2026-10-09', 'Pacific/Auckland');
    $end = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland');
    $cursor = $start;

    while ($cursor->lessThanOrEqualTo($end)) {
        $load = $cursor->greaterThanOrEqualTo(CarbonImmutable::parse('2026-11-20', 'Pacific/Auckland'))
            ? 140.0
            : 80.0;

        Activity::factory()->create([
            'started_at_local' => $cursor->setTime(8, 0),
            'tss' => $load,
            'training_load' => null,
        ]);

        $cursor = $cursor->addDay();
    }

    $service = app(TrainingLoadService::class);
    $payload = $service->build(range: '90d', projectionDays: 14);

    // 28-day load = 21 * 80 + 7 * 140 = 2660 -> avg 95/day -> 50% maintenance = 47.5/day
    expect($payload['summary']['tsb'])->toBeLessThan(0.0)
        ->and($payload['summary']['maintenance_daily_load'])->toBe(47.5)
        ->and($payload['projections']['rest'])->toHaveCount(14)
        ->and($payload['projections']['maintenance'])->toHaveCount(14)
        ->and($payload['projections']['rest'][0]['date'])->toBe('2026-11-27')
        ->and($payload['projections']['rest'][0]['load'])->toBe(0.0)
        ->and($payload['projections']['maintenance'][0]['load'])->toBe(47.5);

    // Under rest, ATL drops faster than under maintenance, so TSB rises higher and reaches +5 sooner
    $restFinal = $payload['projections']['rest'][13];
    $maintenanceFinal = $payload['projections']['maintenance'][13];

    expect($restFinal['tsb'])->toBeGreaterThan($maintenanceFinal['tsb'])
        ->and($restFinal['ctl'])->toBeLessThan($maintenanceFinal['ctl'])
        ->and($payload['summary']['projected_peak_date'])->not->toBeNull()
        ->and($payload['summary']['projected_peak_dates']['rest'])->not->toBeNull();

    // Verify projection_days clamping to [7, 21]
    $clampedLow = $service->build(range: '90d', projectionDays: 2);
    $clampedHigh = $service->build(range: '90d', projectionDays: 45);

    expect($clampedLow['filters']['projection_days'])->toBe(7)
        ->and($clampedLow['projections']['rest'])->toHaveCount(7)
        ->and($clampedHigh['filters']['projection_days'])->toBe(21)
        ->and($clampedHigh['projections']['rest'])->toHaveCount(21);

    // Peak form is the highest projected TSB, not the first day it crosses +5
    $restPoints = collect($clampedHigh['projections']['rest']);
    $firstFreshDate = $restPoints->first(fn (array $point) => $point['tsb'] >= 5.0)['date'];
    $highestTsbDate = $restPoints->sortByDesc('tsb')->first()['date'];

    expect($clampedHigh['summary']['projected_peak_dates']['rest'])->toBe($highestTsbDate)
        ->and($highestTsbDate)->toBeGreaterThan($firstFreshDate)
        ->and($highestTsbDate)->not->toBe($restPoints->last()['date']);
});

it('reports the same CTL and TSB for a date regardless of the selected range when history predates the window', function () {
    // 400 days of steady 100/day: true CTL ≈ ATL ≈ 100 and TSB ≈ 0 well before the visible windows.
    $cursor = CarbonImmutable::parse('2025-10-23', 'Pacific/Auckland');
    $end = CarbonImmutable::parse('2026-11-26', 'Pacific/Auckland');

    while ($cursor->lessThanOrEqualTo($end)) {
        Activity::factory()->create([
            'started_at_local' => $cursor->setTime(8, 0),
            'tss' => 100.0,
            'training_load' => null,
        ]);

        $cursor = $cursor->addDay();
    }

    $service = app(TrainingLoadService::class);
    $dayIn = fn (array $payload) => collect($payload['daily'])->firstWhere('date', '2026-09-01');

    $quarter = $dayIn($service->build(range: '90d'));
    $halfYear = $dayIn($service->build(range: '180d'));
    $year = $dayIn($service->build(range: '1y'));

    expect($quarter['ctl'])->toBeGreaterThan(99.5)
        ->and($quarter['ctl'])->toEqualWithDelta($year['ctl'], 0.3)
        ->and($halfYear['ctl'])->toEqualWithDelta($year['ctl'], 0.3)
        ->and($quarter['tsb'])->toEqualWithDelta(0.0, 0.5)
        ->and($quarter['tsb'])->toEqualWithDelta($year['tsb'], 0.3);
});

it('decays CTL and ATL across rest days after a single session', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-16 08:00:00'),
        'tss' => 100.0,
        'training_load' => null,
    ]);

    $payload = app(TrainingLoadService::class)->build(range: '90d');
    $restDays = collect($payload['daily'])->filter(fn (array $day) => $day['date'] > '2026-11-16')->values();
    $today = $restDays->last();

    // Session day: CTL = 100/42 = 2.38, ATL = 100/7 = 14.29; then 10 rest days of pure decay.
    // CTL = 2.381 × (41/42)^10 = 1.9, ATL = 14.286 × (6/7)^10 = 3.1, TSB = CTL(11-25) − ATL(11-25) = 1.917 − 3.568 = −1.7
    expect($restDays)->toHaveCount(10)
        ->and($restDays->pluck('load')->unique()->all())->toBe([0.0])
        ->and($restDays->pluck('atl')->all())->toBe($restDays->pluck('atl')->sortDesc()->values()->all())
        ->and($today['ctl'])->toBe(1.9)
        ->and($today['atl'])->toBe(3.1)
        ->and($today['tsb'])->toBe(-1.7);
});

it('clamps a custom range that extends past today', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        'tss' => 80.0,
        'training_load' => null,
    ]);

    $service = app(TrainingLoadService::class);
    $payload = $service->build(range: 'custom', from: '2026-11-01', to: '2027-01-15');

    expect($payload['dateWindow']['to'])->toBe('2026-11-26')
        ->and($payload['dateWindow']['end'])->toBe('2026-11-26')
        ->and($payload['daily'])->toHaveCount(26)
        ->and($payload['daily'][25]['date'])->toBe('2026-11-26')
        ->and($payload['projections']['rest'][0]['date'])->toBe('2026-11-27');

    $futureOnly = $service->build(range: 'custom', from: '2027-01-01', to: '2027-02-01');

    expect($futureOnly['daily'])->toHaveCount(1)
        ->and($futureOnly['daily'][0]['date'])->toBe('2026-11-26');
});

it('omits projections when the window ends before today', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-08-20 08:00:00'),
        'tss' => 120.0,
        'training_load' => null,
    ]);

    $service = app(TrainingLoadService::class);
    $steppedBack = $service->build(range: '90d', end: '2026-09-01');
    $pastCustom = $service->build(range: 'custom', from: '2026-08-01', to: '2026-09-01');

    expect($steppedBack['dateWindow']['isLatest'])->toBeFalse()
        ->and($steppedBack['daily'][count($steppedBack['daily']) - 1]['date'])->toBe('2026-09-01')
        ->and($steppedBack['projections'])->toBe(['rest' => [], 'maintenance' => []])
        ->and($steppedBack['summary']['projected_peak_date'])->toBeNull()
        ->and($pastCustom['projections'])->toBe(['rest' => [], 'maintenance' => []]);
});

it('caps the all-time range to five years and says so in the label', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2019-06-01 08:00:00'),
        'tss' => 50.0,
        'training_load' => null,
    ]);
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        'tss' => 50.0,
        'training_load' => null,
    ]);

    $payload = app(TrainingLoadService::class)->build(range: 'all');

    expect($payload['daily'])->toHaveCount(1826)
        ->and($payload['daily'][0]['date'])->toBe('2021-11-27')
        ->and($payload['dateWindow']['label'])->toBe('All time · last 5 years');
});
