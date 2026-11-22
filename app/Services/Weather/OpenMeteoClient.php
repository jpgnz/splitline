<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * HTTP client for Open-Meteo Forecast, Historical Forecast, and Archive APIs.
 *
 * @phpstan-type OpenMeteoHourlySeries array{
 *     time: list<int>,
 *     temperature_2m: list<?float>,
 *     relative_humidity_2m: list<?float>,
 *     apparent_temperature: list<?float>,
 *     dew_point_2m: list<?float>,
 *     wind_speed_10m: list<?float>,
 *     wind_gusts_10m: list<?float>,
 *     wind_direction_10m: list<?float>,
 *     weather_code: list<?int>,
 *     precipitation: list<?float>,
 *     cloud_cover: list<?float>,
 *     surface_pressure: list<?float>
 * }
 */
class OpenMeteoClient
{
    public const string RATE_LIMIT_TIMESTAMPS_CACHE_KEY = 'open_meteo.request_timestamps';

    private const float RATE_LIMIT_WINDOW_SECONDS = 60.0;

    private const float RATE_LIMIT_WINDOW_BUFFER_SECONDS = 0.25;

    private const int FORECAST_LOOKBACK_DAYS = 90;

    /**
     * @var list<string>
     */
    public const array HOURLY_VARIABLES = [
        'temperature_2m',
        'relative_humidity_2m',
        'apparent_temperature',
        'dew_point_2m',
        'wind_speed_10m',
        'wind_gusts_10m',
        'wind_direction_10m',
        'weather_code',
        'precipitation',
        'cloud_cover',
        'surface_pressure',
    ];

    /**
     * @var list<int>
     */
    private const array RETRY_BACKOFF_SECONDS = [1, 3, 8, 15];

    /**
     * @var (Closure(float, string): void)|null
     */
    private ?Closure $rateLimitWaitListener = null;

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Register a listener invoked whenever the client pauses for Open-Meteo rate limiting.
     *
     * @param  (callable(float, string): void)|null  $listener
     */
    public function onRateLimitWait(?callable $listener): void
    {
        $this->rateLimitWaitListener = $listener !== null ? $listener(...) : null;
    }

    /**
     * Choose the appropriate Open-Meteo endpoint based on the activity's UTC start date.
     *
     * @return 'forecast'|'historical_forecast'|'archive'
     */
    public function selectEndpoint(
        CarbonImmutable $activityStartUtc,
        ?CarbonImmutable $nowUtc = null,
    ): string {
        $now = ($nowUtc ?? CarbonImmutable::now('UTC'))->utc();
        $start = $activityStartUtc->utc();

        if ($start->greaterThanOrEqualTo($now->subDays(self::FORECAST_LOOKBACK_DAYS)->startOfDay())) {
            return 'forecast';
        }

        $historicalForecastStart = CarbonImmutable::create(2022, 1, 1, 0, 0, 0, 'UTC');

        if ($start->greaterThanOrEqualTo($historicalForecastStart)) {
            return 'historical_forecast';
        }

        return 'archive';
    }

    /**
     * Fetch batched hourly weather data for one or more GNSS coordinates across a UTC time window.
     *
     * @param  non-empty-list<array{lat: float, lng: float}>  $coordinates
     * @return array{endpoint: 'forecast'|'historical_forecast'|'archive', locations: list<OpenMeteoHourlySeries>}
     */
    public function fetchHourly(
        array $coordinates,
        CarbonImmutable $windowStartUtc,
        CarbonImmutable $windowEndUtc,
    ): array {
        $endpoint = $this->selectEndpoint($windowStartUtc);
        $startDate = $windowStartUtc->utc()->subHour()->format('Y-m-d');
        $endDate = $windowEndUtc->utc()->addHour()->format('Y-m-d');

        $locations = $this->requestEndpoint($endpoint, $coordinates, $startDate, $endDate);

        if ($endpoint === 'historical_forecast' && $this->hasMissingCoverage($locations)) {
            $endpoint = 'archive';
            $locations = $this->requestEndpoint($endpoint, $coordinates, $startDate, $endDate);
        }

        return [
            'endpoint' => $endpoint,
            'locations' => $locations,
        ];
    }

