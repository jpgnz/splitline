<?php

use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Support\MapConfig;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('disables both map tiles and terrain and clears external URLs when MAPS_ENABLED is false', function () {
    config([
        'services.maps.enabled' => false,
        'services.maps.tiles_enabled' => true,
        'services.maps.terrain_enabled' => true,
        'services.maps.style_light_url' => 'https://tiles.example.test/light.json',
        'services.maps.style_dark_url' => 'https://tiles.example.test/dark.json',
        'services.maps.terrain_tilejson_url' => 'https://dem.example.test/tilejson.json',
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('maps.tiles_enabled', false)
            ->where('maps.terrain_enabled', false)
            ->where('maps.style_light_url', null)
            ->where('maps.style_dark_url', null)
            ->where('maps.terrain_tilejson_url', null),
        );
});

it('supports granular map tile and terrain toggles, empty docker overrides, and self-hosted style URLs', function () {
    config([
        'services.maps.enabled' => true,
        'services.maps.tiles_enabled' => '',
        'services.maps.terrain_enabled' => 'false',
        'services.maps.style_light_url' => 'https://maps.internal.local/styles/light.json',
        'services.maps.style_dark_url' => 'https://maps.internal.local/styles/dark.json',
        'services.maps.terrain_tilejson_url' => '',
    ]);

    $maps = app(MapConfig::class);

    expect($maps->tilesEnabled())->toBeTrue()
        ->and($maps->terrainEnabled())->toBeFalse()
        ->and($maps->styleLightUrl())->toBe('https://maps.internal.local/styles/light.json')
        ->and($maps->styleDarkUrl())->toBe('https://maps.internal.local/styles/dark.json')
        ->and($maps->terrainTilejsonUrl())->toBeNull()
        ->and($maps->tilesHost())->toBe('maps.internal.local')
        ->and($maps->isPublicTilesHost())->toBeFalse();

    // Non-HTTP URL schemes fall back safely to built-in defaults
    config([
        'services.maps.tiles_enabled' => 'true',
        'services.maps.style_light_url' => 'javascript:alert(1)',
    ]);

    expect($maps->styleLightUrl())->toBe(MapConfig::DEFAULT_STYLE_LIGHT_URL);
});

it('exposes maps_config and weather status on the settings page for purely offline configurations', function () {
    config([
        'services.garmin.key' => '',
        'services.open_meteo.enabled' => false,
        'services.nominatim.enabled' => false,
        'services.maps.enabled' => false,
    ]);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('weatherEnabled', false)
            ->where('weather.enabled', false)
            ->where('geocoding.enabled', false)
            ->where('maps_config.enabled', false)
            ->where('maps_config.tiles_enabled', false)
            ->where('maps_config.terrain_enabled', false)
            ->where('maps.tiles_enabled', false)
            ->where('maps.terrain_enabled', false),
        );
});

it('skips garmin:sync cleanly without creating a failed SyncRun or making HTTP calls when GARMIN_KEY is empty', function () {
    Http::preventStrayRequests();
    config(['services.garmin.key' => '']);

    $this->artisan('garmin:sync', ['--days' => 1])
        ->expectsOutputToContain('GARMIN_KEY is empty')
        ->assertSuccessful();

    expect(SyncRun::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('skips weather:sync cleanly without queuing jobs, creating a SyncRun, or making HTTP calls when OPEN_METEO_ENABLED is false', function () {
    Http::preventStrayRequests();
    Queue::fake([SyncActivityWeatherJob::class]);
    config(['services.open_meteo.enabled' => false]);

    Activity::factory()->create([
        'is_indoor' => false,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
    ]);

    $this->artisan('weather:sync')
        ->expectsOutputToContain('OPEN_METEO_ENABLED=false')
        ->assertSuccessful();

    $this->artisan('weather:sync', ['--queue' => true])
        ->expectsOutputToContain('OPEN_METEO_ENABLED=false')
        ->assertSuccessful();

    expect(SyncRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('blocks manual weather sync requests and shares weatherEnabled=false when OPEN_METEO_ENABLED is false', function () {
    Http::preventStrayRequests();
    config(['services.open_meteo.enabled' => false]);

    $activity = Activity::factory()->create([
        'is_indoor' => false,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
    ]);

    $this->get(route('activities.show', $activity))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('weatherEnabled', false),
        );

    $this->from(route('activities.show', $activity))
        ->post(route('activities.weather.sync', $activity))
        ->assertRedirect(route('activities.show', $activity))
        ->assertSessionHas('status', 'Weather lookups are disabled (OPEN_METEO_ENABLED=false).');

    Http::assertNothingSent();
});

it('gates scheduled garmin:sync and geocode:sync commands when their services are unconfigured or disabled', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $garminEvent = $events->first(
        fn (ScheduledEvent $event): bool => is_string($event->command) && str_contains($event->command, 'garmin:sync'),
    );
    $geocodeEvent = $events->first(
        fn (ScheduledEvent $event): bool => is_string($event->command) && str_contains($event->command, 'geocode:sync'),
    );

    expect($garminEvent)->toBeInstanceOf(ScheduledEvent::class)
        ->and($geocodeEvent)->toBeInstanceOf(ScheduledEvent::class);

    config([
        'services.garmin.key' => '',
        'services.nominatim.enabled' => false,
    ]);

    expect($garminEvent->filtersPass($this->app))->toBeFalse()
        ->and($geocodeEvent->filtersPass($this->app))->toBeFalse();

    config([
        'services.garmin.key' => 'configured-key',
        'services.nominatim.enabled' => true,
    ]);

    expect($garminEvent->filtersPass($this->app))->toBeTrue()
        ->and($geocodeEvent->filtersPass($this->app))->toBeTrue();
});
