<?php

namespace App\Services\Segments;

use App\Models\Activity;
use App\Services\Routes\RouteMatchingService;

/**
 * Detects sustained climbs from smoothed GNSS + elevation telemetry streams and categorizes them (Cat 4 -> HC).
 *
 * @phpstan-type StreamClimbPoint array{
 *     orig_index: int,
 *     time: float,
 *     distance: float,
 *     altitude: float,
 *     lat: float,
 *     lng: float
 * }
 * @phpstan-type DetectedClimb array{
 *     start_index: int,
 *     end_index: int,
 *     distance_meters: float,
 *     elevation_gain: float,
 *     elevation_loss: float,
 *     avg_grade_pct: float,
 *     max_grade_pct: float,
 *     fiets_score: float,
 *     climb_category: string
 * }
 */
class ClimbDetector
{
    /**
     * Outdoor sport categories eligible for automatic climb detection.
     *
     * @var array<string, float>
     */
    public const array MIN_DISTANCE_BY_SPORT = [
        'biking' => 400.0,
        'running' => 300.0,
        'hiking' => 300.0,
        'walking' => 300.0,
    ];

    public const float MIN_ELEVATION_GAIN_METERS = 30.0;

    public const float MIN_AVG_GRADE_PCT = 3.0;

    public const float UNCATEGORIZED_MIN_GAIN_METERS = 35.0;

    public const float UNCATEGORIZED_MIN_GRADE_PCT = 3.5;

    public const int SMOOTHING_WINDOW = 5;

    public const float MAX_DIP_DISTANCE_METERS = 150.0;

    public const float MAX_DIP_DROP_METERS = 15.0;

    public const float TAIL_TRIM_MIN_GRADE_PCT = 1.5;

    public const float TAIL_LOOKAHEAD_METERS = 40.0;

    public const float MAX_GRADE_SUBWINDOW_METERS = 100.0;

    public const float ALTITUDE_GAIN_NOISE_THRESHOLD = 0.2;

    /**
     * Determine whether an activity is eligible for automatic climb detection.
     */
    public function isEligibleActivity(Activity $activity): bool
    {
        if ($activity->is_indoor) {
            return false;
        }

        return isset(self::MIN_DISTANCE_BY_SPORT[$activity->sport_category]);
    }

    /**
     * Detect sustained climbs from an activity's stream series.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<DetectedClimb>
     */
    public function detect(Activity $activity, array $series): array
    {
        if (! $this->isEligibleActivity($activity)) {
            return [];
        }

        return $this->detectFromSeries($series, $activity->sport_category);
    }

