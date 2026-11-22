<?php

namespace App\Services\Garmin;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

class GarminClient
{
    public const string ACTIVITY_TYPES_CACHE_KEY = 'garmin.personal_api.activity_types';

    public const int ACTIVITY_TYPES_CACHE_TTL_SECONDS = 172800;

    public const string RATE_LIMIT_TIMESTAMPS_CACHE_KEY = 'garmin.personal_api.request_timestamps';

    public const string RATE_LIMIT_UNTIL_CACHE_KEY = 'garmin.personal_api.rate_limit_until';

    private const float RATE_LIMIT_WINDOW_SECONDS = 60.0;

    private const float RATE_LIMIT_SAFETY_BUFFER_SECONDS = 1.0;

    private const float RATE_LIMIT_WINDOW_BUFFER_SECONDS = 0.25;

    /**
     * @var list<int>
     */
    private const array RATE_LIMIT_FALLBACK_BACKOFF_SECONDS = [2, 5, 15, 30, 60];

    /**
     * @var (Closure(float, string): void)|null
     */
    private ?Closure $rateLimitWaitListener = null;

    public function __construct(
        private readonly GarminTokenManager $tokenManager,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly CacheRepository $cache,
        private readonly int $maxRequestsPerMinute = 85,
        private readonly int $maxRateLimitRetries = 5,
        private readonly int $maxRetryWaitSeconds = 120,
    ) {}

