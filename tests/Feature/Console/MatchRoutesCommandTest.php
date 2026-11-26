<?php

use App\Jobs\MatchUnfingerprintedRoutesJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\RouteCluster;
use App\Services\Routes\RouteMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array{0: float, 1: float}>
 */
function commandLoopPolyline(float $latDrift = 0.0): array
{
    $coords = [];

    for ($i = 0; $i < 30; $i++) {
        $angle = ($i / 29) * 2.0 * M_PI;
        $coords[] = [
            round(-36.8600 + (0.007 * sin($angle)) + $latDrift, 5),
            round(174.7700 + (0.007 * cos($angle)), 5),
        ];
    }

    return $coords;
}

it('scans unfingerprinted activities and clusters repeated routes via routes:match', function () {
    $a1 = Activity::factory()->create([
        'name' => 'Cornwall Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5100.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => commandLoopPolyline()]);

    $a2 = Activity::factory()->create([
        'name' => 'Cornwall Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-04 07:00:00'),
        'distance_meters' => 5120.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => commandLoopPolyline(0.0001)]);

    $this->artisan('routes:match')
        ->expectsOutputToContain('Activities Scanned')
        ->expectsOutputToContain('Matched Routes (2+ attempts)')
        ->assertSuccessful();

    $cluster = RouteCluster::query()->matched()->first();

    expect($cluster)->not->toBeNull()
        ->and($cluster?->activity_count)->toBe(2);

    // Running again without --rebuild scans 0 new activities
    $this->artisan('routes:match')
        ->expectsOutputToContain('Activities Scanned')
        ->assertSuccessful();

    expect(RouteCluster::query()->count())->toBe(1);
});

it('rebuilds route clusters from scratch with --rebuild while preserving custom names', function () {
    $a1 = Activity::factory()->create([
        'name' => 'Waterfront 5K',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => commandLoopPolyline()]);

    $a2 = Activity::factory()->create([
        'name' => 'Waterfront 5K',
        'started_at_local' => CarbonImmutable::parse('2026-11-08 07:00:00'),
        'distance_meters' => 5050.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => commandLoopPolyline(0.00015)]);

    $this->artisan('routes:match')->assertSuccessful();

    $cluster = RouteCluster::query()->matched()->firstOrFail();
    $cluster->update(['name_override' => 'Custom Waterfront Loop']);

    $this->artisan('routes:match --rebuild --chunk=1')
        ->expectsOutputToContain('Route cluster rebuild completed.')
        ->assertSuccessful();

    $rebuilt = RouteCluster::query()->matched()->firstOrFail();

    expect($rebuilt->activity_count)->toBe(2)
        ->and($rebuilt->name_override)->toBe('Custom Waterfront Loop');
});

it('fails cleanly when an invalid --chunk option is provided', function () {
    $this->artisan('routes:match --chunk=0')
        ->expectsOutputToContain('The --chunk option must be a positive integer.')
        ->assertFailed();
});

it('dispatches MatchUnfingerprintedRoutesJob only when unfingerprinted activities exist with --queue', function () {
    Queue::fake();

    // Empty polyline arrays (e.g. non-GPS streams) should not trigger unnecessary queued jobs
    $noGps = Activity::factory()->create([
        'is_indoor' => false,
        'distance_meters' => 5000.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $noGps->id, 'polyline' => []]);

    $this->artisan('routes:match --queue')
        ->expectsOutputToContain('Nothing to queue.')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    $a1 = Activity::factory()->create([
        'name' => 'Upgrade Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => commandLoopPolyline()]);

    $a2 = Activity::factory()->create([
        'name' => 'Upgrade Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-03 07:00:00'),
        'distance_meters' => 5050.0,
    ]);
    ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => commandLoopPolyline(0.0001)]);

    $this->artisan('routes:match --queue --chunk=50')
        ->expectsOutputToContain('Queued background route matching for 2 un-fingerprinted activity(ies).')
        ->assertSuccessful();

    Queue::assertPushedOn(MatchUnfingerprintedRoutesJob::QUEUE, MatchUnfingerprintedRoutesJob::class, function (MatchUnfingerprintedRoutesJob $job): bool {
        return $job->rebuild === false && $job->chunkSize === 50;
    });

    // Running the job executes the scan and produces the matched route
    (new MatchUnfingerprintedRoutesJob(rebuild: false, chunkSize: 50))->handle(app(RouteMatchingService::class));

    expect(RouteCluster::query()->matched()->count())->toBe(1);
});
