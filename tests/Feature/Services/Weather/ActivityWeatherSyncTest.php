<?php

use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\SyncRun;
use App\Services\Weather\ActivityWeatherSyncService;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * Build a synthetic Open-Meteo hourly weather payload for a single location across the given UTC timestamps.
 *
 * @param  list<int>  $timestamps
 * @param  array<string, list<float|int|null>>  $overrides
 * @return array{hourly: array<string, list<float|int|null>>}
 */
function fakeOpenMeteoLocation(array $timestamps, array $overrides = []): array
{
    $count = count($timestamps);

    $defaults = [
        'time' => $timestamps,
        'temperature_2m' => array_fill(0, $count, 16.0),
        'relative_humidity_2m' => array_fill(0, $count, 70.0),
        'apparent_temperature' => array_fill(0, $count, 14.0),
        'dew_point_2m' => array_fill(0, $count, 10.5),
        'wind_speed_10m' => array_fill(0, $count, 14.0),
        'wind_gusts_10m' => array_fill(0, $count, 22.0),
        'wind_direction_10m' => array_fill(0, $count, 45.0),
        'weather_code' => array_fill(0, $count, 2),
        'precipitation' => array_fill(0, $count, 0.0),
        'cloud_cover' => array_fill(0, $count, 50.0),
        'surface_pressure' => array_fill(0, $count, 1015.0),
    ];

    return [
        'hourly' => array_merge($defaults, $overrides),
    ];
}

/**
 * Build a straight northward GNSS stream spanning the requested distance (m) and duration (s).
 *
 * @return list<array{time: float, distance: float, altitude: float, lat: float, lng: float}>
 */
function buildNorthwardGnssSeries(float $distanceMeters = 6000.0, float $durationSeconds = 1800.0, int $steps = 60): array
{
    $series = [];
    $latDeltaPerMeter = 1.0 / 111195.0;

    for ($i = 0; $i <= $steps; $i++) {
        $fraction = $i / $steps;
        $dist = $distanceMeters * $fraction;
        $series[] = [
            'time' => round($durationSeconds * $fraction, 1),
            'distance' => round($dist, 1),
            'altitude' => 40.0 + ($fraction * 25.0),
            'lat' => -36.8500 + ($dist * $latDeltaPerMeter),
            'lng' => 174.7633,
        ];
    }

    return $series;
}

