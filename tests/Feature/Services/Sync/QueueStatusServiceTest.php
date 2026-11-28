<?php

use App\Jobs\ComputeDerivedMetricsJob;
use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Jobs\GeocodeSegmentJob;
use App\Jobs\MatchUnfingerprintedRoutesJob;
use App\Jobs\ScanActivitySegmentsJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Services\Sync\QueueStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('summarizes queued database jobs by queue and pending activity enrichments', function () {
    config([
        'queue.default' => 'database',
        'services.garmin.key' => 'test-garmin-key',
    ]);

    // Queued jobs across default, streams, and weather queues
    $now = CarbonImmutable::now()->getTimestamp();

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityWeatherJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => GeocodeRouteClusterJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    // 1. Outdoor non-manual activity awaiting streams, missing weather, AND missing location_name
    Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    // 2. Manual activity without streams — must NOT count as awaiting streams
    Activity::factory()->create([
        'is_manual' => true,
        'is_indoor' => true,
        'start_lat' => null,
        'start_lng' => null,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    // 3. Streamed outdoor activity with start_point weather (upgradeable -> counts as missing weather) and unfingerprinted route
    $streamedUpgradeable = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'distance_meters' => 5000.0,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => CarbonImmutable::now(),
    ]);

    $longPolyline = [];
    for ($i = 0; $i < 15; $i++) {
        $longPolyline[] = [-36.85 + ($i * 0.001), 174.76 + ($i * 0.001)];
    }

    ActivityStream::factory()->create([
        'activity_id' => $streamedUpgradeable->id,
        'polyline' => $longPolyline,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $streamedUpgradeable->id,
        'sampling_mode' => 'start_point',
    ]);

    // 4. Fully enriched outdoor activity (route-sampled weather + fingerprinted + segments scanned) — counts in none
    $fullyEnriched = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'distance_meters' => 8000.0,
        'start_lat' => -36.86,
        'start_lng' => 174.77,
        'streams_synced_at' => CarbonImmutable::now(),
        'segments_scanned_at' => CarbonImmutable::now(),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $fullyEnriched->id,
        'polyline' => $longPolyline,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $fullyEnriched->id,
        'sampling_mode' => 'route',
    ]);

    $geocodedCluster = RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => CarbonImmutable::now('UTC'),
    ]);

    ActivityRouteFingerprint::factory()->create([
        'activity_id' => $fullyEnriched->id,
        'route_cluster_id' => $geocodedCluster->id,
    ]);

    ActivityDerivedMetric::factory()->create([
        'activity_id' => $fullyEnriched->id,
    ]);

    // Non-GPS outdoor stream (empty polyline + null start coordinates) must NOT count as awaiting weather
    $nonGpsStreamed = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'start_lat' => null,
        'start_lng' => null,
        'streams_synced_at' => CarbonImmutable::now(),
        'segments_scanned_at' => CarbonImmutable::now(),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $nonGpsStreamed->id,
        'polyline' => [],
    ]);

    ActivityDerivedMetric::factory()->create([
        'activity_id' => $nonGpsStreamed->id,
    ]);

    // 5. Matched route cluster awaiting geocoding + segment awaiting geocoding
    RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    Segment::factory()->create([
        'effort_count' => 5,
        'detection_count' => 5,
        'geocoded_at' => null,
    ]);

    // Hidden climb candidate (< 5 efforts) should NOT count toward pending geocoding
    Segment::factory()->create([
        'effort_count' => 1,
        'detection_count' => 1,
        'geocoded_at' => null,
    ]);

    $summary = app(QueueStatusService::class)->summary();

    expect($summary)->toBe([
        'jobs' => [
            'default' => 1,
            'streams' => 2,
            'weather' => 1,
            'geocode' => 1,
            'total' => 5,
        ],
        'pending' => [
            'streams' => 1,
            'weather' => 2,
            'routes' => 1,
            'segments' => 1,
            'derived_metrics' => 1,
            'geocoding' => 3,
        ],
        'has_active_work' => true,
    ]);
});

