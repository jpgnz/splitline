<?php

namespace App\Services\Weather;

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Services\Ingestion\TelemetryPersistenceService;
use Splitline\Core\Data\RouteInput;
use Splitline\Weather\WeatherEnricher;

/**
 * Orchestrates Open-Meteo weather enrichment and persistence for outdoor Activity records.
 */
class ActivityWeatherSyncService
{
    public const string PROVIDER = WeatherEnricher::PROVIDER;

    public function __construct(
        private readonly WeatherEnricher $enricher,
        private readonly TelemetryPersistenceService $persistence,
    ) {}

    /**
     * Determine whether an activity is eligible for weather synchronization.
     */
    public function isEligible(Activity $activity): bool
    {
        if ($activity->is_indoor) {
            return false;
        }

        if ($activity->start_lat !== null && $activity->start_lng !== null) {
            return true;
        }

        return $this->enricher->isEligible(
            $this->buildRouteInput($activity, $this->resolveStream($activity)),
        );
    }

    /**
     * Fetch, interpolate, summarize, and persist weather telemetry for an outdoor activity.
     */
    public function sync(Activity $activity, bool $force = false): ?ActivityWeather
    {
        if (! (bool) config('services.open_meteo.enabled', true)) {
            return null;
        }

        $stream = $this->resolveStream($activity);
        $routeInput = $this->buildRouteInput($activity, $stream);

        if (! $this->enricher->isEligible($routeInput)) {
            return null;
        }

        $existing = ActivityWeather::query()
            ->where('activity_id', $activity->id)
            ->first();

        if ($existing !== null && ! $force) {
            $canUpgradeToRoute = $existing->sampling_mode === 'start_point'
                && $this->hasRouteCoordinates($activity, $routeInput->series);

            if (! $canUpgradeToRoute) {
                return $existing;
            }
        }

        $weatherData = $this->enricher->enrich($routeInput);

        if ($weatherData === null) {
            return null;
        }

        return $this->persistence->upsertWeather($activity, $weatherData);
    }

    private function resolveStream(Activity $activity): ?ActivityStream
    {
        return $activity->relationLoaded('stream')
            ? $activity->stream
            : $activity->stream()->first();
    }

    private function buildRouteInput(Activity $activity, ?ActivityStream $stream): RouteInput
    {
        return new RouteInput(
            isIndoor: $activity->is_indoor,
            startedAtUtc: $activity->started_at_utc,
            durationSeconds: $activity->duration_seconds,
            elapsedDurationSeconds: $activity->elapsed_duration_seconds,
            distanceMeters: $activity->distance_meters,
            elevationGain: $activity->elevation_gain,
            startLat: $activity->start_lat,
            startLng: $activity->start_lng,
            series: $this->normalizeSeries($stream?->series),
        );
    }

    /**
     * @param  list<array{time?: float|int|null, distance?: float|int|null, altitude?: float|int|null, lat?: float|int|null, lng?: float|int|null}>|null  $series
     */
    private function hasRouteCoordinates(Activity $activity, ?array $series): bool
    {
        if ($series === null || $series === []) {
            return false;
        }

        return $this->enricher->isEligible(new RouteInput(
            isIndoor: $activity->is_indoor,
            startedAtUtc: $activity->started_at_utc,
            durationSeconds: $activity->duration_seconds,
            elapsedDurationSeconds: $activity->elapsed_duration_seconds,
            distanceMeters: $activity->distance_meters,
            elevationGain: $activity->elevation_gain,
            startLat: null,
            startLng: null,
            series: $series,
        ));
    }

    /**
     * @param  array<int|string, mixed>|null  $series
     * @return list<array{time?: float|int|null, distance?: float|int|null, altitude?: float|int|null, lat?: float|int|null, lng?: float|int|null}>|null
     */
    private function normalizeSeries(?array $series): ?array
    {
        if ($series === null) {
            return null;
        }

        $normalized = [];

        foreach ($series as $point) {
            if (! is_array($point)) {
                continue;
            }

            $entry = [];

            foreach (['time', 'distance', 'altitude', 'lat', 'lng'] as $key) {
                if (isset($point[$key]) && is_numeric($point[$key])) {
                    $entry[$key] = (float) $point[$key];
                }
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }
}