it('selects forecast, historical_forecast, and archive endpoints by UTC date and falls back to archive on null coverage', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    Http::preventStrayRequests();

    $client = app(OpenMeteoClient::class);

    expect($client->selectEndpoint(CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')))->toBe('forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2023-06-15 08:00:00', 'UTC')))->toBe('historical_forecast')
        ->and($client->selectEndpoint(CarbonImmutable::parse('2019-08-10 08:00:00', 'UTC')))->toBe('archive');

    $tsRecent = [
        CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-11-20 07:00:00', 'UTC')->getTimestamp(),
    ];
    $ts2023 = [
        CarbonImmutable::parse('2023-06-15 08:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2023-06-15 09:00:00', 'UTC')->getTimestamp(),
    ];
    $ts2019 = [
        CarbonImmutable::parse('2019-08-10 08:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2019-08-10 09:00:00', 'UTC')->getTimestamp(),
    ];

    $calledUrls = [];

    Http::fake(function (Request $request) use ($tsRecent, $ts2023, $ts2019, &$calledUrls) {
        $calledUrls[] = $request->url();

        if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
            return Http::response(fakeOpenMeteoLocation($tsRecent), 200);
        }

        if (str_starts_with($request->url(), 'https://historical-forecast-api.open-meteo.com/v1/forecast')) {
            // Simulate missing coverage (all-null temperature and wind) to trigger automatic archive fallback
            return Http::response(fakeOpenMeteoLocation($ts2023, [
                'temperature_2m' => [null, null],
                'wind_speed_10m' => [null, null],
            ]), 200);
        }

        if (str_starts_with($request->url(), 'https://archive-api.open-meteo.com/v1/archive')) {
            $timestamps = str_contains($request->url(), '2023-06-15') ? $ts2023 : $ts2019;

            return Http::response(fakeOpenMeteoLocation($timestamps, [
                'temperature_2m' => [11.0, 13.0],
            ]), 200);
        }

        return Http::response('Not found', 404);
    });

    $service = app(ActivityWeatherSyncService::class);

    // 1. Recent activity -> forecast endpoint
    $recentActivity = Activity::factory()->create([
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        'duration_seconds' => 1800.0,
        'elapsed_duration_seconds' => 1800.0,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
    ]);
    $recentWeather = $service->sync($recentActivity);
    expect($recentWeather)->not->toBeNull()
        ->and($recentWeather->model_endpoint)->toBe('forecast');

    // 2. 2023 activity with null historical_forecast coverage -> falls back to archive endpoint
    $fallbackActivity = Activity::factory()->create([
        'started_at_utc' => CarbonImmutable::parse('2023-06-15 08:15:00', 'UTC'),
        'duration_seconds' => 1800.0,
        'elapsed_duration_seconds' => 1800.0,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
    ]);
    $fallbackWeather = $service->sync($fallbackActivity);
    expect($fallbackWeather)->not->toBeNull()
        ->and($fallbackWeather->model_endpoint)->toBe('archive')
        ->and($fallbackWeather->temperature_c)->toBe(12.0);

    // 3. 2019 activity -> directly hits archive endpoint
    $archiveActivity = Activity::factory()->create([
        'started_at_utc' => CarbonImmutable::parse('2019-08-10 08:15:00', 'UTC'),
        'duration_seconds' => 1800.0,
        'elapsed_duration_seconds' => 1800.0,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
    ]);
    $archiveWeather = $service->sync($archiveActivity);
    expect($archiveWeather)->not->toBeNull()
        ->and($archiveWeather->model_endpoint)->toBe('archive');

    expect($calledUrls)->toHaveCount(4);
});

it('handles batched route coordinates, interpolates scalars and wrapping wind vectors, applies Windy override, and computes route wind impact', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    Http::preventStrayRequests();

    $startUtc = CarbonImmutable::parse('2026-11-21 00:30:00', 'UTC');
    $activity = Activity::factory()->create([
        'started_at_utc' => $startUtc,
        'duration_seconds' => 1800.0,
        'elapsed_duration_seconds' => 1800.0,
        'distance_meters' => 6000.0,
        'start_lat' => -36.8500,
        'start_lng' => 174.7633,
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => buildNorthwardGnssSeries(6000.0, 1800.0, 60),
    ]);

    $hour0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC')->getTimestamp();
    $hour1 = CarbonImmutable::parse('2026-11-21 01:00:00', 'UTC')->getTimestamp();
    $hour2 = CarbonImmutable::parse('2026-11-21 02:00:00', 'UTC')->getTimestamp();

    $capturedQuery = null;

    Http::fake(function (Request $request) use ($hour0, $hour1, $hour2, &$capturedQuery) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $capturedQuery);
        $coordCount = count(explode(',', (string) ($capturedQuery['latitude'] ?? '')));

        $locations = [];

        for ($i = 0; $i < $coordCount; $i++) {
            // Wind wraps across North: 355 deg -> 5 deg at 24 km/h -> pure headwind when moving North!
            $locations[] = fakeOpenMeteoLocation([$hour0, $hour1, $hour2], [
                'temperature_2m' => [14.0, 18.0, 20.0],
                'apparent_temperature' => [10.0, 14.0, 16.0],
                'wind_speed_10m' => [24.0, 24.0, 24.0],
                'wind_gusts_10m' => [36.0, 38.0, 40.0],
                'wind_direction_10m' => [355.0, 5.0, 5.0],
                'weather_code' => [2, 2, 2],
            ]);
        }

        return Http::response($locations, 200);
    });

    $weather = app(ActivityWeatherSyncService::class)->sync($activity);

    expect($weather)->not->toBeNull()
        ->and($weather->provider)->toBe('open_meteo')
        ->and($weather->sampling_mode)->toBe('route')
        ->and($weather->waypoint_count)->toBeGreaterThanOrEqual(3)
        ->and($weather->wmo_code)->toBe(2)
        ->and($weather->condition)->toBe('Windy')
        ->and($weather->base_condition)->toBe('Partly Cloudy')
        ->and($weather->icon)->toBe('wind')
        ->and($weather->wind_direction_deg)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(5)
        ->and($weather->wind_direction_compass)->toBe('N')
        ->and($weather->wind_gusts_kmh)->toBe(38.0)
        ->and($weather->headwind_pct)->toBe(100.0)
        ->and($weather->crosswind_pct)->toBe(0.0)
        ->and($weather->tailwind_pct)->toBe(0.0)
        ->and($weather->avg_effective_wind_kmh)->toBeLessThan(-23.0)
        ->and($weather->samples)->toHaveCount($weather->waypoint_count);

    // Start time at 00:30 UTC pads start_date back by 1 hour to the previous UTC day
    expect($capturedQuery['start_date'] ?? null)->toBe('2026-11-20')
        ->and($capturedQuery['end_date'] ?? null)->toBe('2026-11-21')
        ->and($capturedQuery['timezone'] ?? null)->toBe('UTC')
        ->and($capturedQuery['timeformat'] ?? null)->toBe('unixtime');

    // First sample at 00:30 UTC linearly interpolates 14.0 & 18.0 -> 16.0 C and circularly interpolates 355 & 5 -> 0 deg
    expect($weather->samples[0]['temperature_c'])->toEqual(16.0)
        ->and($weather->samples[0]['wind_direction_deg'])->toBe(0)
        ->and($weather->samples[0]['wind_direction_compass'])->toBe('N');
});