    /**
     * Detect sustained climbs directly from a raw activity_streams.series payload for a given sport category.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<DetectedClimb>
     */
    public function detectFromSeries(array $series, string $sportCategory): array
    {
        if (! isset(self::MIN_DISTANCE_BY_SPORT[$sportCategory])) {
            return [];
        }

        $minDistance = self::MIN_DISTANCE_BY_SPORT[$sportCategory];
        $points = $this->extractValidPoints($series);
        $count = count($points);

        if ($count < 6) {
            return [];
        }

        $smoothedAlt = $this->smoothAltitudes($points, self::SMOOTHING_WINDOW);
        $detected = [];

        $inClimb = false;
        $climbStart = 0;
        $peakIdx = 0;
        $peakAlt = $smoothedAlt[0];
        $valleyIdx = 0;
        $valleyAlt = $smoothedAlt[0];

        $i = 0;

        while ($i < $count - 1) {
            if (! $inClimb) {
                if ($smoothedAlt[$i + 1] > $smoothedAlt[$i] + 0.05) {
                    $inClimb = true;
                    $climbStart = $i;
                    $peakIdx = $i + 1;
                    $peakAlt = $smoothedAlt[$i + 1];
                    $valleyIdx = $i + 1;
                    $valleyAlt = $smoothedAlt[$i + 1];
                }

                $i++;

                continue;
            }

            $next = $i + 1;
            $altNext = $smoothedAlt[$next];

            if ($altNext > $peakAlt + 0.05) {
                $peakIdx = $next;
                $peakAlt = $altNext;
                $valleyIdx = $next;
                $valleyAlt = $altNext;
                $i++;

                continue;
            }

            if ($altNext < $valleyAlt) {
                $valleyAlt = $altNext;
                $valleyIdx = $next;
            }

            $dipDistance = $points[$next]['distance'] - $points[$peakIdx]['distance'];
            $dipDrop = $peakAlt - $altNext;
            $spanDistance = max(1.0, $points[$next]['distance'] - $points[$climbStart]['distance']);
            $overallGrade = (($altNext - $smoothedAlt[$climbStart]) / $spanDistance) * 100.0;
            $isRecovering = $altNext > $smoothedAlt[$next - 1] + 0.05;
            $maxAllowedDipSpan = $isRecovering
                ? self::MAX_DIP_DISTANCE_METERS * 2.0
                : self::MAX_DIP_DISTANCE_METERS;

            $dipExceeded = $dipDistance > $maxAllowedDipSpan
                || $dipDrop >= self::MAX_DIP_DROP_METERS
                || ($spanDistance >= self::MAX_DIP_DISTANCE_METERS && $dipDistance > 30.0 && $overallGrade < self::MIN_AVG_GRADE_PCT);

            if ($dipExceeded) {
                $climb = $this->finalizeCandidate($points, $smoothedAlt, $climbStart, $peakIdx, $minDistance);

                if ($climb !== null) {
                    $detected[] = $climb;
                }

                $inClimb = false;
                $i = max($peakIdx + 1, $valleyIdx);

                continue;
            }

            $i++;
        }

        if ($inClimb) {
            $climb = $this->finalizeCandidate($points, $smoothedAlt, $climbStart, $peakIdx, $minDistance);

            if ($climb !== null) {
                $detected[] = $climb;
            }
        }

        return $this->deduplicateOverlapping($detected);
    }

    /**
     * Classify climb difficulty using Strava/FIETS distance × avg_grade_pct score thresholds.
     */
    public static function categorizeClimb(float $distanceMeters, float $avgGradePct): string
    {
        $score = $distanceMeters * $avgGradePct;

        if ($score >= 80000.0) {
            return 'hc';
        }

        if ($score >= 64000.0) {
            return 'cat1';
        }

        if ($score >= 32000.0) {
            return 'cat2';
        }

        if ($score >= 16000.0) {
            return 'cat3';
        }

        if ($score >= 8000.0) {
            return 'cat4';
        }

        return 'uncategorized';
    }

    /**
     * Compute the FIETS climb difficulty index: (gain^2) / (distance * 10).
     */
    public static function computeFietsScore(float $elevationGain, float $distanceMeters): float
    {
        if ($distanceMeters <= 0.0 || $elevationGain <= 0.0) {
            return 0.0;
        }

        return round(($elevationGain * $elevationGain) / ($distanceMeters * 10.0), 2);
    }

    /**
     * Compute max grade percentage over ~100m sub-windows across a smoothed elevation slice.
     *
     * @param  list<StreamClimbPoint>  $points
     * @param  list<float>  $smoothedAlt
     */
    public function computeMaxGrade(array $points, array $smoothedAlt, int $start, int $end): float
    {
        $maxGrade = 0.0;
        $j = $start + 1;

        for ($i = $start; $i < $end; $i++) {
            if ($j <= $i) {
                $j = $i + 1;
            }

            while ($j < $end && ($points[$j]['distance'] - $points[$i]['distance']) < self::MAX_GRADE_SUBWINDOW_METERS) {
                $j++;
            }

            $span = $points[$j]['distance'] - $points[$i]['distance'];

            if ($span < 25.0) {
                continue;
            }

            $grade = (($smoothedAlt[$j] - $smoothedAlt[$i]) / $span) * 100.0;

            if ($grade > $maxGrade) {
                $maxGrade = $grade;
            }
        }

        return round(min(45.0, max(0.0, $maxGrade)), 1);
    }

