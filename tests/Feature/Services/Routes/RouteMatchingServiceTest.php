<?php

use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\RouteCluster;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Services\Routes\RouteMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Splitline\Core\Data\ActivityStreamData;

/**
 * Generate a closed rectangular/circular loop polyline around Auckland (~5 km circumference).
 *
 * @return list<array{0: float, 1: float}>
 */
function buildLoopPolyline(
    float $centerLat = -36.8500,
    float $centerLng = 174.7600,
    float $radiusDeg = 0.0072,
    int $points = 40,
    float $latDrift = 0.0,
    float $lngDrift = 0.0,
    bool $reverse = false,
): array {
    $coords = [];

    for ($i = 0; $i < $points; $i++) {
        $fraction = $i / ($points - 1);
        $angle = ($reverse ? -$fraction : $fraction) * 2.0 * M_PI;
        $coords[] = [
            round($centerLat + ($radiusDeg * sin($angle)) + $latDrift, 5),
            round($centerLng + ($radiusDeg * cos($angle)) + $lngDrift, 5),
        ];
    }

    return $coords;
}

it('matches identical and slightly drifted outdoor loops into a single cluster and updates aggregates', function () {
    $matcher = app(RouteMatchingService::class);

    $first = Activity::factory()->create([
        'name' => 'Waterfront Loop',
        'sport_category' => 'running',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5020.0,
        'duration_seconds' => 1500.0,
        'elevation_gain' => 60.0,
    ]);
    $firstStream = ActivityStream::factory()->create([
        'activity_id' => $first->id,
        'polyline' => buildLoopPolyline(),
    ]);

    $second = Activity::factory()->create([
        'name' => 'Waterfront Loop',
        'sport_category' => 'running',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-08 07:00:00'),
        'distance_meters' => 5100.0,
        'duration_seconds' => 1440.0,
        'elevation_gain' => 80.0,
    ]);
    // ~22m GPS drift (0.0002 deg lat ~ 22m)
    $secondStream = ActivityStream::factory()->create([
        'activity_id' => $second->id,
        'polyline' => buildLoopPolyline(latDrift: 0.0002, lngDrift: 0.00015),
    ]);

    $fp1 = $matcher->matchActivity($first, $firstStream);
    $fp2 = $matcher->matchActivity($second, $secondStream);

    expect($fp1)->not->toBeNull()
        ->and($fp2)->not->toBeNull()
        ->and($fp1->route_cluster_id)->toBe($fp2->route_cluster_id)
        ->and($fp1->is_loop)->toBeTrue()
        ->and($fp1->waypoints)->toHaveCount(20)
        ->and($fp1->encoded_preview_polyline)->not->toBeEmpty();

    $cluster = RouteCluster::query()->findOrFail($fp1->route_cluster_id);

    expect($cluster->activity_count)->toBe(2)
        ->and($cluster->is_loop)->toBeTrue()
        ->and($cluster->distance_meters)->toBe(5060.0)
        ->and($cluster->elevation_gain)->toBe(70.0)
        ->and($cluster->name)->toBe('Waterfront Loop')
        ->and($cluster->last_activity_at?->format('Y-m-d'))->toBe('2026-11-08');
});