it('skips indoor and coordinate-less activities without making HTTP requests', function () {
    Http::preventStrayRequests();
    Http::fake();

    $indoor = Activity::factory()->indoor()->create();
    $noGnss = Activity::factory()->create([
        'is_indoor' => false,
        'start_lat' => null,
        'start_lng' => null,
    ]);

    $service = app(ActivityWeatherSyncService::class);

    expect($service->isEligible($indoor))->toBeFalse()
        ->and($service->isEligible($noGnss))->toBeFalse()
        ->and($service->sync($indoor))->toBeNull()
        ->and($service->sync($noGnss))->toBeNull();

    Http::assertNothingSent();
});

it('upgrades start_point weather to route mode after a GNSS stream becomes available', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    Http::preventStrayRequests();

    $startUtc = CarbonImmutable::parse('2026-11-21 08:00:00', 'UTC');
    $hour0 = $startUtc->getTimestamp();
    $hour1 = $startUtc->addHour()->getTimestamp();
    $hour2 = $startUtc->addHours(2)->getTimestamp();

    $requestCount = 0;

    Http::fake(function (Request $request) use ($hour0, $hour1, $hour2, &$requestCount) {
        $requestCount++;
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $coords = explode(',', (string) ($query['latitude'] ?? ''));

        if (count($coords) === 1) {
            // Single-coordinate object response
            return Http::response(fakeOpenMeteoLocation([$hour0, $hour1, $hour2]), 200);
        }

        // Multi-coordinate batched array response
        $locations = array_map(
            static fn (): array => fakeOpenMeteoLocation([$hour0, $hour1, $hour2]),
            $coords,
        );

        return Http::response($locations, 200);
    });

    $activity = Activity::factory()->create([
        'started_at_utc' => $startUtc,
        'duration_seconds' => 5400.0, // 1.5h -> 3 start_point hourly timestamps at 1 coordinate
        'elapsed_duration_seconds' => 5400.0,
        'distance_meters' => 8000.0,
        'start_lat' => -36.8500,
        'start_lng' => 174.7633,
    ]);

    $service = app(ActivityWeatherSyncService::class);

    // Phase A: Before stream sync -> start_point mode (1 coordinate queried)
    $initial = $service->sync($activity);
    expect($initial)->not->toBeNull()
        ->and($initial->sampling_mode)->toBe('start_point')
        ->and($initial->waypoint_count)->toBe(3)
        ->and($initial->headwind_pct)->toBeNull()
        ->and($requestCount)->toBe(1);

    // Calling sync again without stream and without force reuses cached record (no HTTP call)
    $cached = $service->sync($activity);
    expect($cached?->id)->toBe($initial->id)
        ->and($requestCount)->toBe(1);

    // Phase B: GNSS stream arrives -> automatically upgrades start_point -> route even with force=false
    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => buildNorthwardGnssSeries(8000.0, 5400.0, 80),
    ]);
    $activity->unsetRelation('stream');

    $upgraded = $service->sync($activity);
    expect($upgraded)->not->toBeNull()
        ->and($upgraded->id)->toBe($initial->id)
        ->and($upgraded->sampling_mode)->toBe('route')
        ->and($upgraded->headwind_pct)->not->toBeNull()
        ->and($requestCount)->toBe(2);

    // Calling sync again once in route mode skips HTTP unless force=true
    $service->sync($activity);
    expect($requestCount)->toBe(2);

    $service->sync($activity, force: true);
    expect($requestCount)->toBe(3);
});

