<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\ActivityWeather;

/**
 * Builds side-by-side activity comparisons ("Ghost Pacer"):
 * headline KPI + weather diffs, distance- or time-aligned interpolated telemetry
 * and time-gap curves, and aligned kilometer split diffs.
 *
 * @phpstan-type StreamPoint array{
 *     time: float,
 *     distance: ?float,
 *     speed: ?float,
 *     gap_speed: ?float,
 *     hr: ?float,
 *     power: ?float,
 *     altitude: ?float,
 *     cadence: ?float
 * }
 * @phpstan-type ComparisonWeather array{
 *     condition: string,
 *     icon: string,
 *     temperature_c: ?float,
 *     apparent_temperature_c: ?float,
 *     humidity_pct: ?int,
 *     wind_speed_kmh: ?float,
 *     headwind_pct: ?float,
 *     tailwind_pct: ?float,
 *     avg_effective_wind_kmh: ?float
 * }
 * @phpstan-type ComparisonDeltas array{
 *     duration_delta_s: ?float,
 *     moving_duration_delta_s: ?float,
 *     distance_delta_m: ?float,
 *     elevation_gain_delta_m: ?float,
 *     speed_delta: ?float,
 *     gap_speed_delta: ?float,
 *     hr_delta: ?int,
 *     max_hr_delta: ?int,
 *     power_delta: ?float,
 *     normalized_power_delta: ?float,
 *     cadence_delta: ?float,
 *     training_load_delta: ?float,
 *     tss_delta: ?float,
 *     calories_delta: ?int,
 *     temp_delta_c: ?float,
 *     wind_delta_kmh: ?float,
 *     effective_wind_delta_kmh: ?float
 * }
 * @phpstan-type ComparisonActivity array{
 *     id: int,
 *     name: string,
 *     started_at_local: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     is_indoor: bool,
 *     location_name: ?string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     moving_duration_seconds: ?float,
 *     elevation_gain: ?float,
 *     avg_speed: ?float,
 *     avg_grade_adjusted_speed: ?float,
 *     avg_hr: ?int,
 *     max_hr: ?int,
 *     avg_power: ?float,
 *     normalized_power: ?float,
 *     avg_cadence: ?float,
 *     training_load: ?float,
 *     tss: ?float,
 *     calories: ?int,
 *     has_streams: bool,
 *     is_baseline: bool,
 *     weather: ?ComparisonWeather,
 *     deltas: ?ComparisonDeltas
 * }
 * @phpstan-type ComparisonCandidate array{
 *     id: int,
 *     name: string,
 *     started_at_local: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     is_indoor: bool,
 *     location_name: ?string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     avg_speed: ?float,
 *     avg_hr: ?int,
 *     has_streams: bool
 * }
 * @phpstan-type AlignedSeriesSummary array{
 *     final_time_delta_seconds: ?float,
 *     max_ahead_seconds: ?float,
 *     max_behind_seconds: ?float,
 *     final_distance_delta_meters: ?float
 * }
 * @phpstan-type AlignedTelemetrySeries array{
 *     activity_id: int,
 *     is_baseline: bool,
 *     has_stream: bool,
 *     elapsed_seconds: list<?float>,
 *     distance_meters: list<?float>,
 *     time_delta_seconds: list<?float>,
 *     distance_delta_meters: list<?float>,
 *     speed: list<?float>,
 *     gap_speed: list<?float>,
 *     hr: list<?float>,
 *     power: list<?float>,
 *     altitude: list<?float>,
 *     cadence: list<?float>,
 *     summary: AlignedSeriesSummary
 * }
 * @phpstan-type AlignedTelemetryPayload array{
 *     mode: 'distance'|'time',
 *     can_align_distance: bool,
 *     axis_max: float,
 *     bins: list<float>,
 *     has_speed: bool,
 *     has_gap: bool,
 *     has_hr: bool,
 *     has_power: bool,
 *     has_altitude: bool,
 *     has_cadence: bool,
 *     series: list<AlignedTelemetrySeries>
 * }
 * @phpstan-type AlignedSplitEntry array{
 *     activity_id: int,
 *     distance_meters: ?float,
 *     duration_seconds: ?float,
 *     avg_speed: ?float,
 *     pace_seconds_per_km: ?float,
 *     gap_seconds_per_km: ?float,
 *     avg_hr: ?int,
 *     avg_power: ?float,
 *     elevation_gain: ?float,
 *     elevation_loss: ?float,
 *     elevation_delta: ?float,
 *     time_delta_seconds: ?float,
 *     hr_delta: ?int,
 *     is_partial: bool
 * }
 * @phpstan-type AlignedSplitRow array{
 *     split_index: int,
 *     label: string,
 *     entries: list<AlignedSplitEntry>
 * }
 */
