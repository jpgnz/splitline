<?php

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use App\Services\Activities\ActivityDerivedMetricsService;
use App\Services\Activities\ActivityQueryService;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Services\Sports\EnduranceHubService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Splitline\Core\Data\ActivityStreamData;

/**
 * Build a uniform synthetic telemetry series spanning $durationSeconds with $pointCount points.
 *
 * @param  callable(int, float): array{hr: int, speed: ?float, gap_speed?: ?float, power?: ?int}  $builder
 * @return list<array<string, float|int|null>>
 */
function buildDerivedSeries(int $pointCount, float $durationSeconds, callable $builder): array
{
    $series = [];
    $step = $pointCount > 1 ? $durationSeconds / ($pointCount - 1) : 0.0;

    for ($i = 0; $i < $pointCount; $i++) {
        $time = round($i * $step, 2);
        $metrics = $builder($i, $time);

        $series[] = [
            'time' => $time,
            'distance' => round($i * 40.0, 1),
            'altitude' => 25.0,
            'speed' => $metrics['speed'],
            'hr' => $metrics['hr'],
            'cadence' => 176,
            'power' => $metrics['power'] ?? null,
            'grade' => 0.0,
            'lat' => null,
            'lng' => null,
            'gap_speed' => $metrics['gap_speed'] ?? null,
        ];
    }

    return $series;
}

