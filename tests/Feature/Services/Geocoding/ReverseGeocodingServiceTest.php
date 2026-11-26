<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\RouteCluster;
use App\Services\Geocoding\ReverseGeocodingService;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Services\Routes\RouteMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Splitline\Core\Data\NormalizedActivity;

beforeEach(function () {
    Cache::flush();
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.base_url' => 'https://nominatim.openstreetmap.org',
        'services.nominatim.rate_limit_ms' => 1100,
    ]);
});

it('samples the furthest waypoint on loops instead of the home start street and upgrades generic cluster names', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    // Start at (-36.8600, 174.7700) [home], furthest point at (-36.8750, 174.7850) [Cornwall Park]
    $waypoints = [
        [-36.8600, 174.7700],
        [-36.8650, 174.7750],
        [-36.8750, 174.7850], // furthest from start
        [-36.8680, 174.7780],
        [-36.8601, 174.7701],
    ];

    $cluster = RouteCluster::factory()->create([
        'name' => 'Auckland 5.2 km Loop',
        'name_override' => null,
        'is_loop' => true,
        'distance_meters' => 5200.0,
        'start_lat' => -36.8600,
        'start_lng' => 174.7700,
        'end_lat' => -36.8601,
        'end_lng' => 174.7701,
        'waypoints' => $waypoints,
        'activity_count' => 3,
        'geocoded_at' => null,
    ]);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'osm_type' => 'way',
            'osm_id' => 555123,
            'category' => 'leisure',
            'type' => 'park',
            'name' => 'Cornwall Park',
            'address' => [
                'house_number' => '19',
                'leisure' => 'Cornwall Park',
                'road' => 'Puriri Drive',
                'suburb' => 'Greenlane',
                'city' => 'Auckland',
                'country' => 'New Zealand',
                'country_code' => 'nz',
            ],
        ]),
    ]);

    $enriched = app(ReverseGeocodingService::class)->enrichRouteCluster($cluster);

    expect($enriched)->toBeTrue();

    $cluster->refresh();

    expect($cluster->place_label)->toBe('Cornwall Park · Auckland')
        ->and($cluster->name)->toBe('Cornwall Park 5.2 km Loop')
        ->and($cluster->geocoded_at)->not->toBeNull();

    // Verify Nominatim was queried at the furthest waypoint (-36.8750, 174.7850), NOT home start (-36.8600, 174.7700)
    Http::assertSent(function (Request $request): bool {
        return abs((float) $request['lat'] - (-36.8750)) < 0.0001
            && abs((float) $request['lon'] - 174.7850) < 0.0001
            && (int) $request['zoom'] === 17;
    });
});

it('names point-to-point routes as Start → End using area tier lookups', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    $cluster = RouteCluster::factory()->create([
        'name' => 'Morning Run',
        'name_override' => null,
        'is_loop' => false,
        'distance_meters' => 8400.0,
        'start_lat' => -36.8550,
        'start_lng' => 174.7450,
        'end_lat' => -36.8480,
        'end_lng' => 174.8320,
        'waypoints' => [
            [-36.8550, 174.7450],
            [-36.8500, 174.7900],
            [-36.8480, 174.8320],
        ],
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::sequence()
            ->push([
                'category' => 'place',
                'type' => 'suburb',
                'address' => [
                    'suburb' => 'Ponsonby',
                    'city' => 'Auckland',
                ],
            ])
            ->push([
                'category' => 'place',
                'type' => 'suburb',
                'address' => [
                    'suburb' => 'Mission Bay',
                    'city' => 'Auckland',
                ],
            ]),
    ]);

    $enriched = app(ReverseGeocodingService::class)->enrichRouteCluster($cluster);

    expect($enriched)->toBeTrue();

    $cluster->refresh();

    expect($cluster->place_label)->toBe('Ponsonby → Mission Bay')
        ->and($cluster->name)->toBe('Ponsonby → Mission Bay (8.4 km)')
        ->and($cluster->geocoded_at)->not->toBeNull();
});