it('releases SyncActivityWeatherJob back to the queue on persistent HTTP 429 and safely skips missing activities', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config(['services.open_meteo.max_retries' => 0]);

    $activity = Activity::factory()->create([
        'started_at_utc' => CarbonImmutable::now('UTC')->subDay(),
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
    ]);

    Http::fake([
        'https://api.open-meteo.com/v1/forecast*' => Http::response(
            ['error' => true, 'reason' => 'Minutely API request limit exceeded.'],
            429,
            ['Retry-After' => '42'],
        ),
    ]);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(42);

    $job = (new SyncActivityWeatherJob($activity->id))->setJob($queueJob);
    $job->handle(app(ActivityWeatherSyncService::class));

    expect(ActivityWeather::query()->where('activity_id', $activity->id)->exists())->toBeFalse();

    // Missing activity ID exits quietly without hitting Open-Meteo
    (new SyncActivityWeatherJob(999999))->handle(app(ActivityWeatherSyncService::class));
});

it('supports weather:sync command flags (--limit, --activity, --force, --upgrade-only, --queue) and records SyncRun', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    Http::preventStrayRequests();

    $hour0 = CarbonImmutable::parse('2026-11-20 06:00:00', 'UTC')->getTimestamp();
    $hour1 = CarbonImmutable::parse('2026-11-20 07:00:00', 'UTC')->getTimestamp();
    $hour2 = CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC')->getTimestamp();

    Http::fake(function (Request $request) use ($hour0, $hour1, $hour2) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $coords = explode(',', (string) ($query['latitude'] ?? ''));

        if (count($coords) === 1) {
            return Http::response(fakeOpenMeteoLocation([$hour0, $hour1, $hour2]), 200);
        }

        return Http::response(
            array_map(static fn (): array => fakeOpenMeteoLocation([$hour0, $hour1, $hour2]), $coords),
            200,
        );
    });

    $newestOutdoor = Activity::factory()->create([
        'garmin_activity_id' => 700001,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        'start_lat' => -36.8500,
        'start_lng' => 174.7633,
    ]);

    $olderOutdoor = Activity::factory()->create([
        'garmin_activity_id' => 700002,
        'started_at_local' => CarbonImmutable::parse('2026-11-19 08:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        'start_lat' => -36.8500,
        'start_lng' => 174.7633,
    ]);

    $indoor = Activity::factory()->indoor()->create([
        'garmin_activity_id' => 700003,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 09:00:00'),
    ]);

    // Activity currently in start_point mode that now has a GNSS stream
    $upgradeCandidate = Activity::factory()->create([
        'garmin_activity_id' => 700004,
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        'started_at_utc' => CarbonImmutable::parse('2026-11-20 06:15:00', 'UTC'),
        'start_lat' => -36.8500,
        'start_lng' => 174.7633,
    ]);
    ActivityWeather::factory()->startPoint()->create([
        'activity_id' => $upgradeCandidate->id,
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $upgradeCandidate->id,
        'series' => buildNorthwardGnssSeries(6000.0, 1800.0, 60),
    ]);

    // 1. --limit=1 syncs only the newest unsynced outdoor activity and records a completed weather SyncRun
    $this->artisan('weather:sync', ['--limit' => 1])
        ->assertSuccessful();

    expect(ActivityWeather::query()->where('activity_id', $newestOutdoor->id)->exists())->toBeTrue()
        ->and(ActivityWeather::query()->where('activity_id', $olderOutdoor->id)->exists())->toBeFalse()
        ->and(ActivityWeather::query()->where('activity_id', $indoor->id)->exists())->toBeFalse();

    $syncRun = SyncRun::query()->where('type', 'weather')->latest('id')->first();
    expect($syncRun)->not->toBeNull()
        ->and($syncRun->status)->toBe('completed')
        ->and($syncRun->items_synced)->toBe(1)
        ->and($syncRun->finished_at)->not->toBeNull();

    // 2. --upgrade-only upgrades only the start_point activity that now has a GNSS stream
    $this->artisan('weather:sync', ['--upgrade-only' => true])
        ->assertSuccessful();

    expect(ActivityWeather::query()->where('activity_id', $upgradeCandidate->id)->first()?->sampling_mode)->toBe('route')
        ->and(ActivityWeather::query()->where('activity_id', $olderOutdoor->id)->exists())->toBeFalse();

    // 3. --activity by Garmin ID targets that specific activity
    $this->artisan('weather:sync', ['--activity' => '700002'])
        ->assertSuccessful();

    expect(ActivityWeather::query()->where('activity_id', $olderOutdoor->id)->exists())->toBeTrue();

    // 4. --force + --queue dispatches SyncActivityWeatherJob with force=true onto the weather queue
    Queue::fake([SyncActivityWeatherJob::class]);

    $this->artisan('weather:sync', ['--force' => true, '--limit' => 2, '--queue' => true])
        ->assertSuccessful();

    Queue::assertPushed(SyncActivityWeatherJob::class, 2);
    Queue::assertPushedOn(
        'weather',
        SyncActivityWeatherJob::class,
        fn (SyncActivityWeatherJob $job): bool => $job->activityId === $newestOutdoor->id && $job->force === true,
    );

    // 5. Validation errors for invalid --limit and unknown --activity
    $this->artisan('weather:sync', ['--limit' => '0'])->assertFailed();
    $this->artisan('weather:sync', ['--activity' => '99999999'])->assertFailed();
});

