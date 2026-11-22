<?php

namespace App\Services\Weather;

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use Carbon\CarbonImmutable;

/**
 * Orchestrates waypoint sampling, Open-Meteo retrieval, temporal/circular interpolation,
 * route wind impact aggregation, and persistence for ActivityWeather records.
 *
 * @phpstan-import-type WeatherSample from ActivityWeather
 * @phpstan-import-type OpenMeteoHourlySeries from OpenMeteoClient
 */
class ActivityWeatherSyncService
{
    public const string PROVIDER = 'open_meteo';

    public function __construct(
        private readonly ActivityWaypointSampler $sampler,
        private readonly OpenMeteoClient $client,
        private readonly WindVectorMath $math,
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

        $stream = $this->resolveStream($activity);

        return $this->sampler->hasValidGnssPoints($stream);
    }

    /**
     * Fetch, interpolate, summarize, and persist weather telemetry for an outdoor activity.
     */
    public function sync(Activity $activity, bool $force = false): ?ActivityWeather
    {
        if (! (bool) config('services.open_meteo.enabled', true)) {
            return null;
        }

        if (! $this->isEligible($activity)) {
            return null;
        }

        $stream = $this->resolveStream($activity);
        $sampled = $this->sampler->sample($activity, $stream);
        $waypoints = $sampled['waypoints'];
        $samplingMode = $sampled['mode'];

        if ($waypoints === []) {
            return null;
        }

        $existing = ActivityWeather::query()
            ->where('activity_id', $activity->id)
            ->first();

        if ($existing !== null && ! $force) {
            $canUpgradeToRoute = $existing->sampling_mode === 'start_point' && $samplingMode === 'route';

            if (! $canUpgradeToRoute) {
                return $existing;
            }
        }

        /** @var list<array{lat: float, lng: float}> $uniqueCoordinates */
        $uniqueCoordinates = [];
        /** @var array<string, int> $coordKeyToIndex */
        $coordKeyToIndex = [];
        /** @var list<int> $waypointToCoordIndex */
        $waypointToCoordIndex = [];

        foreach ($waypoints as $waypoint) {
            $coordKey = sprintf('%.5f,%.5f', $waypoint['lat'], $waypoint['lng']);

            if (! isset($coordKeyToIndex[$coordKey])) {
                $coordKeyToIndex[$coordKey] = count($uniqueCoordinates);
                $uniqueCoordinates[] = [
                    'lat' => $waypoint['lat'],
                    'lng' => $waypoint['lng'],
                ];
            }

            $waypointToCoordIndex[] = $coordKeyToIndex[$coordKey];
        }

        if ($uniqueCoordinates === []) {
            return null;
        }

        $windowStartUtc = $activity->started_at_utc->utc();
        $lastWaypointTimestamp = max(array_column($waypoints, 'timestamp'));
        $durationSeconds = max(0, (int) round($activity->elapsed_duration_seconds ?? $activity->duration_seconds));
        $windowEndUtc = CarbonImmutable::createFromTimestampUTC(
            max($lastWaypointTimestamp, $windowStartUtc->getTimestamp() + $durationSeconds),
        );

        $response = $this->client->fetchHourly($uniqueCoordinates, $windowStartUtc, $windowEndUtc);

        /** @var list<WeatherSample> $samples */
        $samples = [];

        foreach ($waypoints as $index => $waypoint) {
            $locationSeries = $response['locations'][$waypointToCoordIndex[$index]];
            $samples[] = $this->interpolateWaypointSample($waypoint, $locationSeries);
        }

        $summary = $this->summarizeSamples($samples);

        $windImpact = $samplingMode === 'route' && $stream !== null && is_array($stream->series) && $stream->series !== []
            ? $this->math->computeRouteWindImpact($stream->series, $samples)
            : [
                'headwind_pct' => null,
                'crosswind_pct' => null,
                'tailwind_pct' => null,
                'avg_effective_wind_kmh' => null,
            ];

        return ActivityWeather::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'provider' => self::PROVIDER,
                'model_endpoint' => $response['endpoint'],
                'sampling_mode' => $samplingMode,
                'waypoint_count' => count($samples),
                'wmo_code' => $summary['wmo_code'],
                'condition' => $summary['condition'],
                'base_condition' => $summary['base_condition'],
                'icon' => $summary['icon'],
                'temperature_c' => $summary['temperature_c'],
                'min_temperature_c' => $summary['min_temperature_c'],
                'max_temperature_c' => $summary['max_temperature_c'],
                'apparent_temperature_c' => $summary['apparent_temperature_c'],
                'humidity_pct' => $summary['humidity_pct'],
                'dew_point_c' => $summary['dew_point_c'],
                'wind_speed_kmh' => $summary['wind_speed_kmh'],
                'wind_gusts_kmh' => $summary['wind_gusts_kmh'],
                'wind_direction_deg' => $summary['wind_direction_deg'],
                'wind_direction_compass' => $summary['wind_direction_compass'],
                'precipitation_mm' => $summary['precipitation_mm'],
                'cloud_cover_pct' => $summary['cloud_cover_pct'],
                'surface_pressure_hpa' => $summary['surface_pressure_hpa'],
                'headwind_pct' => $windImpact['headwind_pct'],
                'crosswind_pct' => $windImpact['crosswind_pct'],
                'tailwind_pct' => $windImpact['tailwind_pct'],
                'avg_effective_wind_kmh' => $windImpact['avg_effective_wind_kmh'],
                'samples' => $samples,
                'fetched_at' => CarbonImmutable::now(),
            ],
        );
    }

    private function resolveStream(Activity $activity): ?ActivityStream
    {
        return $activity->relationLoaded('stream')
            ? $activity->stream
            : $activity->stream()->first();
    }

    /**
     * Interpolate scalar and circular wind metrics for a single waypoint from hourly Open-Meteo series.
     *
     * @param  array{time_offset_s: int, distance_m: float, lat: float, lng: float, timestamp: int}  $waypoint
     * @param  OpenMeteoHourlySeries  $hourly
     * @return WeatherSample
     */
    private function interpolateWaypointSample(array $waypoint, array $hourly): array
    {
        $times = $hourly['time'];
        $count = count($times);

        if ($count === 0) {
            return [
                'time_offset_s' => $waypoint['time_offset_s'],
                'distance_m' => round($waypoint['distance_m'], 1),
                'lat' => round($waypoint['lat'], 6),
                'lng' => round($waypoint['lng'], 6),
                'wmo_code' => null,
                'temperature_c' => null,
                'apparent_temperature_c' => null,
                'humidity_pct' => null,
                'dew_point_c' => null,
                'wind_speed_kmh' => null,
                'wind_gusts_kmh' => null,
                'wind_direction_deg' => null,
                'wind_direction_compass' => null,
                'precipitation_mm' => null,
                'cloud_cover_pct' => null,
                'surface_pressure_hpa' => null,
            ];
        }

        [$idx0, $idx1, $alpha] = $this->findBracketingHours($times, $waypoint['timestamp']);

        $temperature = $this->interpolateScalar($hourly['temperature_2m'], $idx0, $idx1, $alpha);
        $apparentTemp = $this->interpolateScalar($hourly['apparent_temperature'], $idx0, $idx1, $alpha);
        $humidity = $this->interpolateScalar($hourly['relative_humidity_2m'], $idx0, $idx1, $alpha);
        $dewPoint = $this->interpolateScalar($hourly['dew_point_2m'], $idx0, $idx1, $alpha);
        $windGusts = $this->interpolateScalar($hourly['wind_gusts_10m'], $idx0, $idx1, $alpha);
        $precipitation = $this->interpolateScalar($hourly['precipitation'], $idx0, $idx1, $alpha);
        $cloudCover = $this->interpolateScalar($hourly['cloud_cover'], $idx0, $idx1, $alpha);
        $pressure = $this->interpolateScalar($hourly['surface_pressure'], $idx0, $idx1, $alpha);

        $speed0 = $hourly['wind_speed_10m'][$idx0] ?? null;
        $speed1 = $hourly['wind_speed_10m'][$idx1] ?? null;
        $dir0 = $hourly['wind_direction_10m'][$idx0] ?? null;
        $dir1 = $hourly['wind_direction_10m'][$idx1] ?? null;

        $windSpeed = null;
        $windDirDeg = null;
        $windCompass = null;

        if ($speed0 !== null && $dir0 !== null && $speed1 !== null && $dir1 !== null) {
            $interpolatedWind = $this->math->interpolateWindVector($speed0, $dir0, $speed1, $dir1, $alpha);
            $windSpeed = round($interpolatedWind['speed'], 1);
            $windDirDeg = $interpolatedWind['direction_deg'];
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        } elseif ($speed0 !== null && $dir0 !== null) {
            $windSpeed = round($speed0, 1);
            $windDirDeg = ((int) round(fmod(fmod($dir0, 360.0) + 360.0, 360.0))) % 360;
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        } elseif ($speed1 !== null && $dir1 !== null) {
            $windSpeed = round($speed1, 1);
            $windDirDeg = ((int) round(fmod(fmod($dir1, 360.0) + 360.0, 360.0))) % 360;
            $windCompass = $this->math->compassDirection((float) $windDirDeg);
        }

        $nearestIdx = $alpha < 0.5 ? $idx0 : $idx1;
        $otherIdx = $alpha < 0.5 ? $idx1 : $idx0;
        $wmoCode = $hourly['weather_code'][$nearestIdx] ?? $hourly['weather_code'][$otherIdx] ?? null;

        return [
            'time_offset_s' => $waypoint['time_offset_s'],
            'distance_m' => round($waypoint['distance_m'], 1),
            'lat' => round($waypoint['lat'], 6),
            'lng' => round($waypoint['lng'], 6),
            'wmo_code' => $wmoCode,
            'temperature_c' => $temperature !== null ? round($temperature, 1) : null,
            'apparent_temperature_c' => $apparentTemp !== null ? round($apparentTemp, 1) : null,
            'humidity_pct' => $humidity !== null ? max(0, min(100, (int) round($humidity))) : null,
            'dew_point_c' => $dewPoint !== null ? round($dewPoint, 1) : null,
            'wind_speed_kmh' => $windSpeed,
            'wind_gusts_kmh' => $windGusts !== null ? round($windGusts, 1) : null,
            'wind_direction_deg' => $windDirDeg,
            'wind_direction_compass' => $windCompass,
            'precipitation_mm' => $precipitation !== null ? round($precipitation, 2) : null,
            'cloud_cover_pct' => $cloudCover !== null ? max(0, min(100, (int) round($cloudCover))) : null,
            'surface_pressure_hpa' => $pressure !== null ? round($pressure, 1) : null,
        ];
    }

    /**
     * Find the bracketing hourly index pair and interpolation fraction alpha in [0, 1].
     *
     * @param  non-empty-list<int>  $times
     * @return array{0: int, 1: int, 2: float}
     */
    private function findBracketingHours(array $times, int $timestamp): array
    {
        $lastIndex = count($times) - 1;

        if ($lastIndex === 0 || $timestamp <= $times[0]) {
            return [0, 0, 0.0];
        }

        if ($timestamp >= $times[$lastIndex]) {
            return [$lastIndex, $lastIndex, 0.0];
        }

        for ($i = 0; $i < $lastIndex; $i++) {
            $t0 = $times[$i];
            $t1 = $times[$i + 1];

            if ($timestamp >= $t0 && $timestamp <= $t1) {
                $span = $t1 - $t0;
                $alpha = $span > 0 ? ($timestamp - $t0) / $span : 0.0;

                return [$i, $i + 1, $alpha];
            }
        }

        return [$lastIndex, $lastIndex, 0.0];
    }

    /**
     * @param  list<?float>  $series
     */
    private function interpolateScalar(array $series, int $idx0, int $idx1, float $alpha): ?float
    {
        $v0 = $series[$idx0] ?? null;
        $v1 = $series[$idx1] ?? null;

        if ($v0 !== null && $v1 !== null) {
            return ((1.0 - $alpha) * $v0) + ($alpha * $v1);
        }

        return $v0 ?? $v1;
    }

    /**
     * Aggregate waypoint samples into activity-level summary columns.
     *
     * @param  list<WeatherSample>  $samples
     * @return array{
     *     wmo_code: ?int,
     *     condition: string,
     *     base_condition: ?string,
     *     icon: string,
     *     temperature_c: ?float,
     *     min_temperature_c: ?float,
     *     max_temperature_c: ?float,
     *     apparent_temperature_c: ?float,
     *     humidity_pct: ?int,
     *     dew_point_c: ?float,
     *     wind_speed_kmh: ?float,
     *     wind_gusts_kmh: ?float,
     *     wind_direction_deg: ?int,
     *     wind_direction_compass: ?string,
     *     precipitation_mm: ?float,
     *     cloud_cover_pct: ?int,
     *     surface_pressure_hpa: ?float
     * }
     */
    private function summarizeSamples(array $samples): array
    {
        $temps = $this->extractNonNullFloats($samples, 'temperature_c');
        $apparentTemps = $this->extractNonNullFloats($samples, 'apparent_temperature_c');
        $humidities = $this->extractNonNullFloats($samples, 'humidity_pct');
        $dewPoints = $this->extractNonNullFloats($samples, 'dew_point_c');
        $windGusts = $this->extractNonNullFloats($samples, 'wind_gusts_kmh');
        $precipitations = $this->extractNonNullFloats($samples, 'precipitation_mm');
        $cloudCovers = $this->extractNonNullFloats($samples, 'cloud_cover_pct');
        $pressures = $this->extractNonNullFloats($samples, 'surface_pressure_hpa');

        $hasWindSamples = false;

        foreach ($samples as $sample) {
            if ($sample['wind_speed_kmh'] !== null && $sample['wind_direction_deg'] !== null) {
                $hasWindSamples = true;
                break;
            }
        }

        $windSpeedKmh = null;
        $windDirectionDeg = null;
        $windDirectionCompass = null;

        if ($hasWindSamples) {
            $vectorMean = $this->math->vectorMeanWind($samples);
            $windSpeedKmh = round($vectorMean['speed'], 1);
            $windDirectionDeg = $vectorMean['direction_deg'];
            $windDirectionCompass = $this->math->compassDirection((float) $windDirectionDeg);
        }

        $maxWindGusts = $windGusts !== [] ? round(max($windGusts), 1) : null;
        $wmoCode = $this->resolveRepresentativeWmoCode($samples);

        if ($wmoCode !== null) {
            $resolved = $this->math->resolveDisplayCondition($wmoCode, $windSpeedKmh, $maxWindGusts);
            $condition = $resolved['condition'];
            $baseCondition = $resolved['base_condition'];
            $icon = $resolved['icon'];
        } else {
            $condition = 'Unknown';
            $baseCondition = null;
            $icon = 'cloud';
        }

        return [
            'wmo_code' => $wmoCode,
            'condition' => $condition,
            'base_condition' => $baseCondition,
            'icon' => $icon,
            'temperature_c' => $temps !== [] ? round(array_sum($temps) / count($temps), 1) : null,
            'min_temperature_c' => $temps !== [] ? round(min($temps), 1) : null,
            'max_temperature_c' => $temps !== [] ? round(max($temps), 1) : null,
            'apparent_temperature_c' => $apparentTemps !== [] ? round(array_sum($apparentTemps) / count($apparentTemps), 1) : null,
            'humidity_pct' => $humidities !== [] ? max(0, min(100, (int) round(array_sum($humidities) / count($humidities)))) : null,
            'dew_point_c' => $dewPoints !== [] ? round(array_sum($dewPoints) / count($dewPoints), 1) : null,
            'wind_speed_kmh' => $windSpeedKmh,
            'wind_gusts_kmh' => $maxWindGusts,
            'wind_direction_deg' => $windDirectionDeg,
            'wind_direction_compass' => $windDirectionCompass,
            'precipitation_mm' => $precipitations !== [] ? round(array_sum($precipitations) / count($precipitations), 2) : null,
            'cloud_cover_pct' => $cloudCovers !== [] ? max(0, min(100, (int) round(array_sum($cloudCovers) / count($cloudCovers)))) : null,
            'surface_pressure_hpa' => $pressures !== [] ? round(array_sum($pressures) / count($pressures), 1) : null,
        ];
    }

    /**
     * @param  list<WeatherSample>  $samples
     * @param  'temperature_c'|'apparent_temperature_c'|'humidity_pct'|'dew_point_c'|'wind_gusts_kmh'|'precipitation_mm'|'cloud_cover_pct'|'surface_pressure_hpa'  $key
     * @return list<float>
     */
    private function extractNonNullFloats(array $samples, string $key): array
    {
        $values = [];

        foreach ($samples as $sample) {
            if ($sample[$key] !== null) {
                $values[] = (float) $sample[$key];
            }
        }

        return $values;
    }

    /**
     * Select the dominant WMO code across samples, breaking ties toward higher severity.
     *
     * @param  list<WeatherSample>  $samples
     */
    private function resolveRepresentativeWmoCode(array $samples): ?int
    {
        /** @var array<int, int> $counts */
        $counts = [];

        foreach ($samples as $sample) {
            $code = $sample['wmo_code'];

            if ($code !== null) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        $bestCode = null;
        $bestCount = 0;

        foreach ($counts as $code => $count) {
            if ($count > $bestCount || ($count === $bestCount && ($bestCode === null || $code > $bestCode))) {
                $bestCode = $code;
                $bestCount = $count;
            }
        }

        return $bestCode;
    }
}
