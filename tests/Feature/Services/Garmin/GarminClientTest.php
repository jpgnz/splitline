<?php

use App\Services\Garmin\GarminApiException;
use App\Services\Garmin\GarminClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeGarminJwt(int $expiresAt, string $signature = 'sig'): string
{
    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $expiresAt], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return "{$header}.{$payload}.{$signature}";
}

it('exchanges the personal api key for a bearer token and caches it across requests', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response([
            'measurementSystem' => 'metric',
            'timeZone' => 'Pacific/Auckland',
        ], 200),
        'https://personal.connectapi.garmin.com/usersummary-service/stats/steps/daily/2026-11-01/2026-11-07' => Http::response([
            ['calendarDate' => '2026-11-01', 'totalSteps' => 9123],
        ], 200),
    ]);

    $client = app(GarminClient::class);

    $profile = $client->userProfileSettings();
    $steps = $client->dailySteps(CarbonImmutable::parse('2026-11-01'), '2026-11-07');

    expect($profile)->toBe([
        'measurementSystem' => 'metric',
        'timeZone' => 'Pacific/Auckland',
    ]);
    expect($steps)->toBe([
        ['calendarDate' => '2026-11-01', 'totalSteps' => 9123],
    ]);

    Http::assertSentCount(3);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token'
        && $request->header('Authorization') === ['ApiKey test-garmin-key']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings'
        && $request->header('Authorization') === ["Bearer {$jwt}"]);
});

it('refreshes the cached bearer token when within one hour of expiration', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $firstJwt = fakeGarminJwt(now()->addHours(2)->getTimestamp(), 'first');
    $secondJwt = fakeGarminJwt(now()->addHours(26)->getTimestamp(), 'second');

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::sequence()
            ->push($firstJwt, 200)
            ->push($secondJwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response(['ok' => true], 200),
    ]);

    $client = app(GarminClient::class);
    $client->userProfileSettings();

    $this->travel(61)->minutes();

    $client->userProfileSettings();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings'
        && $request->header('Authorization') === ["Bearer {$secondJwt}"]);
});

it('retries once with a fresh bearer token when garmin responds with 401', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $revokedJwt = fakeGarminJwt(now()->addHours(24)->getTimestamp(), 'revoked');
    $freshJwt = fakeGarminJwt(now()->addHours(24)->getTimestamp(), 'fresh');

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::sequence()
            ->push($revokedJwt, 200)
            ->push($freshJwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::sequence()
            ->push(['error' => 'Unauthorized'], 401)
            ->push(['measurementSystem' => 'metric'], 200),
    ]);

    $profile = app(GarminClient::class)->userProfileSettings();

    expect($profile)->toBe(['measurementSystem' => 'metric']);

    Http::assertSentCount(4);
});

it('serializes boolean and repeated array query parameters per the swagger spec', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/metrics-service/metrics/maxmet/daily/2026-11-01/2026-11-10?sport=RUNNING&sport=CYCLING' => Http::response([
            ['sport' => 'RUNNING', 'vo2MaxValue' => 54],
        ], 200),
        'https://personal.connectapi.garmin.com/weight-service/weight/range/2026-11-01/2026-11-10?includeAll=true' => Http::response([
            'dailyWeightSummaries' => [],
        ], 200),
    ]);

    $client = app(GarminClient::class);

    expect($client->dailyVo2Max('2026-11-01', '2026-11-10', ['RUNNING', 'CYCLING']))
        ->toBe([['sport' => 'RUNNING', 'vo2MaxValue' => 54]]);

    expect($client->weightMeasurements('2026-11-01', '2026-11-10', includeAll: true))
        ->toBe(['dailyWeightSummaries' => []]);
});

it('caches activity types for 48 hours unless forced to refresh', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/activity-service/activity/activityTypes/simple' => Http::sequence()
            ->push([['typeKey' => 'running']], 200)
            ->push([['typeKey' => 'running'], ['typeKey' => 'cycling']], 200),
    ]);

    $client = app(GarminClient::class);

    expect($client->activityTypes())->toBe([['typeKey' => 'running']]);
    expect($client->activityTypes())->toBe([['typeKey' => 'running']]);
    expect($client->activityTypes(forceRefresh: true))->toBe([['typeKey' => 'running'], ['typeKey' => 'cycling']]);

    Http::assertSentCount(3);
});

it('downloads raw zip bytes for valid activity ids and rejects invalid ids', function () {
    $this->freezeTime();
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/123456' => Http::response("PK\x03\x04zipdata", 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    $client = app(GarminClient::class);

    expect($client->downloadActivity(123456))->toBe("PK\x03\x04zipdata");

    expect(fn () => $client->downloadActivity(0))
        ->toThrow(GarminApiException::class, 'activityId must be a positive integer.');
});