class ActivityComparisonService
{
    public const int MAX_ACTIVITIES = 3;

    public const int MAX_CANDIDATES = 40;

    public const int SAMPLE_BINS = 160;

    /**
     * Parse requested activity IDs from comma-separated string, array, or integer.
     * Preserves requested order, deduplicates, and caps at MAX_ACTIVITIES.
     *
     * @return list<int>
     */
    public function parseIds(mixed $rawIds): array
    {
        $tokens = [];

        if (is_int($rawIds)) {
            $tokens[] = (string) $rawIds;
        } elseif (is_string($rawIds)) {
            $tokens = explode(',', $rawIds);
        } elseif (is_array($rawIds)) {
            foreach ($rawIds as $item) {
                if (is_int($item)) {
                    $tokens[] = (string) $item;
                } elseif (is_string($item)) {
                    foreach (explode(',', $item) as $part) {
                        $tokens[] = $part;
                    }
                }
            }
        }

        $ids = [];

        foreach ($tokens as $token) {
            $trimmed = trim($token);

            if ($trimmed === '' || ctype_digit($trimmed) === false) {
                continue;
            }

            $id = (int) $trimmed;

            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;

            if (count($ids) >= self::MAX_ACTIVITIES) {
                break;
            }
        }

        return $ids;
    }

    /**
     * Normalize requested alignment axis.
     *
     * @return 'distance'|'time'
     */
    public function normalizeAlign(mixed $align): string
    {
        return $align === 'time' ? 'time' : 'distance';
    }

    /**
     * Build the full side-by-side comparison payload for up to 3 activities.
     *
     * @return array{
     *     align: 'distance'|'time',
     *     requested_align: 'distance'|'time',
     *     fallback_reason: ?string,
     *     selected_ids: list<int>,
     *     activities: list<ComparisonActivity>,
     *     candidates: list<ComparisonCandidate>,
     *     aligned: AlignedTelemetryPayload,
     *     splits: list<AlignedSplitRow>
     * }
     */
    public function compare(mixed $rawIds = null, mixed $align = 'distance'): array
    {
        $requestedIds = $this->parseIds($rawIds);
        $requestedAlign = $this->normalizeAlign($align);

        /** @var list<Activity> $activities */
        $activities = [];

        if ($requestedIds !== []) {
            $found = Activity::query()
                ->with(['stream', 'weather'])
                ->whereIn('id', $requestedIds)
                ->get()
                ->keyBy('id');

            foreach ($requestedIds as $id) {
                $activity = $found->get($id);

                if ($activity instanceof Activity) {
                    $activities[] = $activity;
                }
            }
        }

        $selectedIds = array_map(
            static fn (Activity $activity): int => $activity->id,
            $activities,
        );

        /** @var array<int, list<StreamPoint>> $normalizedSeries */
        $normalizedSeries = [];

        foreach ($activities as $activity) {
            $normalizedSeries[$activity->id] = $this->normalizeSeries($activity->stream?->series);
        }

        $baseline = $activities[0] ?? null;
        $mappedActivities = [];

        foreach ($activities as $index => $activity) {
            $hasStreams = count($normalizedSeries[$activity->id]) >= 2;
            $mappedActivities[] = $this->mapActivity(
                $activity,
                $index === 0 ? null : $baseline,
                $hasStreams,
            );
        }

        $candidates = $this->fetchCandidates(
            $selectedIds,
            $baseline?->sport_category,
        );

        [$aligned, $fallbackReason] = $this->buildAlignedTelemetry(
            $activities,
            $normalizedSeries,
            $requestedAlign,
        );

        $splits = $this->buildAlignedSplits($activities);

        return [
            'align' => $aligned['mode'],
            'requested_align' => $requestedAlign,
            'fallback_reason' => $fallbackReason,
            'selected_ids' => $selectedIds,
            'activities' => $mappedActivities,
            'candidates' => $candidates,
            'aligned' => $aligned,
            'splits' => $splits,
        ];
    }