    /**
     * Execute a single batched GET request against an Open-Meteo endpoint with retries and rate limiting.
     *
     * @param  'forecast'|'historical_forecast'|'archive'  $endpoint
     * @param  non-empty-list<array{lat: float, lng: float}>  $coordinates
     * @return list<OpenMeteoHourlySeries>
     */
    private function requestEndpoint(
        string $endpoint,
        array $coordinates,
        string $startDate,
        string $endDate,
    ): array {
        $url = $this->endpointUrl($endpoint);
        $timeout = max(1, (int) config('services.open_meteo.timeout', 15));
        $maxRetries = max(0, (int) config('services.open_meteo.max_retries', 2));

        $query = [
            'latitude' => implode(',', array_map(
                static fn (array $coord): string => (string) round($coord['lat'], 5),
                $coordinates,
            )),
            'longitude' => implode(',', array_map(
                static fn (array $coord): string => (string) round($coord['lng'], 5),
                $coordinates,
            )),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'hourly' => implode(',', self::HOURLY_VARIABLES),
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'precipitation_unit' => 'mm',
            'timezone' => 'UTC',
            'timeformat' => 'unixtime',
        ];

        $attempt = 0;

        while (true) {
            $this->waitForRateLimitSlot();

            try {
                $response = Http::connectTimeout(5)
                    ->timeout($timeout)
                    ->acceptJson()
                    ->get($url, $query);
            } catch (ConnectionException $exception) {
                if ($attempt < $maxRetries) {
                    $waitSeconds = $this->backoffSeconds($attempt);
                    $attempt++;
                    $this->sleepSeconds($waitSeconds);

                    continue;
                }

                throw OpenMeteoException::connectionError($url, $exception);
            }

            if ($response->status() === 429) {
                $retryAfterSeconds = $this->resolveRetryAfterSeconds($response, $attempt);

                if ($attempt < $maxRetries) {
                    $attempt++;
                    $this->notifyRateLimitWait(
                        (float) $retryAfterSeconds,
                        "HTTP 429 from Open-Meteo (retry {$attempt}/{$maxRetries})",
                    );
                    $this->sleepSeconds((float) $retryAfterSeconds);

                    continue;
                }

                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: 429,
                    responseBody: $response->body(),
                    retryAfterSeconds: $retryAfterSeconds,
                );
            }

            if ($response->serverError()) {
                if ($attempt < $maxRetries) {
                    $waitSeconds = $this->backoffSeconds($attempt);
                    $attempt++;
                    $this->sleepSeconds($waitSeconds);

                    continue;
                }

                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: $response->status(),
                    responseBody: $response->body(),
                );
            }

            if (! $response->successful()) {
                throw OpenMeteoException::httpError(
                    url: $url,
                    statusCode: $response->status(),
                    responseBody: $response->body(),
                );
            }