    /**
     * Register a listener invoked whenever the client pauses for Garmin rate limiting.
     *
     * @param  (callable(float, string): void)|null  $listener
     */
    public function onRateLimitWait(?callable $listener): void
    {
        $this->rateLimitWaitListener = $listener !== null ? $listener(...) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userProfileSettings(): ?array
    {
        return $this->getJsonObject('/userprofile-service/userprofile/settings');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySummary(CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject('/usersummary-service/usersummary/daily', [
            'calendarDate' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySummaries(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonObject('/usersummary-service/v2/dailysummaries', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailySteps(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/usersummary-service/stats/steps/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyStress(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/usersummary-service/stats/stress/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyHydration(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/usersummary-service/stats/hydration/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySleep(CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject('/sleep-service/sleep/dailySleep', [
            'date' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailySleepSummaries(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray('/sleep-service/sleep/dailySleepSummariesByDate', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
        ]);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyBodyBattery(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?int $chunks = null,
    ): ?array {
        return $this->getJsonArray('/wellness-service/wellness/bodyBattery/reports/daily', [
            'startDate' => $this->formatDate($startDate),
            'endDate' => $this->formatDate($endDate),
            'chunks' => $chunks,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailyHeartRate(CarbonInterface|string|null $date = null): ?array
    {
        return $this->getJsonObject('/wellness-service/wellness/dailyHeartRate', [
            'date' => $date !== null ? $this->formatDate($date) : null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailySpo2(CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            sprintf('/wellness-service/wellness/daily/spo2acclimation/%s', $this->formatDate($date)),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function dailyHrv(CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            sprintf('/hrv-service/hrv/%s', $this->formatDate($date)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function dailyHrvSummaries(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/hrv-service/hrv/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  list<string>|null  $sports
     * @return array<int|string, mixed>|null
     */
    public function dailyVo2Max(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        ?array $sports = null,
    ): ?array {
        return $this->getJsonArray(
            sprintf('/metrics-service/metrics/maxmet/daily/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
            ['sport' => $sports],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fitnessAge(CarbonInterface|string $date, ?bool $fullHistoryScan = null): ?array
    {
        return $this->getJsonObject(
            sprintf('/fitnessage-service/fitnessage/v2/%s', $this->formatDate($date)),
            ['fullHistoryScan' => $fullHistoryScan],
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function heartRateZones(?bool $generateDefaults = null): ?array
    {
        return $this->getJsonArray('/biometric-service/heartRateZones/v2', [
            'generateDefaults' => $generateDefaults,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function weightMeasurements(
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
        bool $includeAll = true,
    ): ?array {
        return $this->getJsonObject(
            sprintf('/weight-service/weight/range/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
            ['includeAll' => $includeAll],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function allWeightMeasurements(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonObject(
            sprintf('/weight-service/weight/all/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function effectiveWeightGoals(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/goal-service/goal/user/effective/weightgoal/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  array{includeGoals?: bool, includeNutrition?: bool, includeMedia?: bool, mealId?: int}  $options
     * @return array<string, mixed>|null
     */
    public function dailyFoodLogs(CarbonInterface|string $date, array $options = []): ?array
    {
        return $this->getJsonObject(
            sprintf('/nutrition-service/food/logs/%s', $this->formatDate($date)),
            $options,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function menstrualCycleSummary(CarbonInterface|string $date): ?array
    {
        return $this->getJsonObject(
            sprintf('/periodichealth-service/menstrualcycle/summary/%s', $this->formatDate($date)),
        );
    }

    /**
     * @param  array{reducedResponse?: bool, skipbm?: bool, includeAnalysis?: bool}  $options
     * @return array<string, mixed>|null
     */
    public function menstrualCycleDayView(
        CarbonInterface|string $date,
        CarbonInterface|string|null $todayDate = null,
        array $options = [],
    ): ?array {
        return $this->getJsonObject(
            sprintf('/periodichealth-service/menstrualcycle/dayview/%s', $this->formatDate($date)),
            [
                'todayDate' => $todayDate !== null ? $this->formatDate($todayDate) : null,
                ...$options,
            ],
        );
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function activityTypes(bool $forceRefresh = false): ?array
    {
        if (! $forceRefresh) {
            $cached = $this->cache->get(self::ACTIVITY_TYPES_CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $types = $this->getJsonArray('/activity-service/activity/activityTypes/simple');

        if ($types !== null) {
            $this->cache->put(
                self::ACTIVITY_TYPES_CACHE_KEY,
                $types,
                self::ACTIVITY_TYPES_CACHE_TTL_SECONDS,
            );
        }

        return $types;
    }

    /**
     * @param  array<string, CarbonInterface|array<int, scalar>|scalar|null>  $filters
     * @return array<int|string, mixed>|null
     */
    public function searchActivities(array $filters = []): ?array
    {
        $normalized = [];

        foreach ($filters as $key => $value) {
            $normalized[$key] = $value instanceof CarbonInterface ? $this->formatDate($value) : $value;
        }

        return $this->getJsonArray('/activitylist-service/activities/search/activities', $normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activity(int|string $activityId): ?array
    {
        return $this->getJsonObject(sprintf('/activity-service/activity/%s', $activityId));
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function activityWorkouts(int|string $activityId): ?array
    {
        return $this->getJsonArray(sprintf('/activity-service/activity/%s/workouts', $activityId));
    }

    /**
     * Download the original activity archive as raw ZIP bytes.
     */
    public function downloadActivity(int|string $activityId): string
    {
        $id = (string) $activityId;

        if (! ctype_digit($id) || (int) $id <= 0) {
            throw GarminApiException::invalidArgument('activityId must be a positive integer.');
        }

        $response = $this->requestGet(
            path: "/download-service/files/activity/{$id}",
            accept: 'application/zip, application/x-zip-compressed, application/octet-stream',
        );

        $body = $response->body();

        if ($body === '') {
            throw GarminApiException::invalidArgument("Garmin returned an empty archive for activity {$id}.");
        }

        return $body;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    public function wellnessActivitySummaries(CarbonInterface|string $startDate, CarbonInterface|string $endDate): ?array
    {
        return $this->getJsonArray(
            sprintf('/wellnessactivity-service/activity/summary/range/%s/%s', $this->formatDate($startDate), $this->formatDate($endDate)),
        );
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    public function get(string $path, array $query = []): mixed
    {
        $response = $this->requestGet($path, $query);

        if (trim($response->body()) === '') {
            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     * @return array<int|string, mixed>|null
     */
    private function getJsonArray(string $path, array $query = []): ?array
    {
        $data = $this->get($path, $query);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     * @return array<string, mixed>|null
     */
    private function getJsonObject(string $path, array $query = []): ?array
    {
        $data = $this->get($path, $query);

        if (! is_array($data)) {
            return null;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    private function requestGet(
        string $path,
        array $query = [],
        string $accept = 'application/json',
    ): Response {
        $relativeUrl = $this->buildPathWithQuery($path, $query);
        $retriedAuth = false;
        $forceTokenRefresh = false;
        $rateLimitAttempt = 0;
        $lastRateLimitWaitSeconds = null;

        while (true) {
            $this->waitForRateLimitSlot();

            $response = $this->sendGet($relativeUrl, $accept, forceRefresh: $forceTokenRefresh);
            $forceTokenRefresh = false;

            if ($response->status() === 401 && ! $retriedAuth) {
                $retriedAuth = true;
                $forceTokenRefresh = true;
                $this->tokenManager->forgetToken();

                continue;
            }

            if ($response->status() === 429) {
                $waitSeconds = $this->resolveRateLimitWaitSeconds($response, $rateLimitAttempt);
                $lastRateLimitWaitSeconds = $waitSeconds;
                $this->recordRateLimitCooldown($waitSeconds);

                if ($rateLimitAttempt >= max(0, $this->maxRateLimitRetries)) {
                    break;
                }

                $rateLimitAttempt++;
                $this->notifyRateLimitWait(
                    $waitSeconds,
                    "HTTP 429 from Garmin (retry {$rateLimitAttempt}/{$this->maxRateLimitRetries})",
                );
                $this->sleepSeconds($waitSeconds);

                continue;
            }

            break;
        }

        if (! $response->successful()) {
            $fullUrl = rtrim($this->baseUrl, '/').'/'.ltrim($relativeUrl, '/');

            throw GarminApiException::httpError(
                method: 'GET',
                url: $fullUrl,
                statusCode: $response->status(),
                responseBody: $response->body(),
                retryAfterSeconds: $lastRateLimitWaitSeconds !== null ? (int) ceil($lastRateLimitWaitSeconds) : null,
            );
        }

        return $response;
    }

    private function sendGet(string $relativeUrl, string $accept, bool $forceRefresh): Response
    {
        $token = $this->tokenManager->getAccessToken($forceRefresh);

        return Http::baseUrl(rtrim($this->baseUrl, '/'))
            ->connectTimeout(5)
            ->timeout($this->timeout)
            ->withToken($token)
            ->withHeaders(['Accept' => $accept])
            ->retry(
                times: [200, 1000],
                sleepMilliseconds: 0,
                when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            )
            ->get($relativeUrl);
    }

    private function waitForRateLimitSlot(): void
    {
        $now = $this->nowSeconds();
        $cooldownUntil = $this->cache->get(self::RATE_LIMIT_UNTIL_CACHE_KEY);

        if (is_numeric($cooldownUntil) && (float) $cooldownUntil > $now) {
            $waitSeconds = min(
                (float) $cooldownUntil - $now,
                (float) max(1, $this->maxRetryWaitSeconds),
            );
            $this->notifyRateLimitWait($waitSeconds, 'Garmin rate limit cooldown');
            $this->sleepSeconds($waitSeconds);
            $now = $this->nowSeconds();
        }

        if ($this->maxRequestsPerMinute <= 0) {
            return;
        }

        while (true) {
            $timestamps = $this->pruneRequestTimestamps(
                $this->cache->get(self::RATE_LIMIT_TIMESTAMPS_CACHE_KEY),
                $now,
            );

            if (count($timestamps) < $this->maxRequestsPerMinute) {
                $timestamps[] = $now;
                $this->cache->put(self::RATE_LIMIT_TIMESTAMPS_CACHE_KEY, $timestamps, 120);

                return;
            }

            $oldestTimestamp = $timestamps[0];
            $waitSeconds = ($oldestTimestamp + self::RATE_LIMIT_WINDOW_SECONDS)
                - $now
                + self::RATE_LIMIT_WINDOW_BUFFER_SECONDS;
            $waitSeconds = max(0.1, min($waitSeconds, (float) max(1, $this->maxRetryWaitSeconds)));

            $this->notifyRateLimitWait(
                $waitSeconds,
                "Garmin request budget ({$this->maxRequestsPerMinute}/min) reached",
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

    private function resolveRateLimitWaitSeconds(Response $response, int $attempt): float
    {
        $decoded = json_decode($response->body(), true);

        if (
            is_array($decoded)
            && isset($decoded['retryInSeconds'])
            && is_numeric($decoded['retryInSeconds'])
            && (float) $decoded['retryInSeconds'] > 0
        ) {
            return $this->clampRetryWait((float) $decoded['retryInSeconds'] + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
        }

        $retryAfter = trim($response->header('Retry-After'));

        if ($retryAfter !== '') {
            if (is_numeric($retryAfter) && (float) $retryAfter > 0) {
                return $this->clampRetryWait((float) $retryAfter + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
            }

            $retryTimestamp = strtotime($retryAfter);

            if ($retryTimestamp !== false) {
                $delta = (float) $retryTimestamp - $this->nowSeconds();

                if ($delta > 0) {
                    return $this->clampRetryWait($delta + self::RATE_LIMIT_SAFETY_BUFFER_SECONDS);
                }
            }
        }

        $fallbackIndex = min(max(0, $attempt), count(self::RATE_LIMIT_FALLBACK_BACKOFF_SECONDS) - 1);

        return $this->clampRetryWait((float) self::RATE_LIMIT_FALLBACK_BACKOFF_SECONDS[$fallbackIndex]);
    }

    private function clampRetryWait(float $seconds): float
    {
        return max(1.0, min($seconds, (float) max(1, $this->maxRetryWaitSeconds)));
    }

    private function recordRateLimitCooldown(float $waitSeconds): void
    {
        $until = $this->nowSeconds() + $waitSeconds;
        $ttl = max(1, (int) ceil($waitSeconds) + 10);

        $this->cache->put(self::RATE_LIMIT_UNTIL_CACHE_KEY, $until, $ttl);
    }

    private function notifyRateLimitWait(float $waitSeconds, string $reason): void
    {
        if ($this->rateLimitWaitListener !== null) {
            ($this->rateLimitWaitListener)($waitSeconds, $reason);
        }
    }

    private function sleepSeconds(float $seconds): void
    {
        $milliseconds = (int) ceil($seconds * 1000);

        if ($milliseconds > 0) {
            Sleep::for($milliseconds)->milliseconds();
        }
    }

    private function nowSeconds(): float
    {
        return Date::now()->getPreciseTimestamp(3) / 1000.0;
    }

    private function formatDate(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;
    }

    /**
     * @param  array<string, array<int, scalar>|scalar|null>  $query
     */
    private function buildPathWithQuery(string $path, array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode($key).'='.rawurlencode($this->stringifyQueryValue($item));
                }

                continue;
            }

            $pairs[] = rawurlencode($key).'='.rawurlencode($this->stringifyQueryValue($value));
        }

        if ($pairs === []) {
            return $path;
        }

        return $path.'?'.implode('&', $pairs);
    }

    private function stringifyQueryValue(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