    /**
     * @return ComparisonActivity
     */
    private function mapActivity(Activity $activity, ?Activity $baseline, bool $hasStreams): array
    {
        $weather = $this->mapWeather($activity->weather);
        $deltas = $baseline !== null
            ? $this->computeDeltas($activity, $baseline)
            : null;

        return [
            'id' => $activity->id,
            'name' => $activity->name,
            'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
            'sport_category' => $activity->sport_category,
            'effective_discipline' => $activity->effectiveDiscipline(),
            'is_indoor' => $activity->is_indoor,
            'location_name' => $activity->location_name,
            'distance_meters' => $activity->distance_meters,
            'duration_seconds' => $activity->duration_seconds,
            'moving_duration_seconds' => $activity->moving_duration_seconds,
            'elevation_gain' => $activity->elevation_gain,
            'avg_speed' => $activity->avg_speed,
            'avg_grade_adjusted_speed' => $activity->avg_grade_adjusted_speed,
            'avg_hr' => $activity->avg_hr,
            'max_hr' => $activity->max_hr,
            'avg_power' => $activity->avg_power,
            'normalized_power' => $activity->normalized_power,
            'avg_cadence' => $activity->avg_cadence,
            'training_load' => $activity->training_load,
            'tss' => $activity->tss,
            'calories' => $activity->calories,
            'has_streams' => $hasStreams,
            'is_baseline' => $baseline === null,
            'weather' => $weather,
            'deltas' => $deltas,
        ];
    }

    /**
     * @return ?ComparisonWeather
     */
    private function mapWeather(?ActivityWeather $weather): ?array
    {
        if ($weather === null) {
            return null;
        }

        return [
            'condition' => $weather->condition,
            'icon' => $weather->icon,
            'temperature_c' => $weather->temperature_c,
            'apparent_temperature_c' => $weather->apparent_temperature_c,
            'humidity_pct' => $weather->humidity_pct,
            'wind_speed_kmh' => $weather->wind_speed_kmh,
            'headwind_pct' => $weather->headwind_pct,
            'tailwind_pct' => $weather->tailwind_pct,
            'avg_effective_wind_kmh' => $weather->avg_effective_wind_kmh,
        ];
    }

    /**
     * @return ComparisonDeltas
     */
    private function computeDeltas(Activity $activity, Activity $baseline): array
    {
        $weather = $activity->weather;
        $baseWeather = $baseline->weather;

        return [
            'duration_delta_s' => round($activity->duration_seconds - $baseline->duration_seconds, 1),
            'moving_duration_delta_s' => $this->diffFloat($activity->moving_duration_seconds, $baseline->moving_duration_seconds, 1),
            'distance_delta_m' => round($activity->distance_meters - $baseline->distance_meters, 1),
            'elevation_gain_delta_m' => $this->diffFloat($activity->elevation_gain, $baseline->elevation_gain, 1),
            'speed_delta' => $this->diffFloat($activity->avg_speed, $baseline->avg_speed, 4),
            'gap_speed_delta' => $this->diffFloat($activity->avg_grade_adjusted_speed, $baseline->avg_grade_adjusted_speed, 4),
            'hr_delta' => $this->diffInt($activity->avg_hr, $baseline->avg_hr),
            'max_hr_delta' => $this->diffInt($activity->max_hr, $baseline->max_hr),
            'power_delta' => $this->diffFloat($activity->avg_power, $baseline->avg_power, 1),
            'normalized_power_delta' => $this->diffFloat($activity->normalized_power, $baseline->normalized_power, 1),
            'cadence_delta' => $this->diffFloat($activity->avg_cadence, $baseline->avg_cadence, 1),
            'training_load_delta' => $this->diffFloat($activity->training_load, $baseline->training_load, 1),
            'tss_delta' => $this->diffFloat($activity->tss, $baseline->tss, 1),
            'calories_delta' => $this->diffInt($activity->calories, $baseline->calories),
            'temp_delta_c' => $this->diffFloat($weather?->temperature_c, $baseWeather?->temperature_c, 1),
            'wind_delta_kmh' => $this->diffFloat($weather?->wind_speed_kmh, $baseWeather?->wind_speed_kmh, 1),
            'effective_wind_delta_kmh' => $this->diffFloat($weather?->avg_effective_wind_kmh, $baseWeather?->avg_effective_wind_kmh, 1),
        ];
    }

    /**
     * Fetch up to MAX_CANDIDATES recent activities (filtered to baseline sport_category when selected).
     *
     * @param  list<int>  $selectedIds
     * @return list<ComparisonCandidate>
     */
    private function fetchCandidates(array $selectedIds, ?string $sportCategory): array
    {
        $query = Activity::query()
            ->select([
                'id',
                'name',
                'started_at_local',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'is_indoor',
                'location_name',
                'distance_meters',
                'duration_seconds',
                'avg_speed',
                'avg_hr',
                'streams_synced_at',
            ])
            ->withExists('stream')
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATES);

