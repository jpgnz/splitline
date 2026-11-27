<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivityDerivedMetric;
use App\Models\ActivityStream;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Computes and persists derived aerobic and pacing metrics for streamed activities.
 */
class ActivityDerivedMetricsService
{
    /**
     * Minimum gap (seconds) between consecutive samples that is treated as a pause. Mirrored by
     * `resolvePauseThresholdSeconds()` in resources/js/lib/streamSelection.ts.
     */
    public const float PAUSE_MIN_SECONDS = 15.0;

    /**
     * Multiple of the median sampling interval that is treated as a pause for sparsely recorded streams.
     */
    public const float PAUSE_MEDIAN_MULTIPLIER = 4.0;

    private const float MIN_ACTIVE_HR = 40.0;

    private const float MIN_ACTIVE_SPEED_MPS = 0.5;

    private const float MIN_DECOUPLING_DURATION_SECONDS = 1200.0;

    private const int MIN_DECOUPLING_POINTS = 60;

    private const float METRIC_COVERAGE_THRESHOLD = 0.8;

    private const int NORMALIZED_POWER_WINDOW_SECONDS = 30;

    private const float MIN_FULL_SPLIT_METERS = 400.0;

    private const float NEGATIVE_SPLIT_THRESHOLD = 1.015;

    private const float POSITIVE_SPLIT_THRESHOLD = 0.985;

