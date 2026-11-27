<?php

use App\Jobs\ComputeDerivedMetricsJob;
use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use App\Services\Activities\ActivityDerivedMetricsService;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array<string, float|int|null>>
 */
function commandDerivedSeries(int $secondHalfHr = 144): array
{
    $points = [];

    for ($i = 0; $i <= 80; $i++) {
        $time = $i * 20.0;
        $points[] = [
            'time' => $time,
            'distance' => $i * 70.0,
            'altitude' => 20.0,
            'speed' => 3.5,
            'gap_speed' => 3.5,
            'hr' => $time <= 800.0 ? 140 : $secondHalfHr,
            'cadence' => 176,
            'power' => null,
            'grade' => 0.0,
            'lat' => null,
            'lng' => null,
        ];
    }

    return $points;
}

it('backfills missing derived metrics inline and skips already computed activities unless --force is set', function () {
    $a1 = Activity::factory()->create(['name' => 'Morning Base Run']);
    ActivityStream::factory()->create([
        'activity_id' => $a1->id,
        'series' => commandDerivedSeries(144),
        'km_splits' => [],
        'polyline' => [],
    ]);

    $a2 = Activity::factory()->create(['name' => 'Tempo Run']);
    ActivityStream::factory()->create([
        'activity_id' => $a2->id,
        'series' => commandDerivedSeries(156),
        'km_splits' => [],
        'polyline' => [],
    ]);

    $this->artisan('activities:compute-derived-metrics --chunk=1')
        ->expectsOutputToContain('Activities Processed')
        ->assertSuccessful();

    expect(ActivityDerivedMetric::query()->count())->toBe(2)
        ->and(app(ActivityDerivedMetricsService::class)->missingCount())->toBe(0);

    // Running again without --force processes 0 rows
    $this->artisan('activities:compute-derived-metrics')
        ->expectsOutputToContain('Activities Processed')
        ->assertSuccessful();

    // Mutate stored decoupling, then run with --force to recompute
    ActivityDerivedMetric::query()->where('activity_id', $a1->id)->update(['decoupling_pct' => 99.0]);

    $this->artisan('activities:compute-derived-metrics --force')
        ->expectsOutputToContain('Derived metrics recomputation completed.')
        ->assertSuccessful();

    expect(ActivityDerivedMetric::query()->where('activity_id', $a1->id)->value('decoupling_pct'))->toBe(2.78);
});

it('computes derived metrics for a single activity with --activity', function () {
    $activity = Activity::factory()->create(['name' => 'Single Run']);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => commandDerivedSeries(145),
        'km_splits' => [],
        'polyline' => [],
    ]);

    $other = Activity::factory()->create(['name' => 'Untouched Run']);
    ActivityStream::factory()->create([
        'activity_id' => $other->id,
        'series' => commandDerivedSeries(145),
        'km_splits' => [],
        'polyline' => [],
    ]);

    $this->artisan("activities:compute-derived-metrics --activity={$activity->id}")
        ->expectsOutputToContain("Computed derived metrics for activity #{$activity->id}")
        ->assertSuccessful();

    $this->assertDatabaseHas('activity_derived_metrics', [
        'activity_id' => $activity->id,
        'decoupling_rating' => 'well_coupled',
    ]);
    $this->assertDatabaseMissing('activity_derived_metrics', [
        'activity_id' => $other->id,
    ]);
});

it('dispatches ComputeDerivedMetricsJob onto the streams queue only when missing or forced', function () {
    Queue::fake();

    // No streamed activities yet -> nothing to queue
    $this->artisan('activities:compute-derived-metrics --queue')
        ->expectsOutputToContain('Nothing to queue.')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    $activity = Activity::factory()->create(['name' => 'Queued Run']);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => commandDerivedSeries(145),
        'km_splits' => [],
        'polyline' => [],
    ]);

    $this->artisan('activities:compute-derived-metrics --queue --chunk=25')
        ->expectsOutputToContain('Queued derived metrics backfill for 1 activity(ies).')
        ->assertSuccessful();

    Queue::assertPushedOn(
        ComputeDerivedMetricsJob::QUEUE,
        ComputeDerivedMetricsJob::class,
        static fn (ComputeDerivedMetricsJob $job): bool => $job->force === false && $job->chunkSize === 25,
    );

    // Execute job directly and verify persistence
    (new ComputeDerivedMetricsJob(force: false, chunkSize: 25))->handle(app(ActivityDerivedMetricsService::class));

    $this->assertDatabaseHas('activity_derived_metrics', [
        'activity_id' => $activity->id,
        'decoupling_metric' => 'gap',
    ]);

    // Forced queue always dispatches even when missingCount === 0
    $this->artisan('activities:compute-derived-metrics --queue --force')
        ->expectsOutputToContain('Queued full derived metrics recomputation.')
        ->assertSuccessful();

    Queue::assertPushed(
        ComputeDerivedMetricsJob::class,
        static fn (ComputeDerivedMetricsJob $job): bool => $job->force === true,
    );
});

it('fails cleanly on invalid --chunk or unknown --activity options', function () {
    $this->artisan('activities:compute-derived-metrics --chunk=0')
        ->expectsOutputToContain('The --chunk option must be a positive integer.')
        ->assertFailed();

    $this->artisan('activities:compute-derived-metrics --activity=999999')
        ->expectsOutputToContain('Activity #999999 not found.')
        ->assertFailed();
});