it('never includes house_number in labels even when Nominatim returns a building/house result', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'category' => 'building',
            'type' => 'house',
            'name' => '742',
            'address' => [
                'house_number' => '742',
                'road' => 'Evergreen Terrace',
                'suburb' => 'Springfield Heights',
                'city' => 'Springfield',
            ],
        ]),
    ]);

    $place = app(ReverseGeocodingService::class)->resolveFeature(-36.8700, 174.7800);

    expect($place)->not->toBeNull()
        ->and($place?->display_label)->toBe('Evergreen Terrace')
        ->and($place?->subtitle())->toBe('Evergreen Terrace · Springfield')
        ->and($place?->display_label)->not->toContain('742')
        ->and($place?->area_label)->not->toContain('742');
});

it('never overwrites name_override or custom non-generic activity titles while still populating place_label', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'category' => 'leisure',
            'type' => 'park',
            'name' => 'Auckland Domain',
            'address' => [
                'leisure' => 'Auckland Domain',
                'suburb' => 'Grafton',
                'city' => 'Auckland',
            ],
        ]),
    ]);

    // Case 1: Cluster with user name_override
    $overriddenCluster = RouteCluster::factory()->create([
        'name' => 'Auckland 5.0 km Loop',
        'name_override' => 'My Tuesday Tempo Loop',
        'is_loop' => true,
        'distance_meters' => 5000.0,
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    app(ReverseGeocodingService::class)->enrichRouteCluster($overriddenCluster);
    $overriddenCluster->refresh();

    expect($overriddenCluster->name_override)->toBe('My Tuesday Tempo Loop')
        ->and($overriddenCluster->name)->toBe('Auckland 5.0 km Loop')
        ->and($overriddenCluster->place_label)->toBe('Auckland Domain · Grafton');

    // Case 2: Cluster with custom non-generic activity title ("Waterfront Threshold")
    $customCluster = RouteCluster::factory()->create([
        'name' => 'Waterfront Threshold',
        'name_override' => null,
        'is_loop' => true,
        'distance_meters' => 5000.0,
        'activity_count' => 2,
        'geocoded_at' => null,
    ]);

    $activity = Activity::factory()->create([
        'name' => 'Waterfront Threshold',
        'location_name' => 'Auckland',
    ]);
    ActivityRouteFingerprint::factory()->create([
        'activity_id' => $activity->id,
        'route_cluster_id' => $customCluster->id,
        'is_unlinked' => false,
    ]);

    app(ReverseGeocodingService::class)->enrichRouteCluster($customCluster);
    $customCluster->refresh();

    expect($customCluster->name)->toBe('Waterfront Threshold')
        ->and($customCluster->place_label)->toBe('Auckland Domain · Grafton');
});

it('preserves geocoded place_label and upgraded auto-name when RouteMatchingService::refreshCluster runs', function () {
    $matcher = app(RouteMatchingService::class);

    $polyline = [];
    for ($i = 0; $i < 30; $i++) {
        $angle = ($i / 29) * 2.0 * M_PI;
        $polyline[] = [round(-36.85 + 0.0072 * sin($angle), 5), round(174.76 + 0.0072 * cos($angle), 5)];
    }

    $a1 = Activity::factory()->create([
        'name' => 'Morning Run',
        'location_name' => 'Auckland',
        'distance_meters' => 5000.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-01 07:00:00'),
    ]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => $polyline]);

    $a2 = Activity::factory()->create([
        'name' => 'Morning Run',
        'location_name' => 'Auckland',
        'distance_meters' => 5000.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-05 07:00:00'),
    ]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => $polyline]);

    $fp1 = $matcher->matchActivity($a1, $s1);
    $matcher->matchActivity($a2, $s2);

    $cluster = RouteCluster::query()->findOrFail($fp1?->route_cluster_id);
    $cluster->update([
        'name' => 'Cornwall Park 5.0 km Loop',
        'place_label' => 'Cornwall Park · Auckland',
        'geocoded_at' => CarbonImmutable::now('UTC'),
    ]);

    // 3rd generic attempt arrives and refreshes cluster
    $a3 = Activity::factory()->create([
        'name' => 'Morning Run',
        'location_name' => 'Auckland',
        'distance_meters' => 5000.0,
        'started_at_local' => CarbonImmutable::parse('2026-11-10 07:00:00'),
    ]);
    $s3 = ActivityStream::factory()->create(['activity_id' => $a3->id, 'polyline' => $polyline]);

    $matcher->matchActivity($a3, $s3);
    $cluster->refresh();

    expect($cluster->activity_count)->toBe(3)
        ->and($cluster->place_label)->toBe('Cornwall Park · Auckland')
        ->and($cluster->name)->toBe('Cornwall Park 5.0 km Loop');
});