it('retries transient HTTP 5xx responses and records a failed weather SyncRun when Open-Meteo errors persist', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 12:00:00', 'UTC'));
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    $startUtc = CarbonImmutable::parse('2026-11-21 06:00:00', 'UTC');
    $hour0 = $startUtc->getTimestamp();
    $hour1 = $startUtc->addHour()->getTimestamp();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 700099,
        'started_at_utc' => $startUtc,
        'duration_seconds' => 1800.0,
        'elapsed_duration_seconds' => 1800.0,
        'start_lat' => -36.8485,
        'start_lng' => 174.7633,
    ]);

    Http::fakeSequence('https://api.open-meteo.com/v1/forecast*')
        ->push('Upstream timeout', 503)
        ->push(fakeOpenMeteoLocation([$hour0, $hour1]), 200)
        ->push('Bad request', 400);

    // First call recovers after transient 503
    $weather = app(ActivityWeatherSyncService::class)->sync($activity);
    expect($weather)->not->toBeNull()
        ->and($weather->model_endpoint)->toBe('forecast');

    // Forced command sync against persistent 400 records a failed SyncRun
    $this->artisan('weather:sync', ['--activity' => (string) $activity->id, '--force' => true])
        ->assertFailed();

    $failedRun = SyncRun::query()->where('type', 'weather')->latest('id')->first();
    expect($failedRun)->not->toBeNull()
        ->and($failedRun->status)->toBe('failed')
        ->and($failedRun->items_synced)->toBe(0)
        ->and($failedRun->error_message)->toContain('HTTP 400');
});