it('selects power when present on at least 80% of active points and computes Pw:Hr decoupling', function () {
    $activity = Activity::factory()->roadBiking()->create();

    // 121 points over 2400s (40m), 20s step:
    // First half: 200 W / 140 bpm => EF1 = 1.42857
    // Second half: 200 W / 150 bpm => EF2 = 1.33333
    // Decoupling = ((1.42857 - 1.33333) / 1.42857) * 100 = 6.67% (moderate_drift)
    $series = buildDerivedSeries(121, 2400.0, static fn (int $i, float $t): array => [
        'hr' => $t <= 1200.0 ? 140 : 150,
        'speed' => 8.5,
        'gap_speed' => 8.5,
        'power' => 200,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('power')
        ->and($metric->decoupling_pct)->toBe(6.67)
        ->and($metric->decoupling_rating)->toBe('moderate_drift')
        ->and($metric->first_half_ef)->toBe(1.429)
        ->and($metric->second_half_ef)->toBe(1.333)
        ->and($metric->efficiency_factor)->toBe(1.38);
});

it('keeps zero-power coasting samples in power coverage and Normalized Power', function () {
    $activity = Activity::factory()->roadBiking()->create();

    // 2400 x 1s samples; every 30s block has 10s coasting at 0 W and 20s at 300 W while still rolling.
    // Every 30s rolling window averages 200 W, so NP = 200 W in both halves (not 300 W from dropping zeros).
    $series = buildDerivedSeries(2400, 2399.0, static fn (int $i, float $t): array => [
        'hr' => $i < 1200 ? 140 : 150,
        'speed' => 9.0,
        'power' => ($i % 30) < 10 ? 0 : 300,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('power')
        ->and($metric->first_half_ef)->toBe(1.429)
        ->and($metric->second_half_ef)->toBe(1.333)
        ->and($metric->efficiency_factor)->toBe(1.379)
        ->and($metric->decoupling_pct)->toBe(6.67);
});

it('uses Normalized Power rather than average power for cycling EF', function () {
    $activity = Activity::factory()->roadBiking()->create();

    // Alternating 30s at 300 W and 30s at 100 W averages 200 W (EF 1.429), but the 30s rolling average sweeps
    // linearly between 100 and 300 W, giving NP ~222 W => EF = 222 / 140 = 1.585.
    $series = buildDerivedSeries(2400, 2399.0, static fn (int $i, float $t): array => [
        'hr' => 140,
        'speed' => 9.0,
        'power' => ($i % 60) < 30 ? 300 : 100,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('power')
        ->and($metric->efficiency_factor)->toBe(1.585);
});

it('excludes auto-pause gaps from active time when splitting halves', function () {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    // First block: 1211 samples at 1 Hz with ten 45s auto-pause gaps => 1200 active seconds at 140 bpm.
    // Second block: 1200 active seconds at 150 bpm. Excluding pauses puts the midpoint on the block boundary.
    $series = [];
    $time = 0.0;

    for ($i = 0; $i < 1211; $i++) {
        if ($i > 0 && $i % 100 === 0 && $i <= 1000) {
            $time += 45.0;
        }

        $series[] = ['time' => $time, 'hr' => 140, 'speed' => 3.0];
        $time += 1.0;
    }

    for ($i = 0; $i < 1200; $i++) {
        $series[] = ['time' => $time, 'hr' => 150, 'speed' => 3.0];
        $time += 1.0;
    }

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('speed')
        ->and($metric->first_half_ef)->toBe(1.286)
        ->and($metric->second_half_ef)->toBe(1.2)
        ->and($metric->decoupling_pct)->toBe(6.67);
});

it('ignores samples without heart rate', function () {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    // Every third sample drops HR; the remaining samples still give a clean 140 -> 150 bpm split.
    $series = buildDerivedSeries(2400, 2399.0, static fn (int $i, float $t): array => [
        'hr' => $i % 3 === 0 ? null : ($i <= 1200 ? 140 : 150),
        'speed' => 3.0,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->first_half_ef)->toBe(1.286)
        ->and($metric->second_half_ef)->toBe(1.2)
        ->and($metric->decoupling_pct)->toBe(6.67);
});

it('leaves decoupling and EF null when the stream has no heart rate', function () {
    $activity = Activity::factory()->roadBiking()->create();

    $series = buildDerivedSeries(2400, 2399.0, static fn (): array => [
        'hr' => null,
        'speed' => 9.0,
        'power' => 200,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_pct)->toBeNull()
        ->and($metric->decoupling_metric)->toBeNull()
        ->and($metric->efficiency_factor)->toBeNull();
});

it('prioritizes gap_speed over raw speed when power is absent', function () {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    // 101 points over 2000s (~33m):
    // First half: gap_speed = 3.5 m/s (210 m/min) / 140 bpm => EF1 = 1.500
    // Second half: gap_speed = 3.5 m/s (210 m/min) / 145 bpm => EF2 = 1.448
    // Decoupling = ((1.500 - 1.4482759) / 1.500) * 100 = 3.45% (well_coupled)
    $series = buildDerivedSeries(101, 2000.0, static fn (int $i, float $t): array => [
        'hr' => $t <= 1000.0 ? 140 : 145,
        'speed' => 3.2,
        'gap_speed' => 3.5,
        'power' => null,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('gap')
        ->and($metric->decoupling_pct)->toBe(3.45)
        ->and($metric->decoupling_rating)->toBe('well_coupled')
        ->and($metric->first_half_ef)->toBe(1.5)
        ->and($metric->second_half_ef)->toBe(1.448);
});

it('falls back to raw speed when power and gap_speed are below 80% coverage', function () {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    $series = buildDerivedSeries(101, 2000.0, static fn (int $i, float $t): array => [
        'hr' => $t <= 1000.0 ? 140 : 160,
        'speed' => 3.5,
        'gap_speed' => $i < 20 ? 3.6 : null,
        'power' => $i < 10 ? 250 : null,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_metric)->toBe('speed')
        ->and($metric->decoupling_pct)->toBe(12.5)
        ->and($metric->decoupling_rating)->toBe('high_drift');
});

it('classifies decoupling ratings across well-coupled, moderate, and high drift thresholds', function (int $secondHalfHr, float $expectedPct, string $expectedRating) {
    $activity = Activity::factory()->roadBiking()->create();

    $series = buildDerivedSeries(81, 1600.0, static fn (int $i, float $t): array => [
        'hr' => $t <= 800.0 ? 140 : $secondHalfHr,
        'speed' => 8.0,
        'power' => 200,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_pct)->toBe($expectedPct)
        ->and($metric->decoupling_rating)->toBe($expectedRating);
})->with([
    'well coupled under 5%' => [145, 3.45, 'well_coupled'],
    'moderate drift between 5% and 10%' => [152, 7.89, 'moderate_drift'],
    'high drift above 10%' => [165, 15.15, 'high_drift'],
]);

it('leaves decoupling null under 20 active minutes while still computing pacing', function () {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    // Only 900s (15 min) active duration -> decoupling must be null
    $series = buildDerivedSeries(91, 900.0, static fn (): array => [
        'hr' => 148,
        'speed' => 3.5,
        'gap_speed' => 3.5,
    ]);

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => $series,
        'km_splits' => [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 300.0, 'avg_speed' => 3.333, 'pace_seconds_per_km' => 300.0],
            ['km' => 2, 'distance_meters' => 1000.0, 'duration_seconds' => 290.0, 'avg_speed' => 3.448, 'pace_seconds_per_km' => 290.0],
            ['km' => 3, 'distance_meters' => 250.0, 'duration_seconds' => 90.0, 'avg_speed' => 2.778, 'pace_seconds_per_km' => 360.0],
        ],
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->decoupling_pct)->toBeNull()
        ->and($metric->decoupling_metric)->toBeNull()
        ->and($metric->decoupling_rating)->toBeNull()
        ->and($metric->efficiency_factor)->toBeNull()
        ->and($metric->pacing_strategy)->toBe('negative_split')
        ->and($metric->split_ratio)->toBeGreaterThan(1.015);
});

it('classifies negative, even, and positive splits and calculates pace CV excluding partial final splits', function (array $pacesSecPerKm, string $expectedStrategy) {
    $activity = Activity::factory()->create(['sport_category' => 'running']);

    $splits = [];

    foreach ($pacesSecPerKm as $index => $pace) {
        $splits[] = [
            'km' => $index + 1,
            'distance_meters' => 1000.0,
            'duration_seconds' => (float) $pace,
            'avg_speed' => round(1000.0 / $pace, 4),
            'pace_seconds_per_km' => (float) $pace,
        ];
    }

    // Add a slow 200m cooldown partial split that must be excluded (< 400m)
    $splits[] = [
        'km' => count($pacesSecPerKm) + 1,
        'distance_meters' => 200.0,
        'duration_seconds' => 120.0,
        'avg_speed' => 1.667,
        'pace_seconds_per_km' => 600.0,
    ];

    $stream = ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [],
        'km_splits' => $splits,
        'polyline' => [],
    ]);

    $metric = app(ActivityDerivedMetricsService::class)->computeAndStore($activity, $stream);

    expect($metric->pacing_strategy)->toBe($expectedStrategy)
        ->and($metric->pace_cv_pct)->not->toBeNull();
})->with([
    'negative split (faster second half)' => [[310, 308, 290, 285], 'negative_split'],
    'even split (steady throughout)' => [[300, 301, 299, 300], 'even_split'],
    'positive split (faded second half)' => [[285, 290, 315, 325], 'positive_split'],
]);

it('computes derived metrics automatically when TelemetryPersistenceService upserts a stream and exposes them in ActivityQueryService detail', function () {
    config([
        'services.open_meteo.auto_sync' => false,
        'services.nominatim.auto_sync' => false,
    ]);

    $activity = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => true,
        'hr_zone_1_seconds' => 200.0,
        'hr_zone_2_seconds' => 1000.0,
        'hr_zone_3_seconds' => 300.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $series = buildDerivedSeries(91, 1800.0, static fn (int $i, float $t): array => [
        'hr' => $t <= 900.0 ? 142 : 146,
        'speed' => 3.4,
        'gap_speed' => 3.5,
    ]);

    $dto = new ActivityStreamData(
        records: [],
        series: $series,
        polyline: [],
        laps: [],
        kmSplits: [
            ['km' => 1, 'distance_meters' => 1000.0, 'duration_seconds' => 295.0, 'avg_speed' => 3.39, 'pace_seconds_per_km' => 295.0, 'gap_seconds_per_km' => null, 'avg_hr' => 142, 'avg_cadence' => 176, 'avg_power' => null, 'elevation_gain' => 5.0, 'elevation_loss' => 5.0, 'avg_grade' => 0.0],
            ['km' => 2, 'distance_meters' => 1000.0, 'duration_seconds' => 294.0, 'avg_speed' => 3.401, 'pace_seconds_per_km' => 294.0, 'gap_seconds_per_km' => null, 'avg_hr' => 145, 'avg_cadence' => 176, 'avg_power' => null, 'elevation_gain' => 5.0, 'elevation_loss' => 5.0, 'avg_grade' => 0.0],
        ],
    );

    app(TelemetryPersistenceService::class)->upsertStream($activity, $dto);

    $this->assertDatabaseHas('activity_derived_metrics', [
        'activity_id' => $activity->id,
        'decoupling_metric' => 'gap',
        'decoupling_rating' => 'well_coupled',
        'pacing_strategy' => 'even_split',
    ]);

    $detail = app(ActivityQueryService::class)->detail($activity->fresh());

    expect($detail['derivedMetrics'])->not->toBeNull()
        ->and($detail['derivedMetrics']['decoupling_metric'])->toBe('gap')
        ->and($detail['derivedMetrics']['decoupling_rating'])->toBe('well_coupled')
        ->and($detail['derivedMetrics']['efficiency_factor'])->toBeGreaterThan(1.0)
        ->and($detail['derivedMetrics']['pacing_strategy'])->toBe('even_split')
        ->and($detail['derivedMetrics']['hr_low_pct'])->toBe(80.0);
});

it('reads the polarized HR split from the activity zones so later summary updates are reflected', function () {
    $activity = Activity::factory()->create([
        'hr_zone_1_seconds' => 200.0,
        'hr_zone_2_seconds' => 1000.0,
        'hr_zone_3_seconds' => 300.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);
    ActivityDerivedMetric::factory()->create(['activity_id' => $activity->id]);

    $activity->update([
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 500.0,
        'hr_zone_3_seconds' => 250.0,
        'hr_zone_4_seconds' => 200.0,
        'hr_zone_5_seconds' => 50.0,
    ]);

    $detail = app(ActivityQueryService::class)->detail($activity->fresh());

    expect($detail['derivedMetrics']['hr_low_pct'])->toBe(50.0)
        ->and($detail['derivedMetrics']['hr_moderate_pct'])->toBe(25.0)
        ->and($detail['derivedMetrics']['hr_high_pct'])->toBe(25.0);
});

it('exposes aerobicDrift trend and summary on EnduranceHubService hub payload', function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'Pacific/Auckland'));

    $olderRun = Activity::factory()->create([
        'name' => 'Base Run April',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-04-15 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
    ]);
    ActivityDerivedMetric::factory()->create([
        'activity_id' => $olderRun->id,
        'decoupling_pct' => 6.4,
        'decoupling_metric' => 'gap',
        'decoupling_rating' => 'moderate_drift',
        'efficiency_factor' => 1.40,
        'first_half_ef' => 1.45,
        'second_half_ef' => 1.36,
    ]);

    $recentRun = Activity::factory()->create([
        'name' => 'Base Run November',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3400.0,
    ]);
    ActivityDerivedMetric::factory()->create([
        'activity_id' => $recentRun->id,
        'decoupling_pct' => 2.8,
        'decoupling_metric' => 'gap',
        'decoupling_rating' => 'well_coupled',
        'efficiency_factor' => 1.55,
        'first_half_ef' => 1.57,
        'second_half_ef' => 1.53,
    ]);

    // Older than 12 months -> excluded from 12m trend
    $ancientRun = Activity::factory()->create([
        'name' => 'Old Run 2024',
        'sport_category' => 'running',
        'sport_discipline' => 'road',
        'started_at_local' => CarbonImmutable::parse('2024-06-01 07:00:00'),
    ]);
    ActivityDerivedMetric::factory()->create([
        'activity_id' => $ancientRun->id,
        'decoupling_pct' => 12.0,
        'decoupling_metric' => 'gap',
        'decoupling_rating' => 'high_drift',
        'efficiency_factor' => 1.20,
    ]);

    app(SportRegistry::class)->flushMemo();

    $hub = app(EnduranceHubService::class)->hub('running');
    $drift = $hub['aerobicDrift'];

    expect($drift['count'])->toBe(2)
        ->and($drift['avg_decoupling_pct'])->toBe(4.6)
        ->and($drift['well_coupled_pct'])->toBe(50.0)
        ->and($drift['latest_ef'])->toBe(1.55)
        ->and($drift['latest_metric'])->toBe('gap')
        ->and($drift['ef_delta_90d'])->toBe(0.15)
        ->and($drift['points'])->toHaveCount(2)
        ->and($drift['points'][0]['activity_id'])->toBe($olderRun->id)
        ->and($drift['points'][1]['activity_id'])->toBe($recentRun->id);
});

it('limits aerobicDrift EF figures to the primary metric so power and speed EF are never mixed', function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'Pacific/Auckland'));

    $sessions = [
        ['2026-05-10 07:00:00', 'gap', 1.40, 4.0],
        ['2026-06-10 07:00:00', 'gap', 1.42, 6.0],
        ['2026-11-01 07:00:00', 'gap', 1.50, 3.0],
        ['2026-11-20 07:00:00', 'power', 2.10, 2.0],
    ];

    foreach ($sessions as [$startedAt, $metric, $ef, $decoupling]) {
        $activity = Activity::factory()->create([
            'sport_category' => 'running',
            'sport_discipline' => 'road',
            'started_at_local' => CarbonImmutable::parse($startedAt),
        ]);
        ActivityDerivedMetric::factory()->create([
            'activity_id' => $activity->id,
            'decoupling_metric' => $metric,
            'efficiency_factor' => $ef,
            'decoupling_pct' => $decoupling,
        ]);
    }

    app(SportRegistry::class)->flushMemo();

    $drift = app(EnduranceHubService::class)->hub('running')['aerobicDrift'];

    expect($drift['count'])->toBe(4)
        ->and($drift['avg_decoupling_pct'])->toBe(3.75)
        ->and($drift['well_coupled_pct'])->toBe(75.0)
        ->and($drift['primary_metric'])->toBe('gap')
        ->and($drift['latest_metric'])->toBe('gap')
        ->and($drift['latest_ef'])->toBe(1.5)
        ->and($drift['ef_delta_90d'])->toBe(0.09)
        ->and($drift['points'])->toHaveCount(4);
});