it('fills missing location_name for outdoor activities without touching existing location_name', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'category' => 'place',
            'type' => 'suburb',
            'address' => [
                'suburb' => 'Takapuna',
                'city' => 'Auckland',
                'country' => 'New Zealand',
            ],
        ]),
    ]);

    $missingLocation = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => null,
        'start_lat' => -36.7880,
        'start_lng' => 174.7730,
    ]);

    $alreadyNamed = Activity::factory()->create([
        'is_indoor' => false,
        'location_name' => 'Wellington',
        'start_lat' => -41.2865,
        'start_lng' => 174.7762,
    ]);

    $service = app(ReverseGeocodingService::class);

    expect($service->enrichActivityLocation($missingLocation))->toBeTrue()
        ->and($missingLocation->fresh()?->location_name)->toBe('Takapuna, Auckland')
        ->and($service->enrichActivityLocation($alreadyNamed))->toBeFalse()
        ->and($alreadyNamed->fresh()?->location_name)->toBe('Wellington');

    Http::assertSentCount(1);
});

it('dispatches background geocode jobs automatically when auto_sync is enabled', function () {
    Queue::fake([GeocodeRouteClusterJob::class, GeocodeActivityLocationJob::class]);
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.auto_sync' => true,
    ]);

    // 1. New outdoor activity without location_name dispatches GeocodeActivityLocationJob
    $persistence = app(TelemetryPersistenceService::class);
    $activity = $persistence->upsertActivity(new NormalizedActivity(
        provider: 'file_import',
        externalId: 'fit-001',
        name: 'Morning Run',
        activityTypeKey: 'running',
        sportCategory: 'running',
        sportDiscipline: 'road',
        isIndoor: false,
        startedAtLocal: CarbonImmutable::parse('2026-11-20 07:00:00'),
        startedAtUtc: CarbonImmutable::parse('2026-11-19 18:00:00', 'UTC'),
        locationName: null,
        startLat: -36.8500,
        startLng: 174.7600,
        distanceMeters: 5000.0,
        durationSeconds: 1500.0,
    ));

    Queue::assertPushed(GeocodeActivityLocationJob::class, fn (GeocodeActivityLocationJob $job): bool => $job->activityId === $activity->id && $job->queue === 'geocode');

    // 2. Cluster reaching activity_count >= 2 dispatches GeocodeRouteClusterJob
    $matcher = app(RouteMatchingService::class);
    $polyline = [];
    for ($i = 0; $i < 30; $i++) {
        $angle = ($i / 29) * 2.0 * M_PI;
        $polyline[] = [round(-36.85 + 0.0072 * sin($angle), 5), round(174.76 + 0.0072 * cos($angle), 5)];
    }

    $a1 = Activity::factory()->create(['distance_meters' => 5000.0]);
    $s1 = ActivityStream::factory()->create(['activity_id' => $a1->id, 'polyline' => $polyline]);
    $a2 = Activity::factory()->create(['distance_meters' => 5010.0]);
    $s2 = ActivityStream::factory()->create(['activity_id' => $a2->id, 'polyline' => $polyline]);

    $fp1 = $matcher->matchActivity($a1, $s1);
    Queue::assertNotPushed(GeocodeRouteClusterJob::class);

    $matcher->matchActivity($a2, $s2);
    Queue::assertPushed(
        GeocodeRouteClusterJob::class,
        fn (GeocodeRouteClusterJob $job): bool => $job->routeClusterId === $fp1?->route_cluster_id && $job->queue === 'geocode',
    );
});
