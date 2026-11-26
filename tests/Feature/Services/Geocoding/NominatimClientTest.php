<?php

use App\Models\GeocodedPlace;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitedException;
use App\Services\Geocoding\NominatimClient;
use App\Support\AppVersion;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    config([
        'app.version' => 'v1.2.3',
        'app.url' => 'https://splitline.example.test',
        'services.nominatim.enabled' => true,
        'services.nominatim.base_url' => 'https://nominatim.openstreetmap.org',
        'services.nominatim.rate_limit_ms' => 1100,
        'services.nominatim.user_agent' => null,
        'services.nominatim.referer' => null,
        'services.nominatim.email' => 'athlete@example.test',
        'services.nominatim.timeout' => 15,
        'services.nominatim.max_retries' => 2,
    ]);
});

it('sends policy-compliant User-Agent, Referer, Accept-Language, and email query params to Nominatim', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'osm_type' => 'way',
            'osm_id' => 987654,
            'category' => 'leisure',
            'type' => 'park',
            'name' => 'Cornwall Park',
            'address' => [
                'house_number' => '42',
                'leisure' => 'Cornwall Park',
                'road' => 'Green Lane West',
                'suburb' => 'Greenlane',
                'city' => 'Auckland',
                'region' => 'Auckland',
                'country' => 'New Zealand',
                'country_code' => 'nz',
            ],
        ]),
    ]);

    $place = app(NominatimClient::class)->reverse(-36.87321, 174.78342, GeocodedPlace::TIER_FEATURE);

    expect($place)->toBeInstanceOf(GeocodedPlace::class)
        ->and($place?->display_label)->toBe('Cornwall Park')
        ->and($place?->area_label)->toBe('Greenlane')
        ->and($place?->city)->toBe('Auckland')
        ->and($place?->raw_address)->not->toHaveKey('house_number');

    Http::assertSent(function (Request $request): bool {
        $ua = $request->header('User-Agent')[0] ?? '';
        $referer = $request->header('Referer')[0] ?? '';
        $lang = $request->header('Accept-Language')[0] ?? '';

        return str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/reverse')
            && $ua === 'Splitline/v1.2.3 (+https://github.com/splitline-php/splitline)'
            && $referer === 'https://splitline.example.test'
            && $lang === 'en'
            && $request['format'] === 'jsonv2'
            && (int) $request['zoom'] === 17
            && (int) $request['addressdetails'] === 1
            && (int) $request['namedetails'] === 1
            && $request['email'] === 'athlete@example.test';
    });
});

it('caches area tier (~220m) and feature tier (~25m) separately and avoids collisions for roads 80m apart', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::sequence()
            ->push([
                'osm_type' => 'relation',
                'osm_id' => 101,
                'category' => 'place',
                'type' => 'suburb',
                'name' => 'Ponsonby',
                'address' => [
                    'suburb' => 'Ponsonby',
                    'city' => 'Auckland',
                    'country' => 'New Zealand',
                    'country_code' => 'nz',
                ],
            ])
            ->push([
                'osm_type' => 'way',
                'osm_id' => 202,
                'category' => 'highway',
                'type' => 'residential',
                'name' => 'Ponsonby Road',
                'address' => [
                    'road' => 'Ponsonby Road',
                    'suburb' => 'Ponsonby',
                    'city' => 'Auckland',
                ],
            ])
            ->push([
                'osm_type' => 'way',
                'osm_id' => 303,
                'category' => 'highway',
                'type' => 'residential',
                'name' => 'Franklin Road',
                'address' => [
                    'road' => 'Franklin Road',
                    'suburb' => 'Ponsonby',
                    'city' => 'Auckland',
                ],
            ]),
    ]);

    $client = app(NominatimClient::class);

    // 1. Area tier lookup + ~50m jitter hits the same ~220m bucket (1 HTTP call total)
    $area1 = $client->reverse(-36.85600, 174.74600, GeocodedPlace::TIER_AREA);
    $area2 = $client->reverse(-36.85645, 174.74635, GeocodedPlace::TIER_AREA);

    expect($area1?->id)->toBe($area2?->id)
        ->and($area2?->area_label)->toBe('Ponsonby');

    // 2. Feature tier lookup + ~8m GPS jitter hits the same ~25m bucket (1 HTTP call)
    $roadA1 = $client->reverse(-36.85600, 174.74600, GeocodedPlace::TIER_FEATURE);
    $roadA2 = $client->reverse(-36.85607, 174.74605, GeocodedPlace::TIER_FEATURE);

    expect($roadA1?->id)->toBe($roadA2?->id)
        ->and($roadA1?->display_label)->toBe('Ponsonby Road');

    // 3. Nearby road ~80m north (~0.00072 deg) must NOT collide in feature tier (triggers 3rd HTTP call)
    $roadB = $client->reverse(-36.85528, 174.74600, GeocodedPlace::TIER_FEATURE);

    expect($roadB?->id)->not->toBe($roadA1?->id)
        ->and($roadB?->display_label)->toBe('Franklin Road');

    Http::assertSentCount(3);
});