it('does not false-match different routes, different sports, large distance mismatches, or reverse direction loops', function () {
    $matcher = app(RouteMatchingService::class);

    $base = Activity::factory()->create([
        'name' => 'Domain Loop',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $baseStream = ActivityStream::factory()->create([
        'activity_id' => $base->id,
        'polyline' => buildLoopPolyline(),
    ]);

    // Same loop, different sport
    $bike = Activity::factory()->roadBiking()->create([
        'name' => 'Domain Bike Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-02 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $bikeStream = ActivityStream::factory()->create([
        'activity_id' => $bike->id,
        'polyline' => buildLoopPolyline(),
    ]);

    // Same loop, reverse direction
    $reverse = Activity::factory()->create([
        'name' => 'Domain Loop Reverse',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-03 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $reverseStream = ActivityStream::factory()->create([
        'activity_id' => $reverse->id,
        'polyline' => buildLoopPolyline(reverse: true),
    ]);

    // Same sport & shape, >12% distance difference
    $farther = Activity::factory()->create([
        'name' => 'Extended Domain Loop',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-04 07:00:00'),
        'distance_meters' => 6000.0,
    ]);
    $fartherStream = ActivityStream::factory()->create([
        'activity_id' => $farther->id,
        'polyline' => buildLoopPolyline(),
    ]);

    // Completely different location (~5 km away)
    $otherRoute = Activity::factory()->create([
        'name' => 'Mission Bay Loop',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $otherStream = ActivityStream::factory()->create([
        'activity_id' => $otherRoute->id,
        'polyline' => buildLoopPolyline(centerLat: -36.8500, centerLng: 174.8300),
    ]);

    $fpBase = $matcher->matchActivity($base, $baseStream);
    $fpBike = $matcher->matchActivity($bike, $bikeStream);
    $fpReverse = $matcher->matchActivity($reverse, $reverseStream);
    $fpFarther = $matcher->matchActivity($farther, $fartherStream);
    $fpOther = $matcher->matchActivity($otherRoute, $otherStream);

    $clusterIds = [
        $fpBase?->route_cluster_id,
        $fpBike?->route_cluster_id,
        $fpReverse?->route_cluster_id,
        $fpFarther?->route_cluster_id,
        $fpOther?->route_cluster_id,
    ];

    expect(array_unique($clusterIds))->toHaveCount(5)
        ->and(RouteCluster::query()->matched()->count())->toBe(0);
});

it('skips indoor activities, short activities under 500m, and polylines with fewer than 10 valid points', function () {
    $matcher = app(RouteMatchingService::class);

    $indoor = Activity::factory()->indoor()->create(['distance_meters' => 5000.0]);
    $indoorStream = ActivityStream::factory()->create([
        'activity_id' => $indoor->id,
        'polyline' => buildLoopPolyline(),
    ]);

    $short = Activity::factory()->create(['distance_meters' => 450.0]);
    $shortStream = ActivityStream::factory()->create([
        'activity_id' => $short->id,
        'polyline' => buildLoopPolyline(),
    ]);

    $sparse = Activity::factory()->create(['distance_meters' => 5000.0]);
    $sparseStream = ActivityStream::factory()->create([
        'activity_id' => $sparse->id,
        'polyline' => buildLoopPolyline(points: 9),
    ]);

    expect($matcher->matchActivity($indoor, $indoorStream))->toBeNull()
        ->and($matcher->matchActivity($short, $shortStream))->toBeNull()
        ->and($matcher->matchActivity($sparse, $sparseStream))->toBeNull()
        ->and(ActivityRouteFingerprint::query()->count())->toBe(0)
        ->and(RouteCluster::query()->count())->toBe(0);
});

it('unlinks an activity, prevents automatic re-matching, and deletes clusters when empty', function () {
    $matcher = app(RouteMatchingService::class);

    $a1 = Activity::factory()->create([
        'name' => 'Park Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => buildLoopPolyline()]);

    $a2 = Activity::factory()->create([
        'name' => 'Park Loop',
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 5050.0,
    ]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => buildLoopPolyline(latDrift: 0.0001)]);

    $fp1 = $matcher->matchActivity($a1, $s1);
    $matcher->matchActivity($a2, $s2);

    $cluster = RouteCluster::query()->findOrFail($fp1?->route_cluster_id);
    expect($cluster->activity_count)->toBe(2);

    expect($matcher->unlinkActivity($cluster, $a2))->toBeTrue();

    $cluster->refresh();
    $fp2 = $a2->routeFingerprint()->first();

    expect($cluster->activity_count)->toBe(1)
        ->and($fp2?->is_unlinked)->toBeTrue()
        ->and($fp2?->route_cluster_id)->toBeNull();

    // Subsequent matchActivity call must respect is_unlinked and not re-add to cluster
    $recheck = $matcher->matchActivity($a2, $s2);
    expect($recheck?->is_unlinked)->toBeTrue()
        ->and($recheck?->route_cluster_id)->toBeNull();

    // Unlinking the last remaining activity deletes the now-empty cluster
    expect($matcher->unlinkActivity($cluster, $a1))->toBeTrue();
    $this->assertModelMissing($cluster);
});

it('preserves custom name overrides and unlinked exclusions during rebuildAll', function () {
    $matcher = app(RouteMatchingService::class);

    $a1 = Activity::factory()->create([
        'name' => 'Morning Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => buildLoopPolyline()]);

    $a2 = Activity::factory()->create([
        'name' => 'Morning Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-03 07:00:00'),
        'distance_meters' => 5020.0,
    ]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => buildLoopPolyline(latDrift: 0.0001)]);

    $a3 = Activity::factory()->create([
        'name' => 'Morning Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
        'distance_meters' => 5040.0,
    ]);
    $s3 = ActivityStream::factory()->create(['activity_id' => $a3->id, 'polyline' => buildLoopPolyline(latDrift: 0.00015)]);

    $fp1 = $matcher->matchActivity($a1, $s1);
    $matcher->matchActivity($a2, $s2);
    $matcher->matchActivity($a3, $s3);

    $cluster = RouteCluster::query()->findOrFail($fp1?->route_cluster_id);
    $cluster->update(['name_override' => 'My Favorite 5K Loop']);
    $matcher->unlinkActivity($cluster, $a3);

    $summary = $matcher->rebuildAll(chunkSize: 2);

    expect($summary['scanned'])->toBe(3)
        ->and($summary['matched'])->toBe(2)
        ->and($summary['matched_routes'])->toBe(1);

    $rebuilt = RouteCluster::query()->matched()->firstOrFail();

    expect($rebuilt->activity_count)->toBe(2)
        ->and($rebuilt->name_override)->toBe('My Favorite 5K Loop')
        ->and($rebuilt->displayName())->toBe('My Favorite 5K Loop')
        ->and($a3->routeFingerprint()->first()?->is_unlinked)->toBeTrue()
        ->and($a3->routeFingerprint()->first()?->route_cluster_id)->toBeNull();
});

it('automatically matches outdoor activities when TelemetryPersistenceService upserts streams', function () {
    Queue::fake([SyncActivityWeatherJob::class]);

    $persistence = app(TelemetryPersistenceService::class);
    $polyline = buildLoopPolyline();

    $first = Activity::factory()->create([
        'name' => 'Harbour Tempo',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
        'distance_meters' => 5000.0,
    ]);
    $second = Activity::factory()->create([
        'name' => 'Harbour Tempo',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-15 07:00:00'),
        'distance_meters' => 5030.0,
    ]);

    $persistence->upsertStream($first, new ActivityStreamData(
        series: [],
        polyline: $polyline,
        laps: [],
        kmSplits: [],
        records: [],
    ));

    $persistence->upsertStream($second, new ActivityStreamData(
        series: [],
        polyline: buildLoopPolyline(latDrift: 0.0001),
        laps: [],
        kmSplits: [],
        records: [],
    ));

    $cluster = RouteCluster::query()->matched()->first();

    expect($cluster)->not->toBeNull()
        ->and($cluster?->activity_count)->toBe(2)
        ->and($first->routeFingerprint()->first()?->route_cluster_id)->toBe($cluster?->id)
        ->and($second->routeFingerprint()->first()?->route_cluster_id)->toBe($cluster?->id);
});