it('throws when GARMIN_KEY is missing', function () {
    Http::preventStrayRequests();

    config(['services.garmin.key' => '']);

    expect(fn () => app(GarminClient::class)->userProfileSettings())
        ->toThrow(GarminApiException::class, 'GARMIN_KEY is not configured.');
});

it('throws when the token endpoint returns a non-jwt payload', function () {
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response('not-a-jwt', 200),
    ]);

    expect(fn () => app(GarminClient::class)->userProfileSettings())
        ->toThrow(GarminApiException::class, 'expected a JWT payload');
});

it('retries HTTP 429 responses using garmin retryInSeconds plus a safety buffer', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'services.garmin.rate_limit.max_requests_per_minute' => 85,
        'services.garmin.rate_limit.max_retries' => 3,
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/usersummary-service/usersummary/daily?calendarDate=2026-08-13' => Http::sequence()
            ->push([
                'count' => 103,
                'limit' => 100,
                'retryInSeconds' => 7,
                'rateLimitMetaData' => 2,
            ], 429)
            ->push(['calendarDate' => '2026-08-13', 'totalSteps' => 8420], 200),
    ]);

    $waitEvents = [];
    $client = app(GarminClient::class);
    $client->onRateLimitWait(function (float $seconds, string $reason) use (&$waitEvents): void {
        $waitEvents[] = ['seconds' => $seconds, 'reason' => $reason];
    });

    $summary = $client->dailySummary('2026-08-13');

    expect($summary)->toBe(['calendarDate' => '2026-08-13', 'totalSteps' => 8420])
        ->and($waitEvents)->toHaveCount(1)
        ->and($waitEvents[0]['seconds'])->toBe(8.0)
        ->and($waitEvents[0]['reason'])->toContain('HTTP 429');

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 8000, times: 1);
    Http::assertSentCount(3);
});

it('retries HTTP 429 using Retry-After header and falls back to exponential backoff when no hint is provided', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'services.garmin.rate_limit.max_requests_per_minute' => 85,
        'services.garmin.rate_limit.max_retries' => 3,
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::sequence()
            ->push('Rate limited', 429, ['Retry-After' => '4'])
            ->push('Still rate limited', 429)
            ->push(['measurementSystem' => 'metric'], 200),
    ]);

    $profile = app(GarminClient::class)->userProfileSettings();

    expect($profile)->toBe(['measurementSystem' => 'metric']);

    Sleep::assertSequence([
        Sleep::for(5000)->milliseconds(),
        Sleep::for(5000)->milliseconds(),
    ]);
});

it('throws a rate-limited GarminApiException with retryAfterSeconds after exhausting 429 retries', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'services.garmin.rate_limit.max_requests_per_minute' => 85,
        'services.garmin.rate_limit.max_retries' => 2,
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response([
            'count' => 101,
            'limit' => 100,
            'retryInSeconds' => 6,
        ], 429),
    ]);

    $caught = null;

    try {
        app(GarminClient::class)->userProfileSettings();
    } catch (GarminApiException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(GarminApiException::class)
        ->and($caught?->isRateLimited())->toBeTrue()
        ->and($caught?->statusCode)->toBe(429)
        ->and($caught?->retryAfterSeconds)->toBe(7);

    Sleep::assertSleptTimes(2);
    Http::assertSentCount(4); // 1 token + 1 initial + 2 retries
});

it('proactively pauses when the per-minute request budget is reached', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'services.garmin.rate_limit.max_requests_per_minute' => 2,
    ]);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response(['ok' => true], 200),
    ]);

    $waitEvents = [];
    $client = app(GarminClient::class);
    $client->onRateLimitWait(function (float $seconds, string $reason) use (&$waitEvents): void {
        $waitEvents[] = ['seconds' => $seconds, 'reason' => $reason];
    });

    $client->userProfileSettings();
    $client->userProfileSettings();
    $client->userProfileSettings();

    expect($waitEvents)->toHaveCount(1)
        ->and($waitEvents[0]['reason'])->toContain('2/min');

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 60250, times: 1);
});

it('respects a shared rate-limit cooldown stored in cache before sending requests', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
    ]);

    Cache::put(GarminClient::RATE_LIMIT_UNTIL_CACHE_KEY, (now()->getPreciseTimestamp(3) / 1000.0) + 10.0, 60);

    $jwt = fakeGarminJwt(now()->addHours(24)->getTimestamp());

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/personal-api/personal-api-key/token' => Http::response($jwt, 200),
        'https://personal.connectapi.garmin.com/userprofile-service/userprofile/settings' => Http::response(['ok' => true], 200),
    ]);

    expect(app(GarminClient::class)->userProfileSettings())->toBe(['ok' => true]);

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 10000, times: 1);
});