    /**
     * Trim leading/trailing flat tails, compute climb metrics, and apply distance/gain/grade/category filters.
     *
     * @param  list<StreamClimbPoint>  $points
     * @param  list<float>  $smoothedAlt
     * @return DetectedClimb|null
     */
    private function finalizeCandidate(
        array $points,
        array $smoothedAlt,
        int $start,
        int $end,
        float $minDistance,
    ): ?array {
        if ($end <= $start) {
            return null;
        }

        [$start, $end] = $this->trimFlatTails($points, $smoothedAlt, $start, $end);

        if ($end <= $start) {
            return null;
        }

        $distance = $points[$end]['distance'] - $points[$start]['distance'];

        if ($distance < $minDistance) {
            return null;
        }

        $netGain = $smoothedAlt[$end] - $smoothedAlt[$start];

        if ($netGain < self::MIN_ELEVATION_GAIN_METERS) {
            return null;
        }

        $gain = 0.0;
        $loss = 0.0;

        for ($k = $start + 1; $k <= $end; $k++) {
            $delta = $smoothedAlt[$k] - $smoothedAlt[$k - 1];

            if ($delta > self::ALTITUDE_GAIN_NOISE_THRESHOLD) {
                $gain += $delta;
            } elseif ($delta < -self::ALTITUDE_GAIN_NOISE_THRESHOLD) {
                $loss += abs($delta);
            }
        }

        $effectiveGain = max($netGain, $gain);

        if ($effectiveGain < self::MIN_ELEVATION_GAIN_METERS) {
            return null;
        }

        $avgGradePct = ($netGain / $distance) * 100.0;

        if ($avgGradePct < self::MIN_AVG_GRADE_PCT) {
            return null;
        }

        $category = self::categorizeClimb($distance, $avgGradePct);

        if (
            $category === 'uncategorized'
            && ($effectiveGain < self::UNCATEGORIZED_MIN_GAIN_METERS || $avgGradePct < self::UNCATEGORIZED_MIN_GRADE_PCT)
        ) {
            return null;
        }

        $roundedDistance = round($distance, 1);
        $roundedGain = round($effectiveGain, 1);
        $roundedLoss = round($loss, 1);
        $roundedAvgGrade = round($avgGradePct, 2);
        $maxGrade = max(round($avgGradePct, 1), $this->computeMaxGrade($points, $smoothedAlt, $start, $end));

        return [
            'start_index' => $points[$start]['orig_index'],
            'end_index' => $points[$end]['orig_index'],
            'distance_meters' => $roundedDistance,
            'elevation_gain' => $roundedGain,
            'elevation_loss' => $roundedLoss,
            'avg_grade_pct' => $roundedAvgGrade,
            'max_grade_pct' => $maxGrade,
            'fiets_score' => self::computeFietsScore($roundedGain, $roundedDistance),
            'climb_category' => $category,
        ];
    }

    /**
     * Trim leading and trailing flat/downhill tails so the climb starts at the foot and ends at the summit.
     *
     * @param  list<StreamClimbPoint>  $points
     * @param  list<float>  $smoothedAlt
     * @return array{0: int, 1: int}
     */
    private function trimFlatTails(array $points, array $smoothedAlt, int $start, int $end): array
    {
        // Snap end back to true summit peak if trailing points dropped or stayed flat.
        $peakIdx = $start;
        $peakAlt = $smoothedAlt[$start];

        for ($k = $start + 1; $k <= $end; $k++) {
            if ($smoothedAlt[$k] > $peakAlt + 0.05) {
                $peakAlt = $smoothedAlt[$k];
                $peakIdx = $k;
            }
        }

        $end = $peakIdx;

        // Trim leading flat/downhill tail.
        while ($start < $end) {
            $look = $start + 1;

            while (
                $look < $end
                && ($points[$look]['distance'] - $points[$start]['distance']) < self::TAIL_LOOKAHEAD_METERS
            ) {
                $look++;
            }

            $span = $points[$look]['distance'] - $points[$start]['distance'];
            $localGrade = $span > 0.0
                ? (($smoothedAlt[$look] - $smoothedAlt[$start]) / $span) * 100.0
                : 0.0;

            if ($smoothedAlt[$start + 1] <= $smoothedAlt[$start] + 0.02 || $localGrade < self::TAIL_TRIM_MIN_GRADE_PCT) {
                $start++;

                continue;
            }

            break;
        }

        // Trim trailing flat tail.
        while ($end > $start) {
            $look = $end - 1;

            while (
                $look > $start
                && ($points[$end]['distance'] - $points[$look]['distance']) < self::TAIL_LOOKAHEAD_METERS
            ) {
                $look--;
            }

            $span = $points[$end]['distance'] - $points[$look]['distance'];
            $localGrade = $span > 0.0
                ? (($smoothedAlt[$end] - $smoothedAlt[$look]) / $span) * 100.0
                : 0.0;

            if ($smoothedAlt[$end] <= $smoothedAlt[$end - 1] + 0.02 || $localGrade < self::TAIL_TRIM_MIN_GRADE_PCT) {
                $end--;

                continue;
            }

            break;
        }

        return [$start, $end];
    }

