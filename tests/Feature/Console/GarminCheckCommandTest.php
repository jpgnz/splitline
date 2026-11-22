<?php

use Illuminate\Support\Facades\Http;

it('reports a healthy garmin connection', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => now()->addHours(24)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $jwt = "{$header}.{$payload}.sig";

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response([
            'measurementSystem' => 'metric',
            'timeZone' => 'Pacific/Auckland',
        ], 200),
    ]);

    $this->artisan('garmin:check')
        ->expectsOutputToContain('Successfully connected to the Garmin Connect Personal API.')
        ->expectsOutputToContain('metric')
        ->assertSuccessful();
});

it('fails gracefully when garmin credentials are missing', function () {
    Http::preventStrayRequests();

    config(['services.garmin.key' => '']);

    $this->artisan('garmin:check')
        ->expectsOutputToContain('GARMIN_KEY is not configured.')
        ->assertFailed();
});