    /**
     * Compute and persist all derived metrics for an activity.
     */
    public function computeAndStore(Activity $activity, ?ActivityStream $stream = null): ActivityDerivedMetric
    {
        $resolvedStream = $stream ?? ($activity->relationLoaded('stream') ? $activity->stream : $activity->stream()->first());

        $series = is_array($resolvedStream?->series) ? $resolvedStream->series : [];
        $kmSplits = is_array($resolvedStream?->km_splits) ? $resolvedStream->km_splits : [];

        $aerobic = $this->computeAerobicDecoupling($series);
        $pacing = $this->computePacingStrategy($kmSplits);

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
     *
     * Each chunk eager-loads full telemetry series, so keep the chunk size small.
     */
    public function backfill(bool $force = false, int $chunkSize = 25): int
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
                $activity->unsetRelation('stream');
                $processed++;
            }
        });

        return $processed;
    }

    /**
     * Compute the polarized heart-rate split (Low Z1-Z2 / Moderate Z3 / High Z4-Z5) percentages
     * from the activity's current HR zone durations.
     *
     * @return array{hr_low_pct: ?float, hr_moderate_pct: ?float, hr_high_pct: ?float}
     */
    public function polarizedHrSplit(Activity $activity): array
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
     * Compute Aerobic Decoupling (Pw:Hr / Pa:Hr / Spd:Hr) and Efficiency Factor from telemetry series.
     *
     * Power-based EF uses Normalized Power (zeros from coasting included) over average heart rate;
     * speed-based EF uses time-weighted average speed in m/min over average heart rate. Samples
     * without heart rate, stopped samples, and pause gaps are excluded from both halves.
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

        $fallbackDt = $this->resolveMedianPositiveDt($points);
        $pauseThreshold = $this->resolvePauseThreshold($fallbackDt);

        /** @var list<array{time: float, active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float, resumes: bool}> $activePoints */
        $activePoints = [];
        $activeElapsed = 0.0;
        $prevTime = null;
        $prevActiveTime = null;

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
                'time' => $time,
                'active_t' => $activeElapsed,
                'dt' => $dt > 0.0 ? $dt : $fallbackDt,
                'hr' => $hr,
                'speed' => $speed,
                'gap_speed' => $point['gap_speed'],
                'power' => $power,
                'resumes' => $prevActiveTime === null || ($time - $prevActiveTime) > $pauseThreshold,
            ];

            $prevActiveTime = $time;
        }

        $activeCount = count($activePoints);

        if ($activeCount < self::MIN_DECOUPLING_POINTS || $activeElapsed < self::MIN_DECOUPLING_DURATION_SECONDS) {
            return $empty;
        }

        $powerCount = 0;
        $gapCount = 0;
        $speedCount = 0;

        foreach ($activePoints as $point) {
            if ($point['power'] !== null && $point['power'] >= 0.0) {
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

        $isPower = $metric === 'power';
        $midpoint = $activeElapsed / 2.0;

        /** @var array{0: list<array{time: float, active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float, resumes: bool}>, 1: list<array{time: float, active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float, resumes: bool}>} $halves */
        $halves = [[], []];

        foreach ($activePoints as $point) {
            $output = $point[$field];

            if ($output === null || ($isPower ? $output < 0.0 : $output <= 0.0)) {
                continue;
            }

            $halves[$point['active_t'] <= $midpoint ? 0 : 1][] = $point;
        }

        $ef1 = $this->efficiencyFactor($halves[0], $field, $scale, $isPower, $pauseThreshold);
        $ef2 = $this->efficiencyFactor($halves[1], $field, $scale, $isPower, $pauseThreshold);
        $overallEf = $this->efficiencyFactor([...$halves[0], ...$halves[1]], $field, $scale, $isPower, $pauseThreshold);

        if ($ef1 === null || $ef2 === null || $overallEf === null || $ef1 <= 0.0) {
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
     * Efficiency Factor for a run of active samples: output (NP for power, time-weighted average
     * for speed) divided by time-weighted average heart rate.
     *
     * @param  list<array{time: float, active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float, resumes: bool}>  $points
     */
    private function efficiencyFactor(array $points, string $field, float $scale, bool $isPower, float $pauseThreshold): ?float
    {
        $outputSum = 0.0;
        $hrSum = 0.0;
        $weight = 0.0;

        foreach ($points as $point) {
            $outputSum += (float) $point[$field] * $point['dt'];
            $hrSum += $point['hr'] * $point['dt'];
            $weight += $point['dt'];
        }

        if ($weight <= 0.0 || $hrSum <= 0.0) {
            return null;
        }

        $output = $isPower
            ? $this->normalizedPower($points, $pauseThreshold)
            : $outputSum / $weight;

        if ($output === null || $output <= 0.0) {
            return null;
        }

        return ($output * $scale) / ($hrSum / $weight);
    }

    /**
     * Coggan Normalized Power: 30-second rolling average of power resampled to a 1-second grid,
     * raised to the 4th power, averaged, then 4th-rooted. Zero-power (coasting) samples count;
     * the rolling window restarts after pauses.
     *
     * @param  list<array{time: float, active_t: float, dt: float, hr: float, speed: ?float, gap_speed: ?float, power: ?float, resumes: bool}>  $points
     */
    private function normalizedPower(array $points, float $pauseThreshold): ?float
    {
        $window = self::NORMALIZED_POWER_WINDOW_SECONDS;
        $ring = array_fill(0, $window, 0.0);
        $ringPos = 0;
        $ringCount = 0;
        $ringSum = 0.0;
        $fourthSum = 0.0;
        $fourthCount = 0;

        $push = function (float $watts) use (&$ring, &$ringPos, &$ringCount, &$ringSum, &$fourthSum, &$fourthCount, $window): void {
            if ($ringCount === $window) {
                $ringSum -= $ring[$ringPos];
            } else {
                $ringCount++;
            }

            $ring[$ringPos] = $watts;
            $ringSum += $watts;
            $ringPos = ($ringPos + 1) % $window;

            if ($ringCount === $window) {
                $fourthSum += ($ringSum / $window) ** 4;
                $fourthCount++;
            }
        };

        $resetRing = function () use (&$ringPos, &$ringCount, &$ringSum): void {
            $ringPos = 0;
            $ringCount = 0;
            $ringSum = 0.0;
        };

        $prevTime = null;
        $prevPower = null;

        foreach ($points as $point) {
            $power = (float) $point['power'];
            $time = $point['time'];

            if ($prevTime === null) {
                $push($power);
                $prevTime = $time;
                $prevPower = $power;

                continue;
            }

            $dt = (int) round($time - $prevTime);

            if ($dt <= 0) {
                continue;
            }

            if ($point['resumes'] || $dt > $pauseThreshold) {
                $resetRing();
                $push($power);
            } else {
                for ($step = 1; $step <= $dt; $step++) {
                    $push($prevPower + (($power - $prevPower) * $step) / $dt);
                }
            }

            $prevTime = $time;
            $prevPower = $power;
        }

        if ($fourthCount === 0) {
            return null;
        }

        return ($fourthSum / $fourthCount) ** 0.25;
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

    private function resolvePauseThreshold(float $medianDt): float
    {
        return max(self::PAUSE_MIN_SECONDS, $medianDt * self::PAUSE_MEDIAN_MULTIPLIER);
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
}
