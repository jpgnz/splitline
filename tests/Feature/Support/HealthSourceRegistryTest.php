<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Activities\ActivityQueryService;
use App\Services\Health\DashboardService;
use App\Services\Health\HealthMetricService;
use App\Services\Sports\EnduranceHubService;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('resolves auto health providers by recency and breaks ties preferring garmin_personal then alphabetical', function () {
    $registry = app(HealthSourceRegistry::class);

    // 0 providers -> null
    expect($registry->availableProviders('sleep'))->toBe([])
        ->and($registry->resolveProvider('sleep'))->toBeNull();

    // 2 providers where oura has a more recent calendar_date -> auto picks oura
    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-19',
        'sleep_seconds' => 25200,
    ]);
    SleepRecord::factory()->create([
        'provider' => 'oura',
        'calendar_date' => '2026-11-20',
        'sleep_seconds' => 28800,
    ]);

    $registry->flushMemo();

    expect($registry->availableProviders('sleep'))->toBe(['garmin_personal', 'oura'])
        ->and($registry->resolveProvider('sleep'))->toBe('oura');

    // Same calendar_date AND same updated_at -> tie-breaker prefers garmin_personal
    $tiedTimestamp = CarbonImmutable::parse('2026-11-21 08:00:00');
    DailyMetric::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-21',
        'steps' => 8000,
        'updated_at' => $tiedTimestamp,
    ]);
    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'steps' => 9500,
        'updated_at' => $tiedTimestamp,
    ]);

    $registry->flushMemo();

    expect($registry->resolveProvider('daily_metrics'))->toBe('garmin_personal');

    // Same calendar_date AND updated_at without garmin_personal -> alphabetical tie-breaker (apple_health < withings)
    WeightMeasurement::factory()->create([
        'provider' => 'withings',
        'calendar_date' => '2026-11-21',
        'weight_grams' => 72000,
        'updated_at' => $tiedTimestamp,
    ]);
    WeightMeasurement::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-21',
        'weight_grams' => 71500,
        'updated_at' => $tiedTimestamp,
    ]);

    $registry->flushMemo();

    expect($registry->resolveProvider('weight'))->toBe('apple_health');
});

it('isolates multi-provider records in DashboardService, HealthMetricService, EnduranceHubService, and ActivityQueryService', function () {
    $registry = app(HealthSourceRegistry::class);

    // Seed Garmin + Oura/Withings/Apple Health across domains
    DailyMetric::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'steps' => 11200,
        'resting_hr' => 48,
    ]);
    DailyMetric::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-21',
        'steps' => 6400,
        'resting_hr' => 54,
    ]);

    SleepRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'sleep_seconds' => 25200,
        'sleep_score' => 74,
    ]);
    SleepRecord::factory()->create([
        'provider' => 'oura',
        'calendar_date' => '2026-11-21',
        'sleep_seconds' => 28800,
        'sleep_score' => 89,
    ]);

    HrvRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 62,
        'status' => 'BALANCED',
    ]);
    HrvRecord::factory()->create([
        'provider' => 'oura',
        'calendar_date' => '2026-11-21',
        'last_night_avg' => 81,
        'status' => 'BALANCED',
    ]);

    WeightMeasurement::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'weight_grams' => 75000,
    ]);
    WeightMeasurement::factory()->create([
        'provider' => 'withings',
        'calendar_date' => '2026-11-21',
        'weight_grams' => 70000,
    ]);

    WeightGoal::factory()->create([
        'provider' => 'garmin_personal',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'goal_grams' => 73000,
    ]);
    WeightGoal::factory()->create([
        'provider' => 'withings',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'goal_grams' => 68000,
    ]);

    Vo2MaxRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'sport' => 'running',
        'vo2_max_precise' => 54.0,
    ]);
    Vo2MaxRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-21',
        'sport' => 'running',
        'vo2_max_precise' => 59.5,
    ]);

    FitnessAgeRecord::factory()->create([
        'provider' => 'garmin_personal',
        'calendar_date' => '2026-11-21',
        'fitness_age' => 27.0,
    ]);
    FitnessAgeRecord::factory()->create([
        'provider' => 'apple_health',
        'calendar_date' => '2026-11-21',
        'fitness_age' => 31.0,
    ]);

    HeartRateZone::factory()->create([
        'provider' => 'garmin_personal',
        'sport' => 'RUNNING',
        'max_hr' => 190,
        'zone1_floor' => 110,
    ]);
    HeartRateZone::factory()->create([
        'provider' => 'polar',
        'sport' => 'RUNNING',
        'max_hr' => 198,
        'zone1_floor' => 120,
    ]);

    $run = Activity::factory()->create([
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'avg_power' => 280.0,
        'ftp' => 280.0,
    ]);

    // Explicitly select secondary providers per domain
    $registry->saveConfig([
        'daily_metrics' => 'apple_health',
        'sleep' => 'oura',
        'hrv' => 'oura',
        'weight' => 'withings',
        'vo2_max' => 'apple_health',
        'fitness_age' => 'apple_health',
        'heart_rate_zones' => 'polar',
    ]);

    $dashboard = app(DashboardService::class)->forDate('2026-11-21');
    $stepsMetric = app(HealthMetricService::class)->forMetric('steps', '7d');
    $sleepMetric = app(HealthMetricService::class)->forMetric('sleep', '7d');

    app(SportRegistry::class)->flushMemo();
    $runningHub = app(EnduranceHubService::class)->hub('road_running');
    $activityDetail = app(ActivityQueryService::class)->detail($run);

    expect($dashboard['atAGlance']['steps']['count'])->toBe(6400)
        ->and($dashboard['sleep']['score'])->toBe(89)
        ->and($dashboard['sleep']['totalSeconds'])->toBe(28800)
        ->and($dashboard['hrv']['lastNightAvg'])->toBe(81)
        ->and($dashboard['atAGlance']['weight']['weightKg'])->toBe(70.0)
        ->and($dashboard['atAGlance']['weight']['goalKg'])->toBe(68.0)
        ->and($dashboard['atAGlance']['vo2Fitness']['runningVo2'])->toBe(59.5)
        ->and($dashboard['atAGlance']['vo2Fitness']['fitnessAge'])->toBe(31.0)
        ->and($stepsMetric['points'])->toHaveCount(1)
        ->and($stepsMetric['points'][0]['value'])->toBe(6400)
        ->and($sleepMetric['points'])->toHaveCount(1)
        ->and($sleepMetric['points'][0]['score'])->toBe(89)
        ->and($runningHub['summary']['latest_vo2_max'])->toBe(59.5)
        ->and($activityDetail['activity']['hr_max_configured'])->toBe(198);

    // Explicit override to a provider with 0 rows excludes garmin_personal rows
    $registry->saveConfig([
        'sleep' => 'whoop',
    ]);

    $dashboardNoWhoop = app(DashboardService::class)->forDate('2026-11-21');
    expect($dashboardNoWhoop['sleep']['score'])->toBeNull()
        ->and($dashboardNoWhoop['sleep']['trend7d'])->toBe([]);
});

