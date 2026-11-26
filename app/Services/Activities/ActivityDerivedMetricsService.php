<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Computes and persists derived aerobic, pacing, heart-rate polarization, and route geometry
 * metrics for streamed activities.
 */
class ActivityDerivedMetricsService
{
    private const float MIN_ACTIVE_HR = 40.0;

    private const float MIN_ACTIVE_SPEED_MPS = 0.5;

    private const float MIN_DECOUPLING_DURATION_SECONDS = 1200.0;

    private const int MIN_DECOUPLING_POINTS = 60;

    private const float METRIC_COVERAGE_THRESHOLD = 0.8;

    private const float MIN_FULL_SPLIT_METERS = 400.0;

    private const float NEGATIVE_SPLIT_THRESHOLD = 1.015;

    private const float POSITIVE_SPLIT_THRESHOLD = 0.985;

    private const float LOOP_MAX_DISTANCE_METERS = 500.0;

    private const int FINGERPRINT_WAYPOINTS = 10;

    private const float EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Compute and persist all derived metrics for an activity.
     */
    public function computeAndStore(Activity $activity, ?ActivityStream $stream = null): ActivityDerivedMetric
    {
        $resolvedStream = $stream ?? ($activity->relationLoaded('stream') ? $activity->stream : $activity->stream()->first());

        $series = is_array($resolvedStream?->series) ? $resolvedStream->series : [];
        $kmSplits = is_array($resolvedStream?->km_splits) ? $resolvedStream->km_splits : [];
        $polyline = is_array($resolvedStream?->polyline) ? $resolvedStream->polyline : [];

        $aerobic = $this->computeAerobicDecoupling($series);
        $pacing = $this->computePacingStrategy($kmSplits);
        $hrSplit = $this->computePolarizedHrSplit($activity);
        $geometry = $this->computeRouteGeometry($polyline);

        $metric = ActivityDerivedMetric::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'decoupling_pct' => $aerobic['decoupling_pct'],
                'decoupling_metric' => $aerobic['decoupling_metric'],
                'decoupling_rating' => $aerobic['decoupling_rating'],
                'efficiency_factor' => $aerobic['efficiency_factor'],
                'first_half_ef' => $aerobic['first_half_ef'],
                'second_half_ef' => $aerobic['second_half_ef'],
                'pacing_strategy' => $pacing['pacing_strategy'],
                'split_ratio' => $pacing['split_ratio'],
                'pace_cv_pct' => $pacing['pace_cv_pct'],
                'hr_low_pct' => $hrSplit['hr_low_pct'],
                'hr_moderate_pct' => $hrSplit['hr_moderate_pct'],
                'hr_high_pct' => $hrSplit['hr_high_pct'],
                'end_lat' => $geometry['end_lat'],
                'end_lng' => $geometry['end_lng'],
                'start_to_end_meters' => $geometry['start_to_end_meters'],
                'is_loop' => $geometry['is_loop'],
                'bbox_min_lat' => $geometry['bbox_min_lat'],
                'bbox_max_lat' => $geometry['bbox_max_lat'],
                'bbox_min_lng' => $geometry['bbox_min_lng'],
                'bbox_max_lng' => $geometry['bbox_max_lng'],
                'route_fingerprint' => $geometry['route_fingerprint'],
                'computed_at' => CarbonImmutable::now('UTC'),
            ],
        );

        $activity->setRelation('derivedMetrics', $metric);

        return $metric;
    }

    /**
     * Count streamed activities that do not yet have an activity_derived_metrics row.
     */
    public function missingCount(): int
    {
        return Activity::query()
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id');
            })
            ->whereNotExists(function (QueryBuilder $derivedSub): void {
                $derivedSub
                    ->selectRaw('1')
                    ->from('activity_derived_metrics')
                    ->whereColumn('activity_derived_metrics.activity_id', 'activities.id');
            })
            ->count();
    }

    /**
     * Backfill derived metrics for streamed activities in chunks.
     */
    public function backfill(bool $force = false, int $chunkSize = 100): int
    {
        $query = Activity::query()
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id');
            })
            ->with('stream');

        if (! $force) {
            $query->whereNotExists(function (QueryBuilder $derivedSub): void {
                $derivedSub
                    ->selectRaw('1')
                    ->from('activity_derived_metrics')
                    ->whereColumn('activity_derived_metrics.activity_id', 'activities.id');
            });
        }

        $processed = 0;
        $safeChunk = max(1, $chunkSize);

        $query->chunkById($safeChunk, function (Collection $activities) use (&$processed): void {
            foreach ($activities as $activity) {
                $this->computeAndStore($activity, $activity->stream);
                $processed++;
            }
        });

        return $processed;
    }

    /**
     * Compute Aerobic Decoupling (Pa:Hr / Pw:Hr / Spd:Hr) and Efficiency Factor from telemetry series.
     *
     * @param  array<int|string, mixed>  $series
     * @return array{
     *     decoupling_pct: ?float,
     *     decoupling_metric: ?string,
     *     decoupling_rating: ?string,
     *     efficiency_factor: ?float,
     *     first_half_ef: ?float,
     *     second_half_ef: ?float
     * }
     */
    private function computeAerobicDecoupling(array $series): array
    {
        $empty = [
            'decoupling_pct' => null,
            'decoupling_metric' => null,
            'decoupling_rating' => null,
            'efficiency_factor' => null,
            'first_half_ef' => null,
            'second_half_ef' => null,
        ];

        $points = $this->extractSeriesPoints($series);

        if (count($points) < self::MIN_DECOUPLING_POINTS) {
            return $empty;
        }

        $pauseThreshold = $this->resolvePauseThreshold($points);
        $fallbackDt = $this->resolveMedianPositiveDt($points);

        /** @var list<array{active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float}> $activePoints */
        $activePoints = [];
        $activeElapsed = 0.0;
        $prevTime = null;

        foreach ($points as $point) {
            $time = $point['time'];
            $rawDt = ($prevTime !== null) ? ($time - $prevTime) : 0.0;
            $prevTime = $time;

            $hr = $point['hr'];
            $speed = $point['speed'];
            $power = $point['power'];

            $isActive = $hr !== null
                && $hr >= self::MIN_ACTIVE_HR
                && (
                    ($speed !== null && $speed >= self::MIN_ACTIVE_SPEED_MPS)
                    || ($power !== null && $power > 0.0)
                );

            if (! $isActive) {
                continue;
            }

            $dt = ($rawDt > 0.0 && $rawDt <= $pauseThreshold) ? $rawDt : 0.0;
            $activeElapsed += $dt;

            $activePoints[] = [
                'active_t' => $activeElapsed,
                'dt' => $dt > 0.0 ? $dt : $fallbackDt,
                'hr' => $hr,
                'speed' => $speed,
                'gap_speed' => $point['gap_speed'],
                'power' => $power,
            ];
        }

        $activeCount = count($activePoints);

        if ($activeCount < self::MIN_DECOUPLING_POINTS || $activeElapsed < self::MIN_DECOUPLING_DURATION_SECONDS) {
            return $empty;
        }

        $powerCount = 0;
        $gapCount = 0;
        $speedCount = 0;

        foreach ($activePoints as $point) {
            if ($point['power'] !== null && $point['power'] > 0.0) {
                $powerCount++;
            }

            if ($point['gap_speed'] !== null && $point['gap_speed'] > 0.0) {
                $gapCount++;
            }

            if ($point['speed'] !== null && $point['speed'] > 0.0) {
                $speedCount++;
            }
        }

        if (($powerCount / $activeCount) >= self::METRIC_COVERAGE_THRESHOLD) {
            $metric = 'power';
            $field = 'power';
            $scale = 1.0;
        } elseif (($gapCount / $activeCount) >= self::METRIC_COVERAGE_THRESHOLD) {
            $metric = 'gap';
            $field = 'gap_speed';
            $scale = 60.0;
        } elseif ($speedCount > 0) {
            $metric = 'speed';
            $field = 'speed';
            $scale = 60.0;
        } else {
            return $empty;
        }

        $midpoint = $activeElapsed / 2.0;

        $firstOutputSum = 0.0;
        $firstHrSum = 0.0;
        $firstWeight = 0.0;

        $secondOutputSum = 0.0;
        $secondHrSum = 0.0;
        $secondWeight = 0.0;

        foreach ($activePoints as $point) {
            $output = $point[$field];

            if ($output === null || $output <= 0.0) {
                continue;
            }

            $weight = $point['dt'];

            if ($point['active_t'] <= $midpoint) {
                $firstOutputSum += $output * $weight;
                $firstHrSum += $point['hr'] * $weight;
                $firstWeight += $weight;
            } else {
                $secondOutputSum += $output * $weight;
                $secondHrSum += $point['hr'] * $weight;
                $secondWeight += $weight;
            }
        }

        if ($firstWeight <= 0.0 || $secondWeight <= 0.0 || $firstHrSum <= 0.0 || $secondHrSum <= 0.0) {
            return $empty;
        }

        $avgOutput1 = $firstOutputSum / $firstWeight;
        $avgHr1 = $firstHrSum / $firstWeight;
        $ef1 = ($avgOutput1 * $scale) / $avgHr1;

        $avgOutput2 = $secondOutputSum / $secondWeight;
        $avgHr2 = $secondHrSum / $secondWeight;
        $ef2 = ($avgOutput2 * $scale) / $avgHr2;

        $totalWeight = $firstWeight + $secondWeight;
        $overallOutput = ($firstOutputSum + $secondOutputSum) / $totalWeight;
        $overallHr = ($firstHrSum + $secondHrSum) / $totalWeight;
        $overallEf = ($overallOutput * $scale) / $overallHr;

        if ($ef1 <= 0.0) {
            return $empty;
        }

        $decouplingPct = round((($ef1 - $ef2) / $ef1) * 100.0, 2);

        if ($decouplingPct < 5.0) {
            $rating = 'well_coupled';
        } elseif ($decouplingPct <= 10.0) {
            $rating = 'moderate_drift';
        } else {
            $rating = 'high_drift';
        }

        return [
            'decoupling_pct' => $decouplingPct,
            'decoupling_metric' => $metric,
            'decoupling_rating' => $rating,
            'efficiency_factor' => round($overallEf, 3),
            'first_half_ef' => round($ef1, 3),
            'second_half_ef' => round($ef2, 3),
        ];
    }

    /**
     * Extract normalized point dictionaries from a raw activity stream series array.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<array{time: float, hr: ?float, speed: ?float, gap_speed: ?float, power: ?float}>
     */
    private function extractSeriesPoints(array $series): array
    {
        $points = [];

        foreach ($series as $item) {
            if (! is_array($item) || ! isset($item['time']) || ! is_numeric($item['time'])) {
                continue;
            }

            $points[] = [
                'time' => (float) $item['time'],
                'hr' => isset($item['hr']) && is_numeric($item['hr']) ? (float) $item['hr'] : null,
                'speed' => isset($item['speed']) && is_numeric($item['speed']) ? (float) $item['speed'] : null,
                'gap_speed' => isset($item['gap_speed']) && is_numeric($item['gap_speed']) ? (float) $item['gap_speed'] : null,
                'power' => isset($item['power']) && is_numeric($item['power']) ? (float) $item['power'] : null,
            ];
        }

        return $points;
    }

    /**
     * @param  list<array{time: float, hr: ?float, speed: ?float, gap_speed: ?float, power: ?float}>  $points
     */
    private function resolveMedianPositiveDt(array $points): float
    {
        $deltas = [];
        $count = count($points);

        for ($i = 1; $i < $count; $i++) {
            $dt = $points[$i]['time'] - $points[$i - 1]['time'];

            if ($dt > 0.0) {
                $deltas[] = $dt;
            }
        }

        if ($deltas === []) {
            return 1.0;
        }

        sort($deltas);
        $mid = intdiv(count($deltas), 2);

        return $deltas[$mid];
    }

    /**
     * @param  list<array{time: float, hr: ?float, speed: ?float, gap_speed: ?float, power: ?float}>  $points
     */
    private function resolvePauseThreshold(array $points): float
    {
        $medianDt = $this->resolveMedianPositiveDt($points);

        return max(60.0, $medianDt * 4.0);
    }

    /**
     * Compute pacing strategy, split ratio, and pace coefficient of variation from full km splits.
     *
     * @param  array<int|string, mixed>  $kmSplits
     * @return array{pacing_strategy: ?string, split_ratio: ?float, pace_cv_pct: ?float}
     */
    private function computePacingStrategy(array $kmSplits): array
    {
        $empty = [
            'pacing_strategy' => null,
            'split_ratio' => null,
            'pace_cv_pct' => null,
        ];

        $rawSplits = array_values(array_filter(
            $kmSplits,
            static fn (mixed $item): bool => is_array($item),
        ));

        if ($rawSplits === []) {
            return $empty;
        }

        // Exclude final partial split < 400m
        $lastSplit = $rawSplits[count($rawSplits) - 1];
        $lastDistance = isset($lastSplit['distance_meters']) && is_numeric($lastSplit['distance_meters'])
            ? (float) $lastSplit['distance_meters']
            : null;

        if ($lastDistance !== null && $lastDistance < self::MIN_FULL_SPLIT_METERS) {
            array_pop($rawSplits);
        }

        /** @var list<array{distance: float, duration: float, speed: float, pace: float}> $fullSplits */
        $fullSplits = [];

        foreach ($rawSplits as $split) {
            $distance = isset($split['distance_meters']) && is_numeric($split['distance_meters'])
                ? (float) $split['distance_meters']
                : 0.0;
            $speed = isset($split['avg_speed']) && is_numeric($split['avg_speed'])
                ? (float) $split['avg_speed']
                : 0.0;
            $duration = isset($split['duration_seconds']) && is_numeric($split['duration_seconds'])
                ? (float) $split['duration_seconds']
                : 0.0;

            if ($speed <= 0.0 && $distance > 0.0 && $duration > 0.0) {
                $speed = $distance / $duration;
            }

            if ($duration <= 0.0 && $distance > 0.0 && $speed > 0.0) {
                $duration = $distance / $speed;
            }

            if ($distance < self::MIN_FULL_SPLIT_METERS || $speed <= 0.0 || $duration <= 0.0) {
                continue;
            }

            $pace = isset($split['pace_seconds_per_km']) && is_numeric($split['pace_seconds_per_km']) && (float) $split['pace_seconds_per_km'] > 0.0
                ? (float) $split['pace_seconds_per_km']
                : (1000.0 / $speed);

            $fullSplits[] = [
                'distance' => $distance,
                'duration' => $duration,
                'speed' => $speed,
                'pace' => $pace,
            ];
        }

        $count = count($fullSplits);

        if ($count < 2) {
            return $empty;
        }

        $halfSize = intdiv($count, 2);
        $firstHalf = array_slice($fullSplits, 0, $halfSize);
        $secondHalf = array_slice($fullSplits, $count - $halfSize, $halfSize);

        $firstDistance = array_sum(array_column($firstHalf, 'distance'));
        $firstDuration = array_sum(array_column($firstHalf, 'duration'));
        $secondDistance = array_sum(array_column($secondHalf, 'distance'));
        $secondDuration = array_sum(array_column($secondHalf, 'duration'));

        if ($firstDuration <= 0.0 || $secondDuration <= 0.0 || $firstDistance <= 0.0 || $secondDistance <= 0.0) {
            return $empty;
        }

        $firstHalfSpeed = $firstDistance / $firstDuration;
        $secondHalfSpeed = $secondDistance / $secondDuration;

        $splitRatio = round($secondHalfSpeed / $firstHalfSpeed, 4);

        if ($splitRatio >= self::NEGATIVE_SPLIT_THRESHOLD) {
            $strategy = 'negative_split';
        } elseif ($splitRatio <= self::POSITIVE_SPLIT_THRESHOLD) {
            $strategy = 'positive_split';
        } else {
            $strategy = 'even_split';
        }

        $paces = array_column($fullSplits, 'pace');
        $meanPace = array_sum($paces) / $count;
        $variance = 0.0;

        foreach ($paces as $pace) {
            $diff = $pace - $meanPace;
            $variance += $diff * $diff;
        }

        $stdDev = sqrt($variance / $count);
        $paceCvPct = $meanPace > 0.0 ? round(($stdDev / $meanPace) * 100.0, 2) : null;

        return [
            'pacing_strategy' => $strategy,
            'split_ratio' => $splitRatio,
            'pace_cv_pct' => $paceCvPct,
        ];
    }

    /**
     * Compute polarized heart-rate split (Low Z1-Z2 / Moderate Z3 / High Z4-Z5) percentages.
     *
     * @return array{hr_low_pct: ?float, hr_moderate_pct: ?float, hr_high_pct: ?float}
     */
    private function computePolarizedHrSplit(Activity $activity): array
    {
        $z1 = max(0.0, (float) ($activity->hr_zone_1_seconds ?? 0.0));
        $z2 = max(0.0, (float) ($activity->hr_zone_2_seconds ?? 0.0));
        $z3 = max(0.0, (float) ($activity->hr_zone_3_seconds ?? 0.0));
        $z4 = max(0.0, (float) ($activity->hr_zone_4_seconds ?? 0.0));
        $z5 = max(0.0, (float) ($activity->hr_zone_5_seconds ?? 0.0));

        $total = $z1 + $z2 + $z3 + $z4 + $z5;

        if ($total <= 0.0) {
            return [
                'hr_low_pct' => null,
                'hr_moderate_pct' => null,
                'hr_high_pct' => null,
            ];
        }

        return [
            'hr_low_pct' => round((($z1 + $z2) / $total) * 100.0, 1),
            'hr_moderate_pct' => round(($z3 / $total) * 100.0, 1),
            'hr_high_pct' => round((($z4 + $z5) / $total) * 100.0, 1),
        ];
    }

    /**
     * Compute route endpoints, loop detection, bounding box, and 10-point fingerprint from polyline.
     *
     * @param  array<int|string, mixed>  $polyline
     * @return array{
     *     end_lat: ?float,
     *     end_lng: ?float,
     *     start_to_end_meters: ?float,
     *     is_loop: ?bool,
     *     bbox_min_lat: ?float,
     *     bbox_max_lat: ?float,
     *     bbox_min_lng: ?float,
     *     bbox_max_lng: ?float,
     *     route_fingerprint: ?list<array{0: float, 1: float}>
     * }
     */
    private function computeRouteGeometry(array $polyline): array
    {
        $empty = [
            'end_lat' => null,
            'end_lng' => null,
            'start_to_end_meters' => null,
            'is_loop' => null,
            'bbox_min_lat' => null,
            'bbox_max_lat' => null,
            'bbox_min_lng' => null,
            'bbox_max_lng' => null,
            'route_fingerprint' => null,
        ];

        /** @var list<array{0: float, 1: float}> $coords */
        $coords = [];

        foreach ($polyline as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }

            $lat = (float) $point[0];
            $lng = (float) $point[1];

            if (! is_finite($lat) || ! is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }

            $coords[] = [$lat, $lng];
        }

        $count = count($coords);

        if ($count === 0) {
            return $empty;
        }

        $first = $coords[0];
        $last = $coords[$count - 1];

        $minLat = $first[0];
        $maxLat = $first[0];
        $minLng = $first[1];
        $maxLng = $first[1];

        foreach ($coords as [$lat, $lng]) {
            if ($lat < $minLat) {
                $minLat = $lat;
            }

            if ($lat > $maxLat) {
                $maxLat = $lat;
            }

            if ($lng < $minLng) {
                $minLng = $lng;
            }

            if ($lng > $maxLng) {
                $maxLng = $lng;
            }
        }

        $startToEnd = round($this->haversineMeters($first[0], $first[1], $last[0], $last[1]), 1);

        /** @var list<array{0: float, 1: float}> $fingerprint */
        $fingerprint = [];

        if ($count === 1) {
            $fingerprint[] = [round($first[0], 6), round($first[1], 6)];
        } else {
            for ($i = 0; $i < self::FINGERPRINT_WAYPOINTS; $i++) {
                $index = (int) round(($i / (self::FINGERPRINT_WAYPOINTS - 1)) * ($count - 1));
                $waypoint = $coords[$index];
                $fingerprint[] = [round($waypoint[0], 6), round($waypoint[1], 6)];
            }
        }

        return [
            'end_lat' => round($last[0], 6),
            'end_lng' => round($last[1], 6),
            'start_to_end_meters' => $startToEnd,
            'is_loop' => $startToEnd <= self::LOOP_MAX_DISTANCE_METERS,
            'bbox_min_lat' => round($minLat, 6),
            'bbox_max_lat' => round($maxLat, 6),
            'bbox_min_lng' => round($minLng, 6),
            'bbox_max_lng' => round($maxLng, 6),
            'route_fingerprint' => $fingerprint,
        ];
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2.0) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2.0) ** 2);

        $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return self::EARTH_RADIUS_METERS * $c;
    }
}