it('returns zero queued job counts gracefully when queue driver is not database', function () {
    config(['queue.default' => 'sync']);

    $now = CarbonImmutable::now()->getTimestamp();
    DB::table('jobs')->insert([
        ['queue' => 'streams', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    $summary = app(QueueStatusService::class)->summary();

    expect($summary['jobs'])->toBe([
        'default' => 0,
        'streams' => 0,
        'weather' => 0,
        'geocode' => 0,
        'total' => 0,
    ])->and($summary['has_active_work'])->toBeFalse();
});

it('exposes only cheap queued-job counts on the sync status endpoint and shared props, and full pending counts on the settings page', function () {
    config([
        'queue.default' => 'database',
        'services.garmin.key' => 'test-garmin-key',
    ]);

    $now = CarbonImmutable::now()->getTimestamp();
    DB::table('jobs')->insert([
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityWeatherJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    $this->getJson(route('sync.status'))
        ->assertOk()
        ->assertJsonPath('enrichments.jobs.streams', 1)
        ->assertJsonPath('enrichments.jobs.weather', 1)
        ->assertJsonPath('enrichments.jobs.geocode', 0)
        ->assertJsonPath('enrichments.jobs.default', 0)
        ->assertJsonPath('enrichments.jobs.total', 2)
        ->assertJsonPath('enrichments.has_active_work', true)
        ->assertJsonMissingPath('enrichments.pending');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('enrichment_queue.jobs.streams', 1)
            ->where('enrichment_queue.jobs.weather', 1)
            ->where('enrichment_queue.jobs.geocode', 0)
            ->where('enrichment_queue.jobs.total', 2)
            ->where('enrichment_queue.pending.streams', 1)
            ->where('enrichment_queue.pending.weather', 1)
            ->where('enrichment_queue.pending.geocoding', 0)
            ->where('enrichment_queue.has_active_work', true)
            ->where('geocoding.host', 'nominatim.openstreetmap.org')
            ->where('sync.enrichments.jobs.total', 2)
            ->where('sync.enrichments.has_active_work', true)
            ->missing('sync.enrichments.pending'),
        );
});

it('queues all pending enrichment categories on demand via POST /sync/enrichments', function () {
    Queue::fake();
    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.open_meteo.enabled' => true,
        'services.nominatim.enabled' => true,
    ]);

    $awaitingStreams = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    $streamedPending = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'distance_meters' => 5000.0,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => CarbonImmutable::now(),
        'segments_scanned_at' => null,
    ]);

    $longPolyline = [];
    for ($i = 0; $i < 15; $i++) {
        $longPolyline[] = [-36.85 + ($i * 0.001), 174.76 + ($i * 0.001)];
    }

    ActivityStream::factory()->create([
        'activity_id' => $streamedPending->id,
        'polyline' => $longPolyline,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $streamedPending->id,
        'sampling_mode' => 'start_point',
    ]);

    $cluster = RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    $segment = Segment::factory()->create([
        'effort_count' => 5,
        'detection_count' => 5,
        'geocoded_at' => null,
    ]);

    $this->postJson(route('sync.enrichments'))
        ->assertStatus(202)
        ->assertJsonPath('queued', [
            'streams' => 1,
            'weather' => 2,
            'routes' => 1,
            'segments' => 1,
            'derived_metrics' => 1,
            'geocoding' => 3,
            'total' => 9,
            'already_queued' => 0,
        ]);

    Queue::assertPushed(SyncActivityStreamJob::class, fn (SyncActivityStreamJob $job): bool => $job->activityId === $awaitingStreams->id);
    Queue::assertPushed(SyncActivityWeatherJob::class, 2);
    Queue::assertPushed(MatchUnfingerprintedRoutesJob::class, fn (MatchUnfingerprintedRoutesJob $job): bool => $job->rebuild === false);
    Queue::assertPushed(ScanActivitySegmentsJob::class, fn (ScanActivitySegmentsJob $job): bool => $job->rebuild === false);
    Queue::assertPushed(ComputeDerivedMetricsJob::class, fn (ComputeDerivedMetricsJob $job): bool => $job->force === false);
    Queue::assertPushed(GeocodeRouteClusterJob::class, fn (GeocodeRouteClusterJob $job): bool => $job->routeClusterId === $cluster->id);
    Queue::assertPushed(GeocodeSegmentJob::class, fn (GeocodeSegmentJob $job): bool => $job->segmentId === $segment->id);
    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $awaitingStreams->id);
});

it('reports pending enrichments whose unique jobs are already queued instead of counting them as newly queued', function () {
    Queue::fake();
    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.open_meteo.enabled' => false,
        'services.nominatim.enabled' => false,
    ]);

    $queuedActivity = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    SyncActivityStreamJob::dispatch($queuedActivity->id);

    $this->postJson(route('sync.enrichments'))
        ->assertOk()
        ->assertJsonPath('queued.streams', 0)
        ->assertJsonPath('queued.total', 0)
        ->assertJsonPath('queued.already_queued', 1)
        ->assertJsonPath('message', 'Pending enrichments are already queued in the background.');

    Queue::assertPushed(SyncActivityStreamJob::class, 1);

    $newActivity = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    $this->postJson(route('sync.enrichments'))
        ->assertStatus(202)
        ->assertJsonPath('queued.streams', 1)
        ->assertJsonPath('queued.already_queued', 1)
        ->assertJsonPath('message', 'Queued pending enrichments: 1 FIT stream. 1 already queued.');

    Queue::assertPushed(SyncActivityStreamJob::class, fn (SyncActivityStreamJob $job): bool => $job->activityId === $newActivity->id);
    Queue::assertPushed(SyncActivityStreamJob::class, 2);
});

it('skips disabled external enrichment providers and reports zero pending counts for disabled providers', function () {
    Queue::fake();
    config([
        'services.garmin.key' => '',
        'services.open_meteo.enabled' => false,
        'services.nominatim.enabled' => false,
    ]);

    Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    $summary = app(QueueStatusService::class)->summary();

    expect($summary['pending'])->toBe([
        'streams' => 0,
        'weather' => 0,
        'routes' => 0,
        'segments' => 0,
        'derived_metrics' => 0,
        'geocoding' => 0,
    ]);

    $this->postJson(route('sync.enrichments'))
        ->assertOk()
        ->assertJsonPath('queued.total', 0)
        ->assertJsonPath('message', 'All activities are already enriched.');

    Queue::assertNothingPushed();

    $this->from(route('settings.index'))
        ->post(route('sync.enrichments'))
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('syncStatus', 'All activities are already enriched.');
});