it('exposes health sources on settings and persists updates via PATCH /settings/health-sources', function () {
    SleepRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-20']);
    SleepRecord::factory()->create(['provider' => 'oura', 'calendar_date' => '2026-11-21']);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('health_sources.has_multiple_providers', true)
            ->has('health_sources.domains', 7),
        );

    $payload = [
        'sources' => [
            'daily_metrics' => 'auto',
            'sleep' => 'garmin_personal',
            'hrv' => 'auto',
            'weight' => 'auto',
            'vo2_max' => 'auto',
            'fitness_age' => 'auto',
            'heart_rate_zones' => 'auto',
        ],
    ];

    $this->from(route('settings.index'))
        ->patch(route('settings.health-sources.update'), $payload)
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('status', 'Health data source preferences updated.');

    $registry = app(HealthSourceRegistry::class);
    $registry->flushMemo();

    expect($registry->configuredSelection('sleep'))->toBe('garmin_personal')
        ->and($registry->resolveProvider('sleep'))->toBe('garmin_personal')
        ->and($registry->configuredSelection('hrv'))->toBe('auto');

    // Validation rejects invalid provider slugs or missing domains
    $this->from(route('settings.index'))
        ->patch(route('settings.health-sources.update'), [
            'sources' => [
                'sleep' => 'INVALID PROVIDER!',
            ],
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors(['sources.sleep', 'sources.daily_metrics']);

    // Validation rejects well-formed provider IDs that have no data in the domain
    $this->from(route('settings.index'))
        ->patch(route('settings.health-sources.update'), [
            'sources' => [...$payload['sources'], 'hrv' => 'whoop'],
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors(['sources.hrv']);

    $registry->flushMemo();

    expect($registry->configuredSelection('hrv'))->toBe('auto');
});

it('keeps older history from other providers in auto mode and only prefers the resolved provider on overlapping dates', function () {
    $registry = app(HealthSourceRegistry::class);

    SleepRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-18', 'sleep_score' => 70]);
    SleepRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-19', 'sleep_score' => 72]);
    SleepRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-20', 'sleep_score' => 74]);
    SleepRecord::factory()->create(['provider' => 'whoop', 'calendar_date' => '2026-11-19', 'sleep_score' => 60]);
    SleepRecord::factory()->create(['provider' => 'whoop', 'calendar_date' => '2026-11-17', 'sleep_score' => 61]);
    SleepRecord::factory()->create(['provider' => 'oura', 'calendar_date' => '2026-11-20', 'sleep_score' => 88]);
    SleepRecord::factory()->create(['provider' => 'oura', 'calendar_date' => '2026-11-21', 'sleep_score' => 90]);

    $rows = $registry->applyScope(SleepRecord::query(), 'sleep')
        ->orderBy('calendar_date')
        ->get(['calendar_date', 'provider', 'sleep_score']);

    expect($registry->resolveProvider('sleep'))->toBe('oura')
        ->and($rows->map(fn (SleepRecord $record): array => [$record->calendar_date, $record->provider])->all())->toBe([
            ['2026-11-17', 'whoop'],
            ['2026-11-18', 'garmin_personal'],
            ['2026-11-19', 'garmin_personal'],
            ['2026-11-20', 'oura'],
            ['2026-11-21', 'oura'],
        ]);

    expect(app(DashboardService::class)->forDate('2026-11-18')['sleep']['score'])->toBe(70)
        ->and(app(DashboardService::class)->forDate('2026-11-20')['sleep']['score'])->toBe(88);
});

it('matches auto-mode fallbacks on sport as well as date for VO2 max and heart rate zones', function () {
    $registry = app(HealthSourceRegistry::class);

    Vo2MaxRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-20', 'sport' => 'running', 'vo2_max_precise' => 54.0]);
    Vo2MaxRecord::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-20', 'sport' => 'cycling', 'vo2_max_precise' => 52.0]);
    Vo2MaxRecord::factory()->create(['provider' => 'apple_health', 'calendar_date' => '2026-11-21', 'sport' => 'running', 'vo2_max_precise' => 55.0]);
    Vo2MaxRecord::factory()->create(['provider' => 'apple_health', 'calendar_date' => '2026-11-20', 'sport' => 'running', 'vo2_max_precise' => 56.0]);

    HeartRateZone::factory()->create(['provider' => 'garmin_personal', 'sport' => 'DEFAULT', 'max_hr' => 190]);
    HeartRateZone::factory()->create(['provider' => 'garmin_personal', 'sport' => 'RUNNING', 'max_hr' => 192, 'updated_at' => '2026-11-01 00:00:00']);
    HeartRateZone::factory()->create(['provider' => 'polar', 'sport' => 'RUNNING', 'max_hr' => 198, 'updated_at' => '2026-11-21 00:00:00']);

    $vo2 = $registry->applyScope(Vo2MaxRecord::query(), 'vo2_max')
        ->orderBy('calendar_date')
        ->orderBy('sport')
        ->get(['calendar_date', 'sport', 'provider'])
        ->map(fn (Vo2MaxRecord $record): array => [$record->calendar_date, $record->sport, $record->provider])
        ->all();

    $zones = $registry->applyScope(HeartRateZone::query(), 'heart_rate_zones')
        ->orderBy('sport')
        ->pluck('max_hr', 'sport')
        ->all();

    expect($vo2)->toBe([
        ['2026-11-20', 'cycling', 'garmin_personal'],
        ['2026-11-20', 'running', 'apple_health'],
        ['2026-11-21', 'running', 'apple_health'],
    ])->and($zones)->toBe(['DEFAULT' => 190, 'RUNNING' => 198]);
});

it('resolves weight goals independently so a scale-only provider cannot hide them', function () {
    WeightMeasurement::factory()->create(['provider' => 'garmin_personal', 'calendar_date' => '2026-11-10', 'weight_grams' => 75000]);
    WeightMeasurement::factory()->create(['provider' => 'withings', 'calendar_date' => '2026-11-21', 'weight_grams' => 70000]);
    WeightGoal::factory()->create(['provider' => 'garmin_personal', 'start_date' => '2026-01-01', 'goal_grams' => 73000]);

    $registry = app(HealthSourceRegistry::class);

    expect($registry->resolveProvider('weight'))->toBe('withings');

    $autoWeight = app(DashboardService::class)->forDate('2026-11-21')['atAGlance']['weight'];

    expect($autoWeight['weightKg'])->toBe(70.0)
        ->and($autoWeight['goalKg'])->toBe(73.0);

    // An explicit provider still strictly filters measurements, but goals fall back when it has none
    $registry->saveConfig(['weight' => 'withings']);

    $explicitWeight = app(DashboardService::class)->forDate('2026-11-15')['atAGlance']['weight'];

    expect($explicitWeight['weightKg'])->toBeNull()
        ->and($explicitWeight['goalKg'])->toBe(73.0);

    // When the explicit provider does have goals, only its goals are used
    WeightGoal::factory()->create(['provider' => 'withings', 'start_date' => '2025-06-01', 'goal_grams' => 68000]);
    $registry->flushMemo();

    expect(app(DashboardService::class)->forDate('2026-11-21')['atAGlance']['weight']['goalKg'])->toBe(68.0);
});

it('rejects scoping a table that does not belong to the health domain', function () {
    app(HealthSourceRegistry::class)->applyScope(SleepRecord::query(), 'hrv');
})->throws(InvalidArgumentException::class);
