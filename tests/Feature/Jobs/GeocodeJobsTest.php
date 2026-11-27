<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Services\Geocoding\NominatimClient;
use App\Services\Geocoding\ReverseGeocodingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.base_url' => 'https://nominatim.openstreetmap.org',
    ]);
});

it('releases geocode jobs for the provider cooldown without recording an attempt', function (Closure $makeJob, Closure $subject) {
    Http::preventStrayRequests();
    Cache::put(NominatimClient::CACHE_COOLDOWN_UNTIL_KEY, microtime(true) * 1000.0 + 45_000.0, 60);

    $model = $subject();
    $job = $makeJob($model->id)->withFakeQueueInteractions();

    $job->handle(app(ReverseGeocodingService::class));

    $job->assertReleased(delay: 45);
    expect($model->fresh()?->geocode_attempted_at)->toBeNull();
    Http::assertNothingSent();
})->with([
    'route cluster' => [
        fn (int $id) => new GeocodeRouteClusterJob($id),
        fn () => RouteCluster::factory()->create(['activity_count' => 2, 'geocoded_at' => null]),
    ],
    'activity location' => [
        fn (int $id) => new GeocodeActivityLocationJob($id),
        fn () => Activity::factory()->create([
            'is_indoor' => false,
            'location_name' => null,
            'start_lat' => -36.85,
            'start_lng' => 174.76,
        ]),
    ],
]);