            return $this->parseResponseLocations($response, $url, count($coordinates));
        }
    }

    /**
     * Normalize Open-Meteo's single-object (1 coordinate) and JSON-array (2+ coordinates) response shapes.
     *
     * @return list<OpenMeteoHourlySeries>
     */
    private function parseResponseLocations(Response $response, string $url, int $expectedCount): array
    {
        $decoded = $response->json();

        if (! is_array($decoded) || $decoded === []) {
            throw OpenMeteoException::invalidResponse($url, 'Empty or non-JSON payload.');
        }

        $rawLocations = array_is_list($decoded) ? $decoded : [$decoded];

        if (count($rawLocations) !== $expectedCount) {
            throw OpenMeteoException::invalidResponse(
                $url,
                sprintf('Expected %d location payload(s), received %d.', $expectedCount, count($rawLocations)),
            );
        }

        $locations = [];

        foreach ($rawLocations as $index => $entry) {
            if (! is_array($entry) || ! isset($entry['hourly']) || ! is_array($entry['hourly'])) {
                throw OpenMeteoException::invalidResponse($url, "Location #{$index} is missing the hourly object.");
            }

            $hourly = $entry['hourly'];

            if (! isset($hourly['time']) || ! is_array($hourly['time'])) {
                throw OpenMeteoException::invalidResponse($url, "Location #{$index} is missing hourly.time.");
            }

            $times = [];

            foreach ($hourly['time'] as $ts) {
                if (! is_numeric($ts)) {
                    throw OpenMeteoException::invalidResponse($url, "Location #{$index} contains a non-numeric timestamp.");
                }

                $times[] = (int) $ts;
            }

            $length = count($times);

            $locations[] = [
                'time' => $times,
                'temperature_2m' => $this->normalizeFloatSeries($hourly['temperature_2m'] ?? null, $length),
                'relative_humidity_2m' => $this->normalizeFloatSeries($hourly['relative_humidity_2m'] ?? null, $length),
                'apparent_temperature' => $this->normalizeFloatSeries($hourly['apparent_temperature'] ?? null, $length),
                'dew_point_2m' => $this->normalizeFloatSeries($hourly['dew_point_2m'] ?? null, $length),
                'wind_speed_10m' => $this->normalizeFloatSeries($hourly['wind_speed_10m'] ?? null, $length),
                'wind_gusts_10m' => $this->normalizeFloatSeries($hourly['wind_gusts_10m'] ?? null, $length),
                'wind_direction_10m' => $this->normalizeFloatSeries($hourly['wind_direction_10m'] ?? null, $length),
                'weather_code' => $this->normalizeIntSeries($hourly['weather_code'] ?? null, $length),
                'precipitation' => $this->normalizeFloatSeries($hourly['precipitation'] ?? null, $length),
                'cloud_cover' => $this->normalizeFloatSeries($hourly['cloud_cover'] ?? null, $length),
                'surface_pressure' => $this->normalizeFloatSeries($hourly['surface_pressure'] ?? null, $length),
            ];
        }

        return $locations;
    }

    /**
     * Determine whether all returned locations lack temperature or wind speed coverage.
     *
     * @param  list<OpenMeteoHourlySeries>  $locations
     */
    private function hasMissingCoverage(array $locations): bool
    {
        $hasTemperature = false;
        $hasWind = false;

        foreach ($locations as $location) {
            foreach ($location['temperature_2m'] as $value) {
                if ($value !== null) {
                    $hasTemperature = true;
                    break;
                }
            }

            foreach ($location['wind_speed_10m'] as $value) {
                if ($value !== null) {
                    $hasWind = true;
                    break;
                }
            }
        }

        return ! $hasTemperature || ! $hasWind;
    }

    /**
     * @return list<?float>
     */
    private function normalizeFloatSeries(mixed $raw, int $length): array
    {
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $item = is_array($raw) && array_key_exists($i, $raw) ? $raw[$i] : null;
            $values[] = is_numeric($item) ? (float) $item : null;
        }

        return $values;
    }

    /**
     * @return list<?int>
     */
    private function normalizeIntSeries(mixed $raw, int $length): array
    {
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $item = is_array($raw) && array_key_exists($i, $raw) ? $raw[$i] : null;
            $values[] = is_numeric($item) ? (int) round((float) $item) : null;
        }

        return $values;
    }

    /**
     * @param  'forecast'|'historical_forecast'|'archive'  $endpoint
     */
    private function endpointUrl(string $endpoint): string
    {
        return match ($endpoint) {
            'forecast' => (string) config('services.open_meteo.forecast_url', 'https://api.open-meteo.com/v1/forecast'),
            'historical_forecast' => (string) config('services.open_meteo.historical_forecast_url', 'https://historical-forecast-api.open-meteo.com/v1/forecast'),
            'archive' => (string) config('services.open_meteo.archive_url', 'https://archive-api.open-meteo.com/v1/archive'),
        };
    }

    private function waitForRateLimitSlot(): void
    {
        $maxRequestsPerMinute = (int) config('services.open_meteo.max_requests_per_minute', 50);

        if ($maxRequestsPerMinute <= 0) {
            return;
        }

        $now = $this->nowSeconds();

        while (true) {
            $timestamps = $this->pruneRequestTimestamps(
                $this->cache->get(self::RATE_LIMIT_TIMESTAMPS_CACHE_KEY),
                $now,
            );

            if (count($timestamps) < $maxRequestsPerMinute) {
                $timestamps[] = $now;
                $this->cache->put(self::RATE_LIMIT_TIMESTAMPS_CACHE_KEY, $timestamps, 120);

                return;
            }

            $oldestTimestamp = $timestamps[0];
            $waitSeconds = ($oldestTimestamp + self::RATE_LIMIT_WINDOW_SECONDS)
                - $now
                + self::RATE_LIMIT_WINDOW_BUFFER_SECONDS;
            $waitSeconds = max(0.1, min($waitSeconds, 60.0));

            $this->notifyRateLimitWait(
                $waitSeconds,
                "Open-Meteo request budget ({$maxRequestsPerMinute}/min) reached",
            );

            $beforeSleep = $now;
            $this->sleepSeconds($waitSeconds);
            $now = $this->nowSeconds();

            if ($now <= $beforeSleep) {
                array_shift($timestamps);
                $timestamps[] = $now;
                $this->cache->put(self::RATE_LIMIT_TIMESTAMPS_CACHE_KEY, $timestamps, 120);

                return;
            }
        }
    }

    /**
     * @return list<float>
     */
    private function pruneRequestTimestamps(mixed $raw, float $now): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $cutoff = $now - self::RATE_LIMIT_WINDOW_SECONDS;
        $valid = [];

        foreach ($raw as $timestamp) {
            if (is_numeric($timestamp) && (float) $timestamp > $cutoff) {
                $valid[] = (float) $timestamp;
            }
        }

        sort($valid);

        return $valid;
    }

    private function resolveRetryAfterSeconds(Response $response, int $attempt): int
    {
        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter) && (float) $retryAfter > 0.0) {
                return max(1, (int) ceil((float) $retryAfter));
            }

            $retryTimestamp = strtotime($retryAfter);

            if ($retryTimestamp !== false) {
                $delta = (float) $retryTimestamp - $this->nowSeconds();

                if ($delta > 0.0) {
                    return max(1, (int) ceil($delta));
                }
            }
        }

        return (int) ceil($this->backoffSeconds($attempt));
    }

    private function backoffSeconds(int $attempt): float
    {
        $index = min(max(0, $attempt), count(self::RETRY_BACKOFF_SECONDS) - 1);

        return (float) self::RETRY_BACKOFF_SECONDS[$index];
    }

    private function notifyRateLimitWait(float $waitSeconds, string $reason): void
    {
        if ($this->rateLimitWaitListener !== null) {
            ($this->rateLimitWaitListener)($waitSeconds, $reason);
        }
    }

    private function sleepSeconds(float $seconds): void
    {
        $milliseconds = (int) ceil($seconds * 1000.0);

        if ($milliseconds > 0) {
            Sleep::for($milliseconds)->milliseconds();
        }
    }

    private function nowSeconds(): float
    {
        return Date::now()->getPreciseTimestamp(3) / 1000.0;
    }
}
