<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.base_url' => 'https://nominatim.openstreetmap.org',
        'services.nominatim.rate_limit_ms' => 1100,
    ]);
});

it('geocodes pending matched route clusters and activities inline via geocode:sync', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    $cluster = RouteCluster::factory()->create([
        'name' => 'Auckland 5.0 km Loop',
        'is_loop' => true,
        'distance_meters' => 5000.0,
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    // Singleton cluster (activity_count = 1) should be skipped
    $singleton = RouteCluster::factory()->create([
        'activity_count' => 1,
        'geocoded_at' => null,
    ]);

    $activity = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.8500,
        'start_lng' => 174.7600,
    ]);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::sequence()
            ->push([
                'category' => 'leisure',
                'type' => 'park',
                'name' => 'Myers Park',
                'address' => ['leisure' => 'Myers Park', 'city' => 'Auckland'],
            ])
            ->push([
                'category' => 'place',
                'type' => 'suburb',
                'address' => ['suburb' => 'Freemans Bay', 'city' => 'Auckland'],
            ]),
    ]);

    $this->artisan('geocode:sync', ['--limit' => 10])
        ->expectsOutputToContain('Geocoding sync completed.')
        ->assertSuccessful();

    expect($cluster->fresh()?->place_label)->toBe('Myers Park · Auckland')
        ->and($cluster->fresh()?->name)->toBe('Myers Park 5.0 km Loop')
        ->and($singleton->fresh()?->geocoded_at)->toBeNull()
        ->and($activity->fresh()?->location_name)->toBe('Freemans Bay, Auckland');
});

it('dispatches geocode jobs with --queue and performs a fast no-op when nothing is pending', function () {
    Queue::fake([GeocodeRouteClusterJob::class, GeocodeActivityLocationJob::class]);

    // Fast no-op when zero items pending
    $this->artisan('geocode:sync', ['--queue' => true])
        ->expectsOutputToContain('Nothing to do.')
        ->assertSuccessful();

    Queue::assertNothingPushed();

    // Create 1 pending matched cluster, 1 already geocoded cluster, and 1 activity missing location_name
    $pendingCluster = RouteCluster::factory()->create([
        'activity_count' => 3,
        'geocoded_at' => null,
    ]);
    RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => CarbonImmutable::now('UTC'),
    ]);
    $pendingActivity = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
    ]);

    $this->artisan('geocode:sync', ['--queue' => true, '--limit' => 50])
        ->expectsOutputToContain('Queued background geocoding for 1 route cluster(s) and 1 activity(ies).')
        ->assertSuccessful();

    Queue::assertPushed(GeocodeRouteClusterJob::class, fn (GeocodeRouteClusterJob $job): bool => $job->routeClusterId === $pendingCluster->id && $job->queue === 'geocode');
    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $pendingActivity->id && $job->queue === 'geocode');
});

it('exits cleanly without queuing or making requests when NOMINATIM_ENABLED is false', function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['services.nominatim.enabled' => false]);

    RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    $this->artisan('geocode:sync', ['--queue' => true])
        ->expectsOutputToContain('Nominatim geocoding is disabled')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('skips recently attempted items so unresolvable lookups cannot starve the batch', function () {
    Queue::fake([GeocodeRouteClusterJob::class, GeocodeActivityLocationJob::class]);
    config(['services.nominatim.retry_failed_days' => 7]);

    RouteCluster::factory()->count(2)->create([
        'activity_count' => 9,
        'geocoded_at' => null,
        'geocode_attempted_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
    $retryableCluster = RouteCluster::factory()->create([
        'activity_count' => 2,
        'geocoded_at' => null,
        'geocode_attempted_at' => CarbonImmutable::now('UTC')->subDays(8),
    ]);
    Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.5,
        'start_lng' => 175.1,
        'geocode_attempted_at' => CarbonImmutable::now('UTC')->subHour(),
        'started_at_local' => CarbonImmutable::parse('2026-11-25 07:00:00'),
    ]);
    $pendingActivity = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
    ]);

    $this->artisan('geocode:sync', ['--queue' => true, '--limit' => 2])
        ->expectsOutputToContain('Queued background geocoding for 1 route cluster(s) and 1 activity(ies).')
        ->assertSuccessful();

    Queue::assertPushed(GeocodeRouteClusterJob::class, 1);
    Queue::assertPushed(GeocodeRouteClusterJob::class, fn (GeocodeRouteClusterJob $job): bool => $job->routeClusterId === $retryableCluster->id);
    Queue::assertPushed(GeocodeActivityLocationJob::class, 1);
    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $pendingActivity->id);
});

it('never selects provider-supplied location names with --force', function () {
    Queue::fake([GeocodeRouteClusterJob::class, GeocodeActivityLocationJob::class]);

    Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => 'North Shore',
        'location_geocoded_at' => null,
        'start_lat' => -36.79,
        'start_lng' => 174.77,
    ]);
    $geocoded = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => 'Takapuna, Auckland',
        'location_geocoded_at' => CarbonImmutable::now('UTC')->subMonth(),
        'start_lat' => -36.79,
        'start_lng' => 174.77,
    ]);
    $missing = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'geocode_attempted_at' => CarbonImmutable::now('UTC')->subHour(),
        'start_lat' => -36.85,
        'start_lng' => 174.76,
    ]);

    $this->artisan('geocode:sync', ['--queue' => true, '--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(GeocodeActivityLocationJob::class, 2);
    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $geocoded->id && $job->force);
    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $missing->id && $job->force);
});
