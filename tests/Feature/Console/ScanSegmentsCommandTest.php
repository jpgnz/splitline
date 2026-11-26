<?php

use App\Jobs\GeocodeSegmentJob;
use App\Jobs\ScanActivitySegmentsJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\Segment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.nominatim.enabled' => false,
        'services.nominatim.auto_sync' => false,
    ]);
});

/**
 * @return list<array{time: float, distance: float, altitude: float, speed: float, lat: float, lng: float}>
 */
function commandClimbSeries(): array
{
    $series = [];
    $lat = -36.8800;
    $lng = 174.7600;

    for ($i = 0; $i <= 60; $i++) {
        $dist = $i * 25.0;
        $series[] = [
            'time' => $i * 5.0,
            'distance' => $dist,
            'altitude' => 50.0 + ($dist * 0.06),
            'speed' => 5.0,
            'lat' => round($lat + ($dist / 111320.0), 6),
            'lng' => $lng,
        ];
    }

    return $series;
}

it('scans unscanned activities inline via segments:scan and rebuilds cleanly with --rebuild', function () {
    $activity = Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 1500.0,
        'segments_scanned_at' => null,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => commandClimbSeries(),
    ]);

    $this->artisan('segments:scan')
        ->expectsOutputToContain('Segment & climb scan completed.')
        ->assertSuccessful();

    expect($activity->fresh()?->segments_scanned_at)->not->toBeNull()
        ->and(Segment::query()->climbs()->count())->toBe(1);

    $this->artisan('segments:scan', ['--rebuild' => true, '--chunk' => 10])
        ->expectsOutputToContain('Segment & climb rebuild completed.')
        ->assertSuccessful();

    expect(Segment::query()->climbs()->count())->toBe(1);
});

it('dispatches ScanActivitySegmentsJob with --queue and no-ops when zero activities are unscanned', function () {
    Queue::fake([ScanActivitySegmentsJob::class]);

    $this->artisan('segments:scan', ['--queue' => true])
        ->expectsOutputToContain('Nothing to queue.')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    $activity = Activity::factory()->roadBiking()->create([
        'segments_scanned_at' => null,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => commandClimbSeries(),
    ]);

    $this->artisan('segments:scan', ['--queue' => true, '--chunk' => 25])
        ->expectsOutputToContain('Queued background segment scanning for 1 unscanned activity(ies).')
        ->assertSuccessful();

    Queue::assertPushed(ScanActivitySegmentsJob::class, fn (ScanActivitySegmentsJob $job): bool => ! $job->rebuild && $job->chunkSize === 25 && $job->queue === 'streams');

    $this->artisan('segments:scan', ['--queue' => true, '--rebuild' => true])
        ->expectsOutputToContain('Queued background segment & climb rebuild.')
        ->assertSuccessful();

    Queue::assertPushed(ScanActivitySegmentsJob::class, fn (ScanActivitySegmentsJob $job): bool => $job->rebuild);
});

it('includes only visible un-geocoded segments in geocode:sync --queue', function () {
    Queue::fake([GeocodeSegmentJob::class]);
    config(['services.nominatim.enabled' => true]);

    $visibleSegment = Segment::factory()->climb('cat4')->create([
        'effort_count' => 5,
        'detection_count' => 5,
        'geocoded_at' => null,
    ]);

    // Below default min_efforts (5) threshold -> should NOT be queued for geocoding
    Segment::factory()->climb('cat4')->create([
        'effort_count' => 1,
        'detection_count' => 1,
        'geocoded_at' => null,
    ]);

    $this->artisan('geocode:sync', ['--queue' => true, '--limit' => 20])
        ->expectsOutputToContain('1 segment(s)')
        ->assertSuccessful();

    Queue::assertPushed(GeocodeSegmentJob::class, 1);
    Queue::assertPushed(GeocodeSegmentJob::class, fn (GeocodeSegmentJob $job): bool => $job->segmentId === $visibleSegment->id && $job->queue === 'geocode');
});