        if ($sportCategory !== null) {
            $query->where('sport_category', $sportCategory);
        }

        if ($selectedIds !== []) {
            $query->whereNotIn('id', $selectedIds);
        }

        $rows = $query->get();
        $candidates = [];

        foreach ($rows as $activity) {
            $candidates[] = [
                'id' => $activity->id,
                'name' => $activity->name,
                'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
                'sport_category' => $activity->sport_category,
                'effective_discipline' => $activity->effectiveDiscipline(),
                'is_indoor' => $activity->is_indoor,
                'location_name' => $activity->location_name,
                'distance_meters' => $activity->distance_meters,
                'duration_seconds' => $activity->duration_seconds,
                'avg_speed' => $activity->avg_speed,
                'avg_hr' => $activity->avg_hr,
                'has_streams' => $activity->streams_synced_at !== null || (bool) $activity->getAttribute('stream_exists'),
            ];
        }

        return $candidates;
    }

    /**
     * Normalize stream series from either row-oriented points or columnar arrays.
     *
     * @param  array<int|string, mixed>|null  $rawSeries
     * @return list<StreamPoint>
     */
    private function normalizeSeries(?array $rawSeries): array
    {
        if ($rawSeries === null || $rawSeries === []) {
            return [];
        }

        $points = [];

        if (isset($rawSeries['time']) && is_array($rawSeries['time'])) {
            $times = array_values($rawSeries['time']);
            $distances = is_array($rawSeries['distance'] ?? null) ? array_values($rawSeries['distance']) : [];
            $speeds = is_array($rawSeries['speed'] ?? null) ? array_values($rawSeries['speed']) : [];
            $gapSpeeds = is_array($rawSeries['gap_speed'] ?? null) ? array_values($rawSeries['gap_speed']) : [];
            $hrs = is_array($rawSeries['hr'] ?? null) ? array_values($rawSeries['hr']) : [];
            $powers = is_array($rawSeries['power'] ?? null) ? array_values($rawSeries['power']) : [];
            $altitudes = is_array($rawSeries['altitude'] ?? null) ? array_values($rawSeries['altitude']) : [];
            $cadences = is_array($rawSeries['cadence'] ?? null) ? array_values($rawSeries['cadence']) : [];

            foreach ($times as $idx => $time) {
                if (! is_numeric($time)) {
                    continue;
                }

                $points[] = [
                    'time' => (float) $time,
                    'distance' => $this->toNullableFloat($distances[$idx] ?? null),
                    'speed' => $this->toNullableFloat($speeds[$idx] ?? null),
                    'gap_speed' => $this->toNullableFloat($gapSpeeds[$idx] ?? null),
                    'hr' => $this->toNullableFloat($hrs[$idx] ?? null),
                    'power' => $this->toNullableFloat($powers[$idx] ?? null),
                    'altitude' => $this->toNullableFloat($altitudes[$idx] ?? null),
                    'cadence' => $this->toNullableFloat($cadences[$idx] ?? null),
                ];
            }
        } else {
            foreach ($rawSeries as $row) {
                if (! is_array($row) || ! isset($row['time']) || ! is_numeric($row['time'])) {
                    continue;
                }

                $points[] = [
                    'time' => (float) $row['time'],
                    'distance' => $this->toNullableFloat($row['distance'] ?? null),
                    'speed' => $this->toNullableFloat($row['speed'] ?? null),
                    'gap_speed' => $this->toNullableFloat($row['gap_speed'] ?? null),
                    'hr' => $this->toNullableFloat($row['hr'] ?? null),
                    'power' => $this->toNullableFloat($row['power'] ?? null),
                    'altitude' => $this->toNullableFloat($row['altitude'] ?? null),
                    'cadence' => $this->toNullableFloat($row['cadence'] ?? null),
                ];
            }
        }

        if ($points === []) {
            return [];
        }

        // Normalize Unix epoch timestamps if ever encountered
        $firstTime = $points[0]['time'];

        if ($firstTime > 1_000_000_000.0) {
            foreach ($points as $idx => $point) {
                $points[$idx]['time'] = max(0.0, $point['time'] - $firstTime);
            }
        }

        return $points;
    }

    /**
     * Build distance- or time-aligned telemetry curves and Ghost Pacer gap deltas.
     *
     * @param  list<Activity>  $activities
     * @param  array<int, list<StreamPoint>>  $normalizedSeries
     * @param  'distance'|'time'  $requestedAlign
     * @return array{0: AlignedTelemetryPayload, 1: ?string}
     */
    private function buildAlignedTelemetry(
        array $activities,
        array $normalizedSeries,
        string $requestedAlign,
    ): array {
        if (count($activities) < 2) {
            return [$this->emptyAlignedPayload($requestedAlign, false), null];
        }

        /** @var array<int, list<StreamPoint>> $distanceStreams */
        $distanceStreams = [];
        /** @var array<int, float> $maxDistances */
        $maxDistances = [];

        /** @var array<int, list<StreamPoint>> $timeStreams */
        $timeStreams = [];
        /** @var array<int, float> $maxTimes */
        $maxTimes = [];

        foreach ($activities as $activity) {
            $raw = $normalizedSeries[$activity->id] ?? [];

            $distPoints = $this->extractMonotonicPoints($raw, 'distance');
            $lastDist = $distPoints !== [] ? ($distPoints[count($distPoints) - 1]['distance'] ?? 0.0) : 0.0;

            if (count($distPoints) >= 2 && $lastDist > 0.0) {
                $distanceStreams[$activity->id] = $distPoints;
                $maxDistances[$activity->id] = $lastDist;
            }

            $timePoints = $this->extractMonotonicPoints($raw, 'time');
            $lastTime = $timePoints !== [] ? $timePoints[count($timePoints) - 1]['time'] : 0.0;

            if (count($timePoints) >= 2 && $lastTime > 0.0) {
                $timeStreams[$activity->id] = $timePoints;
                $maxTimes[$activity->id] = $lastTime;
            }
        }

        $baselineId = $activities[0]->id;
        $canAlignDistance = isset($distanceStreams[$baselineId]) && count($distanceStreams) >= 2;
        $canAlignTime = isset($timeStreams[$baselineId]) && count($timeStreams) >= 2;

        $effectiveAlign = $requestedAlign;
        $fallbackReason = null;

        if ($requestedAlign === 'distance' && ! $canAlignDistance) {
            if ($canAlignTime) {
                $effectiveAlign = 'time';
                $fallbackReason = 'missing_distance_stream';
            } else {
                return [$this->emptyAlignedPayload('distance', false), 'missing_streams'];
            }
        }

        if ($effectiveAlign === 'time' && ! $canAlignTime) {
            return [$this->emptyAlignedPayload('time', $canAlignDistance), 'missing_streams'];
        }

        $axisMax = 0.0;

        if ($effectiveAlign === 'distance' && $maxDistances !== []) {
            $axisMax = min($maxDistances);
        } elseif ($effectiveAlign === 'time' && $maxTimes !== []) {
            $axisMax = min($maxTimes);
        }

        if ($axisMax <= 0.0) {
            return [$this->emptyAlignedPayload($effectiveAlign, $canAlignDistance), 'missing_streams'];
        }

        $binCount = self::SAMPLE_BINS;
        $bins = [];

        for ($i = 0; $i < $binCount; $i++) {
            $bins[] = round(($axisMax * $i) / ($binCount - 1), 2);
        }

        $rawSampled = [];

        foreach ($activities as $index => $activity) {
            $points = $effectiveAlign === 'distance'
                ? ($distanceStreams[$activity->id] ?? null)
                : ($timeStreams[$activity->id] ?? null);

            if ($points === null) {
                $rawSampled[$index] = null;

                continue;
            }

            $rawSampled[$index] = $this->sampleStreamAtBins($points, $bins, $effectiveAlign);
        }

        $baselineSampled = $rawSampled[0];
        $series = [];

        $hasSpeed = false;
        $hasGap = false;
        $hasHr = false;
        $hasPower = false;
        $hasAltitude = false;
        $hasCadence = false;

        foreach ($activities as $index => $activity) {
            $sampled = $rawSampled[$index];
            $isBaseline = $index === 0;

            if ($sampled === null) {
                $nulls = array_fill(0, $binCount, null);
                $series[] = [
                    'activity_id' => $activity->id,
                    'is_baseline' => $isBaseline,
                    'has_stream' => false,
                    'elapsed_seconds' => $nulls,
                    'distance_meters' => $nulls,
                    'time_delta_seconds' => $nulls,
                    'distance_delta_meters' => $nulls,
                    'speed' => $nulls,
                    'gap_speed' => $nulls,
                    'hr' => $nulls,
                    'power' => $nulls,
                    'altitude' => $nulls,
                    'cadence' => $nulls,
                    'summary' => [
                        'final_time_delta_seconds' => null,
                        'max_ahead_seconds' => null,
                        'max_behind_seconds' => null,
                        'final_distance_delta_meters' => null,
                    ],
                ];

                continue;
            }

            $hasSpeed = $hasSpeed || $this->hasAnyPositiveValue($sampled['speed']);
            $hasGap = $hasGap || $this->hasAnyPositiveValue($sampled['gap_speed']);
            $hasHr = $hasHr || $this->hasAnyNonNullValue($sampled['hr']);
            $hasPower = $hasPower || $this->hasAnyPositiveValue($sampled['power']);
            $hasAltitude = $hasAltitude || $this->hasAnyNonNullValue($sampled['altitude']);
            $hasCadence = $hasCadence || $this->hasAnyPositiveValue($sampled['cadence']);

            $timeDeltas = [];
            $distanceDeltas = [];
            $finalTimeDelta = null;
            $maxAhead = null;
            $maxBehind = null;
            $finalDistanceDelta = null;

            for ($i = 0; $i < $binCount; $i++) {
                if ($effectiveAlign === 'distance') {
                    $distanceDeltas[] = null;

                    if ($isBaseline) {
                        $timeDeltas[] = 0.0;
                    } elseif (
                        $baselineSampled !== null
                        && $sampled['elapsed_seconds'][$i] !== null
                        && $baselineSampled['elapsed_seconds'][$i] !== null
                    ) {
                        $delta = round($sampled['elapsed_seconds'][$i] - $baselineSampled['elapsed_seconds'][$i], 2);
                        $timeDeltas[] = $delta;
                        $finalTimeDelta = $delta;

                        if ($maxAhead === null || $delta < $maxAhead) {
                            $maxAhead = $delta;
                        }

                        if ($maxBehind === null || $delta > $maxBehind) {
                            $maxBehind = $delta;
                        }
                    } else {
                        $timeDeltas[] = null;
                    }
                } else {
                    $timeDeltas[] = null;

                    if ($isBaseline) {
                        $distanceDeltas[] = 0.0;
                    } elseif (
                        $baselineSampled !== null
                        && $sampled['distance_meters'][$i] !== null
                        && $baselineSampled['distance_meters'][$i] !== null
                    ) {
                        $distDelta = round($sampled['distance_meters'][$i] - $baselineSampled['distance_meters'][$i], 1);
                        $distanceDeltas[] = $distDelta;
                        $finalDistanceDelta = $distDelta;
                    } else {
                        $distanceDeltas[] = null;
                    }
                }
            }

            $series[] = [
                'activity_id' => $activity->id,
                'is_baseline' => $isBaseline,
                'has_stream' => true,
                'elapsed_seconds' => $sampled['elapsed_seconds'],
                'distance_meters' => $sampled['distance_meters'],
                'time_delta_seconds' => $timeDeltas,
                'distance_delta_meters' => $distanceDeltas,
                'speed' => $sampled['speed'],
                'gap_speed' => $sampled['gap_speed'],
                'hr' => $sampled['hr'],
                'power' => $sampled['power'],
                'altitude' => $sampled['altitude'],
                'cadence' => $sampled['cadence'],
                'summary' => [
                    'final_time_delta_seconds' => $isBaseline ? 0.0 : $finalTimeDelta,
                    'max_ahead_seconds' => $isBaseline ? 0.0 : $maxAhead,
                    'max_behind_seconds' => $isBaseline ? 0.0 : $maxBehind,
                    'final_distance_delta_meters' => $isBaseline ? 0.0 : $finalDistanceDelta,
                ],
            ];
        }

        return [
            [
                'mode' => $effectiveAlign,
                'can_align_distance' => $canAlignDistance,
                'axis_max' => round($axisMax, 2),
                'bins' => $bins,
                'has_speed' => $hasSpeed,
                'has_gap' => $hasGap,
                'has_hr' => $hasHr,
                'has_power' => $hasPower,
                'has_altitude' => $hasAltitude,
                'has_cadence' => $hasCadence,
                'series' => $series,
            ],
            $fallbackReason,
        ];
    }

    /**
     * Filter stream points to those monotonically non-decreasing along the chosen axis.
     *
     * @param  list<StreamPoint>  $points
     * @param  'distance'|'time'  $axis
     * @return list<StreamPoint>
     */
    private function extractMonotonicPoints(array $points, string $axis): array
    {
        $filtered = [];
        $lastAxis = null;

        foreach ($points as $point) {
            $value = $point[$axis];

            if ($value === null || ! is_finite($value)) {
                continue;
            }

            if ($lastAxis !== null && $value < $lastAxis) {
                continue;
            }

            // Replace duplicate axis coordinate with the latest sample so interpolation spans cleanly
            if ($lastAxis !== null && $value === $lastAxis && $filtered !== []) {
                $filtered[count($filtered) - 1] = $point;

                continue;
            }

            $filtered[] = $point;
            $lastAxis = $value;
        }

        return $filtered;
    }

    /**
     * Linearly interpolate all telemetry channels across evenly spaced axis bins.
     *
     * @param  list<StreamPoint>  $points
     * @param  list<float>  $bins
     * @param  'distance'|'time'  $axis
     * @return array{
     *     elapsed_seconds: list<?float>,
     *     distance_meters: list<?float>,
     *     speed: list<?float>,
     *     gap_speed: list<?float>,
     *     hr: list<?float>,
     *     power: list<?float>,
     *     altitude: list<?float>,
     *     cadence: list<?float>
     * }
     */
    private function sampleStreamAtBins(array $points, array $bins, string $axis): array
    {
        $count = count($points);
        $cursor = 0;

        $elapsed = [];
        $distance = [];
        $speed = [];
        $gapSpeed = [];
        $hr = [];
        $power = [];
        $altitude = [];
        $cadence = [];

        foreach ($bins as $targetX) {
            while ($cursor + 1 < $count && ($points[$cursor + 1][$axis] ?? 0.0) <= $targetX) {
                $cursor++;
            }

            $p0 = $points[$cursor];
            $p1 = $points[min($cursor + 1, $count - 1)];

            $x0 = $p0[$axis] ?? 0.0;
            $x1 = $p1[$axis] ?? $x0;

            if ($targetX <= $x0 || $x1 <= $x0) {
                $t = 0.0;
            } elseif ($targetX >= $x1) {
                $t = 1.0;
            } else {
                $t = ($targetX - $x0) / ($x1 - $x0);
            }

            $elapsed[] = $axis === 'time'
                ? round($targetX, 2)
                : $this->lerpNullable($p0['time'], $p1['time'], $t, 2);

            $distance[] = $axis === 'distance'
                ? round($targetX, 1)
                : $this->lerpNullable($p0['distance'], $p1['distance'], $t, 1);

            $speed[] = $this->lerpNullable($p0['speed'], $p1['speed'], $t, 3);
            $gapSpeed[] = $this->lerpNullable($p0['gap_speed'], $p1['gap_speed'], $t, 3);
            $hr[] = $this->lerpNullable($p0['hr'], $p1['hr'], $t, 1);
            $power[] = $this->lerpNullable($p0['power'], $p1['power'], $t, 1);
            $altitude[] = $this->lerpNullable($p0['altitude'], $p1['altitude'], $t, 1);
            $cadence[] = $this->lerpNullable($p0['cadence'], $p1['cadence'], $t, 1);
        }

        return [
            'elapsed_seconds' => $elapsed,
            'distance_meters' => $distance,
            'speed' => $speed,
            'gap_speed' => $gapSpeed,
            'hr' => $hr,
            'power' => $power,
            'altitude' => $altitude,
            'cadence' => $cadence,
        ];
    }

    /**
     * Align km_splits by split index (1..maxSplits) with time/HR deltas against Baseline.
     *
     * @param  list<Activity>  $activities
     * @return list<AlignedSplitRow>
     */
    private function buildAlignedSplits(array $activities): array
    {
        if (count($activities) < 2) {
            return [];
        }

        /** @var array<int, array<int, array<string, mixed>>> $splitsByActivity */
        $splitsByActivity = [];
        $maxSplitIndex = 0;

        foreach ($activities as $idx => $activity) {
            $rawSplits = $activity->stream?->km_splits;
            $indexed = [];

            if (is_array($rawSplits)) {
                $position = 1;

                foreach ($rawSplits as $split) {
                    if (! is_array($split)) {
                        continue;
                    }

                    $km = isset($split['km']) && is_numeric($split['km']) && (int) $split['km'] > 0
                        ? (int) $split['km']
                        : $position;

                    $indexed[$km] = $split;
                    $maxSplitIndex = max($maxSplitIndex, $km);
                    $position++;
                }
            }

            $splitsByActivity[$idx] = $indexed;
        }

        if ($maxSplitIndex === 0) {
            return [];
        }

        $rows = [];

        for ($km = 1; $km <= $maxSplitIndex; $km++) {
            $baseSplit = $splitsByActivity[0][$km] ?? null;
            $baseDuration = $baseSplit !== null ? $this->toNullableFloat($baseSplit['duration_seconds'] ?? null) : null;
            $baseHr = $baseSplit !== null ? $this->toNullableInt($baseSplit['avg_hr'] ?? null) : null;

            $entries = [];

            foreach ($activities as $idx => $activity) {
                $split = $splitsByActivity[$idx][$km] ?? null;

                if ($split === null) {
                    $entries[] = [
                        'activity_id' => $activity->id,
                        'distance_meters' => null,
                        'duration_seconds' => null,
                        'avg_speed' => null,
                        'pace_seconds_per_km' => null,
                        'gap_seconds_per_km' => null,
                        'avg_hr' => null,
                        'avg_power' => null,
                        'elevation_gain' => null,
                        'elevation_loss' => null,
                        'elevation_delta' => null,
                        'time_delta_seconds' => null,
                        'hr_delta' => null,
                        'is_partial' => false,
                    ];

                    continue;
                }

                $distance = $this->toNullableFloat($split['distance_meters'] ?? null);
                $duration = $this->toNullableFloat($split['duration_seconds'] ?? null);
                $avgSpeed = $this->toNullableFloat($split['avg_speed'] ?? null);
                $paceSec = $this->toNullableFloat($split['pace_seconds_per_km'] ?? null);
                $gapSec = $this->toNullableFloat($split['gap_seconds_per_km'] ?? null);
                $avgHr = $this->toNullableInt($split['avg_hr'] ?? null);
                $avgPower = $this->toNullableFloat($split['avg_power'] ?? null);
                $elevGain = $this->toNullableFloat($split['elevation_gain'] ?? null);
                $elevLoss = $this->toNullableFloat($split['elevation_loss'] ?? null);

                $elevDelta = ($elevGain !== null || $elevLoss !== null)
                    ? round(($elevGain ?? 0.0) - ($elevLoss ?? 0.0), 1)
                    : null;

                $timeDelta = ($idx > 0 && $duration !== null && $baseDuration !== null)
                    ? round($duration - $baseDuration, 1)
                    : null;

                $hrDelta = ($idx > 0 && $avgHr !== null && $baseHr !== null)
                    ? $avgHr - $baseHr
                    : null;

                $entries[] = [
                    'activity_id' => $activity->id,
                    'distance_meters' => $distance !== null ? round($distance, 1) : null,
                    'duration_seconds' => $duration !== null ? round($duration, 1) : null,
                    'avg_speed' => $avgSpeed !== null ? round($avgSpeed, 3) : null,
                    'pace_seconds_per_km' => $paceSec !== null ? round($paceSec, 1) : null,
                    'gap_seconds_per_km' => $gapSec !== null ? round($gapSec, 1) : null,
                    'avg_hr' => $avgHr,
                    'avg_power' => $avgPower !== null ? round($avgPower, 1) : null,
                    'elevation_gain' => $elevGain !== null ? round($elevGain, 1) : null,
                    'elevation_loss' => $elevLoss !== null ? round($elevLoss, 1) : null,
                    'elevation_delta' => $elevDelta,
                    'time_delta_seconds' => $timeDelta,
                    'hr_delta' => $hrDelta,
                    'is_partial' => $distance !== null && $distance < 950.0,
                ];
            }

            $rows[] = [
                'split_index' => $km,
                'label' => "Km {$km}",
                'entries' => $entries,
            ];
        }

        return $rows;
    }

    /**
     * @param  'distance'|'time'  $mode
     * @return AlignedTelemetryPayload
     */
    private function emptyAlignedPayload(string $mode, bool $canAlignDistance): array
    {
        return [
            'mode' => $mode,
            'can_align_distance' => $canAlignDistance,
            'axis_max' => 0.0,
            'bins' => [],
            'has_speed' => false,
            'has_gap' => false,
            'has_hr' => false,
            'has_power' => false,
            'has_altitude' => false,
            'has_cadence' => false,
            'series' => [],
        ];
    }

    private function lerpNullable(?float $v0, ?float $v1, float $t, int $precision): ?float
    {
        if ($v0 === null && $v1 === null) {
            return null;
        }

        if ($v0 === null) {
            return round($v1, $precision);
        }

        if ($v1 === null) {
            return round($v0, $precision);
        }

        return round($v0 + ($t * ($v1 - $v0)), $precision);
    }

    private function diffFloat(?float $current, ?float $baseline, int $precision): ?float
    {
        if ($current === null || $baseline === null) {
            return null;
        }

        return round($current - $baseline, $precision);
    }

    private function diffInt(?int $current, ?int $baseline): ?int
    {
        if ($current === null || $baseline === null) {
            return null;
        }

        return $current - $baseline;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    /**
     * @param  list<?float>  $values
     */
    private function hasAnyNonNullValue(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<?float>  $values
     */
    private function hasAnyPositiveValue(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && $value > 0.0) {
                return true;
            }
        }

        return false;
    }
}