it('enforces a >=1000ms spacing floor on the public OSMF host even if configured lower', function () {
    Http::preventStrayRequests();
    config(['services.nominatim.rate_limit_ms' => 200]);

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response([
            'category' => 'place',
            'type' => 'suburb',
            'address' => ['suburb' => 'Devonport', 'city' => 'Auckland'],
        ]),
    ]);

    $nowMs = 1_000_000.0;
    /** @var list<int> $sleptMicroseconds */
    $sleptMicroseconds = [];

    $client = new NominatimClient(
        appVersion: app(AppVersion::class),
        sleeper: function (int $us) use (&$nowMs, &$sleptMicroseconds): void {
            $sleptMicroseconds[] = $us;
            $nowMs += $us / 1000.0;
        },
        clockMs: static function () use (&$nowMs): float {
            return $nowMs;
        },
    );

    expect($client->effectiveRateLimitMs())->toBe(1000);

    $client->reverse(-36.83000, 174.79500, GeocodedPlace::TIER_AREA);
    $nowMs += 150.0; // Only 150ms elapsed since previous request
    $client->reverse(-36.88000, 174.82000, GeocodedPlace::TIER_AREA);

    expect($sleptMicroseconds)->toHaveCount(1)
        ->and($sleptMicroseconds[0])->toBe(850_000);
});

it('honors a custom self-hosted NOMINATIM_BASE_URL and allows zero rate-limit delay', function () {
    Http::preventStrayRequests();
    config([
        'services.nominatim.base_url' => 'http://nominatim.internal:8080/',
        'services.nominatim.rate_limit_ms' => 0,
    ]);

    Http::fake([
        'http://nominatim.internal:8080/reverse*' => Http::response([
            'category' => 'leisure',
            'type' => 'park',
            'name' => 'Albert Park',
            'address' => ['leisure' => 'Albert Park', 'city' => 'Auckland'],
        ]),
    ]);

    $slept = 0;
    $client = new NominatimClient(
        appVersion: app(AppVersion::class),
        sleeper: function (int $us) use (&$slept): void {
            $slept += $us;
        },
    );

    expect($client->isPublicHost())->toBeFalse()
        ->and($client->effectiveRateLimitMs())->toBe(0);

    $place = $client->reverse(-36.8505, 174.7675, GeocodedPlace::TIER_FEATURE);

    expect($place?->provider_host)->toBe('nominatim.internal')
        ->and($place?->display_label)->toBe('Albert Park')
        ->and($slept)->toBe(0);

    Http::assertSent(fn (Request $req): bool => str_starts_with($req->url(), 'http://nominatim.internal:8080/reverse'));
});

it('throws GeocodingRateLimitedException and sets cooldown when Nominatim returns HTTP 429', function () {
    Http::preventStrayRequests();
    Sleep::fake();

    Http::fake([
        'https://nominatim.openstreetmap.org/reverse*' => Http::response(
            ['error' => 'Too Many Requests'],
            429,
            ['Retry-After' => '45'],
        ),
    ]);

    $client = app(NominatimClient::class);

    try {
        $client->reverse(-36.8500, 174.7600, GeocodedPlace::TIER_AREA);
        test()->fail('Expected GeocodingRateLimitedException was not thrown.');
    } catch (GeocodingRateLimitedException $exception) {
        expect($exception->retryAfterSeconds)->toBe(45);
    }

    expect(Cache::has(NominatimClient::CACHE_COOLDOWN_UNTIL_KEY))->toBeTrue();

    // Subsequent call during cooldown must fail fast without making another HTTP call
    expect(fn () => $client->reverse(-36.8900, 174.8100, GeocodedPlace::TIER_AREA))
        ->toThrow(GeocodingRateLimitedException::class);

    Http::assertSentCount(1);
});

it('makes zero HTTP calls when NOMINATIM_ENABLED is false', function () {
    Http::preventStrayRequests();
    config(['services.nominatim.enabled' => false]);

    $place = app(NominatimClient::class)->reverse(-36.8500, 174.7600, GeocodedPlace::TIER_FEATURE);

    expect($place)->toBeNull();
    Http::assertNothingSent();
});