    /**
     * Extract valid GNSS + elevation points with monotonic distance while retaining original stream indices.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<StreamClimbPoint>
     */
    private function extractValidPoints(array $series): array
    {
        $points = [];
        $cumulativeFallback = 0.0;
        $prevLat = null;
        $prevLng = null;

        foreach ($series as $idx => $raw) {
            if (! is_array($raw) || ! is_int($idx)) {
                continue;
            }

            $rawAlt = $raw['altitude'] ?? null;
            $rawLat = $raw['lat'] ?? null;
            $rawLng = $raw['lng'] ?? null;

            if (! is_numeric($rawAlt) || ! is_numeric($rawLat) || ! is_numeric($rawLng)) {
                continue;
            }

            $alt = (float) $rawAlt;
            $lat = (float) $rawLat;
            $lng = (float) $rawLng;

            if (
                ! is_finite($alt)
                || ! is_finite($lat)
                || ! is_finite($lng)
                || $lat < -90.0
                || $lat > 90.0
                || $lng < -180.0
                || $lng > 180.0
            ) {
                continue;
            }

            if ($prevLat !== null && $prevLng !== null) {
                $cumulativeFallback += RouteMatchingService::haversineMeters($prevLat, $prevLng, $lat, $lng);
            }

            $prevLat = $lat;
            $prevLng = $lng;

            $distance = isset($raw['distance']) && is_numeric($raw['distance'])
                ? (float) $raw['distance']
                : $cumulativeFallback;

            $time = isset($raw['time']) && is_numeric($raw['time'])
                ? (float) $raw['time']
                : (float) $idx;

            $points[] = [
                'orig_index' => $idx,
                'time' => $time,
                'distance' => $distance,
                'altitude' => $alt,
                'lat' => $lat,
                'lng' => $lng,
            ];
        }

        return $points;
    }

    /**
     * Centered moving-average altitude smoothing to remove GPS/barometer jitter.
     *
     * @param  list<StreamClimbPoint>  $points
     * @return list<float>
     */
    private function smoothAltitudes(array $points, int $window): array
    {
        $count = count($points);
        $radius = max(1, intdiv($window, 2));
        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            $lo = max(0, $i - $radius);
            $hi = min($count - 1, $i + $radius);
            $sum = 0.0;

            for ($k = $lo; $k <= $hi; $k++) {
                $sum += $points[$k]['altitude'];
            }

            $smoothed[] = $sum / ($hi - $lo + 1);
        }

        return $smoothed;
    }

    /**
     * Ensure returned climbs are non-overlapping and sorted chronologically by stream index.
     *
     * @param  list<DetectedClimb>  $climbs
     * @return list<DetectedClimb>
     */
    private function deduplicateOverlapping(array $climbs): array
    {
        if (count($climbs) <= 1) {
            return $climbs;
        }

        usort($climbs, static fn (array $a, array $b): int => $a['start_index'] <=> $b['start_index']);

        $result = [];

        foreach ($climbs as $climb) {
            if ($result === []) {
                $result[] = $climb;

                continue;
            }

            $lastIdx = count($result) - 1;
            $prev = $result[$lastIdx];

            if ($climb['start_index'] <= $prev['end_index']) {
                if ($climb['fiets_score'] > $prev['fiets_score']) {
                    $result[$lastIdx] = $climb;
                }

                continue;
            }

            $result[] = $climb;
        }

        return $result;
    }
}
