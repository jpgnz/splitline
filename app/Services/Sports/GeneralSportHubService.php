<?php

namespace App\Services\Sports;

use App\Models\Activity;
use App\Services\Goals\GoalProgressService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Lightweight Consistency Hub service for general secondary sports (`walking`, `swimming`, `cardio`).
 *
 * @phpstan-type ZoneAggregate array{zone: int, label: string, seconds: float, pct: float}
 */
class GeneralSportHubService
{
    private const int MAX_ALL_RANGE_WEEKS = 260;

    /** @var list<string> */
    private const array VALID_VOLUME_RANGES = ['12w', '6m', '1y', 'all'];

    /** @var list<string> */
    private const array VALID_ZONE_RANGES = ['30d', '90d', 'all'];

    /** @var array<int, string> */
    private const array HR_ZONE_LABELS = [
        1 => 'Warm Up',
        2 => 'Easy',
        3 => 'Aerobic',
        4 => 'Threshold',
        5 => 'Maximum',
    ];

    public function __construct(
        private readonly SportRegistry $sports,
        private readonly GoalProgressService $goalProgressService,
    ) {}

    /**
     * Build the General Consistency Hub payload for `walking`, `swimming`, or `cardio`.
     *
     * @return array{
     *     sport: array<string, mixed>,
     *     filters: array{volumeRange: string, zoneRange: string},
     *     summary: array{
     *         ytd_sessions: int,
     *         ytd_duration_seconds: float,
     *         ytd_distance_meters: float,
     *         ytd_load: float,
     *         ytd_avg_hr: ?float,
     *         ytd_avg_speed: ?float,
     *         total_sessions: int,
     *         total_duration_seconds: float,
     *         total_distance_meters: float,
     *         total_load: float,
     *         avg_hr: ?float,
     *         avg_speed: ?float
     *     },
     *     weeklyVolume: list<array{
     *         week_start: string,
     *         label: string,
     *         sessions: int,
     *         duration_seconds: float,
     *         distance_meters: float,
     *         training_load: float
     *     }>,
     *     hrZones: array{
     *         range: string,
     *         total_seconds: float,
     *         zones: list<ZoneAggregate>,
     *         polarization: array{easy_pct: float, moderate_pct: float, hard_pct: float}
     *     },
     *     streak: array{current_weeks: int, longest_weeks: int},
     *     goals: list<array<string, mixed>>,
     *     recentActivities: list<array<string, mixed>>
     * }
     */
    public function hub(
        string $sportKey,
        string $volumeRange = '12w',
        string $zoneRange = '30d',
    ): array {
        $normalizedKey = $this->sports->normalizeKey($sportKey);
        $entry = $this->sports->get($normalizedKey);

        if ($entry === null || $entry['hub_type'] !== 'general') {
            throw new InvalidArgumentException("Unsupported general hub sport: {$sportKey}");
        }

        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $normalizedVolumeRange = in_array($volumeRange, self::VALID_VOLUME_RANGES, true)
            ? $volumeRange
            : '12w';
        $normalizedZoneRange = in_array($zoneRange, self::VALID_ZONE_RANGES, true)
            ? $zoneRange
            : '30d';

        $baseQuery = $this->sports->applyScope(Activity::query(), $normalizedKey);

        return [
            'sport' => [
                ...$entry,
                'family_key' => null,
                'tabs' => [],
            ],
            'filters' => [
                'volumeRange' => $normalizedVolumeRange,
                'zoneRange' => $normalizedZoneRange,
            ],
            'summary' => $this->buildSummary($baseQuery, $today),
            'weeklyVolume' => $this->buildWeeklyVolume($baseQuery, $normalizedVolumeRange, $today, $timezone),
            'hrZones' => $this->buildHrZones($baseQuery, $normalizedZoneRange, $today),
            'streak' => $this->buildWeeklyStreak($baseQuery, $today),
            'goals' => $this->goalProgressService->activeGoals(sport: $normalizedKey),
            'recentActivities' => $this->buildRecentActivities($baseQuery),
        ];
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     ytd_sessions: int,
     *     ytd_duration_seconds: float,
     *     ytd_distance_meters: float,
     *     ytd_load: float,
     *     ytd_avg_hr: ?float,
     *     ytd_avg_speed: ?float,
     *     total_sessions: int,
     *     total_duration_seconds: float,
     *     total_distance_meters: float,
     *     total_load: float,
     *     avg_hr: ?float,
     *     avg_speed: ?float
     * }
     */
    private function buildSummary(Builder $baseQuery, CarbonImmutable $today): array
    {
        $ytdBounds = [
            $today->startOfYear()->format('Y-m-d').' 00:00:00',
            $today->endOfYear()->format('Y-m-d').' 23:59:59',
        ];

        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(tss, training_load, 0)), 0) as load')
            ->selectRaw('AVG(CASE WHEN avg_hr > 0 THEN avg_hr END) as avg_hr')
            ->selectRaw('AVG(CASE WHEN avg_speed > 0 THEN avg_speed END) as avg_speed')
            ->first();

        $totalRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(tss, training_load, 0)), 0) as load')
            ->selectRaw('AVG(CASE WHEN avg_hr > 0 THEN avg_hr END) as avg_hr')
            ->selectRaw('AVG(CASE WHEN avg_speed > 0 THEN avg_speed END) as avg_speed')
            ->first();

        $ytdDistance = $this->rowFloat($ytdRow, 'distance_meters');
        $ytdDuration = $this->rowFloat($ytdRow, 'duration_seconds');
        $ytdAvgSpeed = ($ytdDistance > 0.0 && $ytdDuration > 0.0)
            ? round($ytdDistance / $ytdDuration, 3)
            : $this->rowNullableFloat($ytdRow, 'avg_speed', 3);

        $totalDistance = $this->rowFloat($totalRow, 'distance_meters');
        $totalDuration = $this->rowFloat($totalRow, 'duration_seconds');
        $avgSpeed = ($totalDistance > 0.0 && $totalDuration > 0.0)
            ? round($totalDistance / $totalDuration, 3)
            : $this->rowNullableFloat($totalRow, 'avg_speed', 3);

        return [
            'ytd_sessions' => $this->rowInt($ytdRow, 'sessions'),
            'ytd_duration_seconds' => round($ytdDuration, 1),
            'ytd_distance_meters' => round($ytdDistance, 1),
            'ytd_load' => round($this->rowFloat($ytdRow, 'load'), 1),
            'ytd_avg_hr' => $this->rowNullableFloat($ytdRow, 'avg_hr', 1),
            'ytd_avg_speed' => $ytdAvgSpeed,
            'total_sessions' => $this->rowInt($totalRow, 'sessions'),
            'total_duration_seconds' => round($totalDuration, 1),
            'total_distance_meters' => round($totalDistance, 1),
            'total_load' => round($this->rowFloat($totalRow, 'load'), 1),
            'avg_hr' => $this->rowNullableFloat($totalRow, 'avg_hr', 1),
            'avg_speed' => $avgSpeed,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     week_start: string,
     *     label: string,
     *     sessions: int,
     *     duration_seconds: float,
     *     distance_meters: float,
     *     training_load: float
     * }>
     */
    private function buildWeeklyVolume(
        Builder $baseQuery,
        string $volumeRange,
        CarbonImmutable $today,
        string $timezone,
    ): array {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();

        if ($volumeRange === '12w') {
            $windowStart = $currentWeekStart->subWeeks(11);
        } elseif ($volumeRange === '6m') {
            $windowStart = $currentWeekStart->subWeeks(25);
        } elseif ($volumeRange === '1y') {
            $windowStart = $currentWeekStart->subWeeks(51);
        } else {
            $earliest = (clone $baseQuery)->min('started_at_local');

            if (is_string($earliest) && trim($earliest) !== '') {
                $earliestWeek = CarbonImmutable::parse($earliest, $timezone)
                    ->startOfWeek(CarbonInterface::MONDAY)
                    ->startOfDay();
                $minAllowedWeek = $currentWeekStart->subWeeks(self::MAX_ALL_RANGE_WEEKS - 1);

                if ($earliestWeek->lessThan($minAllowedWeek)) {
                    $windowStart = $minAllowedWeek;
                } elseif ($earliestWeek->greaterThan($currentWeekStart)) {
                    $windowStart = $currentWeekStart;
                } else {
                    $windowStart = $earliestWeek;
                }
            } else {
                $windowStart = $currentWeekStart->subWeeks(11);
            }
        }

        $queryStart = $windowStart->format('Y-m-d').' 00:00:00';
        $queryEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY)->format('Y-m-d').' 23:59:59';

        $activities = (clone $baseQuery)
            ->whereBetween('started_at_local', [$queryStart, $queryEnd])
            ->get(['started_at_local', 'duration_seconds', 'distance_meters', 'training_load', 'tss']);

        /** @var array<string, array{sessions: int, duration_seconds: float, distance_meters: float, training_load: float}> $byWeek */
        $byWeek = [];

        foreach ($activities as $activity) {
            $weekKey = $activity->started_at_local
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');

            if (! isset($byWeek[$weekKey])) {
                $byWeek[$weekKey] = [
                    'sessions' => 0,
                    'duration_seconds' => 0.0,
                    'distance_meters' => 0.0,
                    'training_load' => 0.0,
                ];
            }

            $byWeek[$weekKey]['sessions']++;
            $byWeek[$weekKey]['duration_seconds'] += $activity->duration_seconds;
            $byWeek[$weekKey]['distance_meters'] += $activity->distance_meters;
            $byWeek[$weekKey]['training_load'] += max(0.0, $activity->training_load ?? $activity->tss ?? 0.0);
        }

        $result = [];
        $cursor = $windowStart;

        while ($cursor->lessThanOrEqualTo($currentWeekStart)) {
            $weekKey = $cursor->format('Y-m-d');
            $bucket = $byWeek[$weekKey] ?? [
                'sessions' => 0,
                'duration_seconds' => 0.0,
                'distance_meters' => 0.0,
                'training_load' => 0.0,
            ];

            $result[] = [
                'week_start' => $weekKey,
                'label' => $cursor->format('j M'),
                'sessions' => $bucket['sessions'],
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'distance_meters' => round($bucket['distance_meters'], 1),
                'training_load' => round($bucket['training_load'], 1),
            ];

            $cursor = $cursor->addWeek();
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     range: string,
     *     total_seconds: float,
     *     zones: list<ZoneAggregate>,
     *     polarization: array{easy_pct: float, moderate_pct: float, hard_pct: float}
     * }
     */
    private function buildHrZones(Builder $baseQuery, string $zoneRange, CarbonImmutable $today): array
    {
        $query = clone $baseQuery;

        if ($zoneRange !== 'all') {
            $days = $zoneRange === '90d' ? 90 : 30;
            $query->whereBetween('started_at_local', [
                $today->subDays($days - 1)->format('Y-m-d').' 00:00:00',
                $today->format('Y-m-d').' 23:59:59',
            ]);
        }

        $row = $query
            ->toBase()
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_1_seconds, 0)), 0) as z1')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_2_seconds, 0)), 0) as z2')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_3_seconds, 0)), 0) as z3')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_4_seconds, 0)), 0) as z4')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_5_seconds, 0)), 0) as z5')
            ->first();

        $secondsByZone = [
            1 => $this->rowFloat($row, 'z1'),
            2 => $this->rowFloat($row, 'z2'),
            3 => $this->rowFloat($row, 'z3'),
            4 => $this->rowFloat($row, 'z4'),
            5 => $this->rowFloat($row, 'z5'),
        ];

        $totalSeconds = array_sum($secondsByZone);
        $zones = [];

        foreach ($secondsByZone as $zone => $seconds) {
            $zones[] = [
                'zone' => $zone,
                'label' => self::HR_ZONE_LABELS[$zone],
                'seconds' => round($seconds, 1),
                'pct' => $totalSeconds > 0.0 ? round(($seconds / $totalSeconds) * 100.0, 1) : 0.0,
            ];
        }

        return [
            'range' => $zoneRange,
            'total_seconds' => round($totalSeconds, 1),
            'zones' => $zones,
            'polarization' => [
                'easy_pct' => $totalSeconds > 0.0
                    ? round((($secondsByZone[1] + $secondsByZone[2]) / $totalSeconds) * 100.0, 1)
                    : 0.0,
                'moderate_pct' => $totalSeconds > 0.0
                    ? round(($secondsByZone[3] / $totalSeconds) * 100.0, 1)
                    : 0.0,
                'hard_pct' => $totalSeconds > 0.0
                    ? round((($secondsByZone[4] + $secondsByZone[5]) / $totalSeconds) * 100.0, 1)
                    : 0.0,
            ],
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{current_weeks: int, longest_weeks: int}
     */
    private function buildWeeklyStreak(Builder $baseQuery, CarbonImmutable $today): array
    {
        $activities = (clone $baseQuery)
            ->where('started_at_local', '<=', $today->format('Y-m-d').' 23:59:59')
            ->get(['started_at_local']);

        if ($activities->isEmpty()) {
            return ['current_weeks' => 0, 'longest_weeks' => 0];
        }

        /** @var array<string, bool> $weekMap */
        $weekMap = [];

        foreach ($activities as $activity) {
            $dayUtc = $this->parseUtcDate($activity->started_at_local->format('Y-m-d'));
            $weekKey = $dayUtc->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');
            $weekMap[$weekKey] = true;
        }

        $todayUtc = $this->parseUtcDate($today->format('Y-m-d'));
        $thisWeekMonday = $todayUtc->startOfWeek(CarbonInterface::MONDAY);

        $current = 0;
        $cursor = null;

        if (isset($weekMap[$thisWeekMonday->format('Y-m-d')])) {
            $cursor = $thisWeekMonday;
        } elseif (isset($weekMap[$thisWeekMonday->subWeek()->format('Y-m-d')])) {
            $cursor = $thisWeekMonday->subWeek();
        }

        while ($cursor !== null && isset($weekMap[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor = $cursor->subWeek();
        }

        ksort($weekMap);

        $longest = 0;
        $run = 0;
        $previous = null;

        foreach (array_keys($weekMap) as $weekKey) {
            $weekStart = $this->parseUtcDate($weekKey);

            if ($previous !== null && $previous->addWeek()->format('Y-m-d') === $weekKey) {
                $run++;
            } else {
                $run = 1;
            }

            if ($run > $longest) {
                $longest = $run;
            }

            $previous = $weekStart;
        }

        return [
            'current_weeks' => $current,
            'longest_weeks' => $longest,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array<string, mixed>>
     */
    private function buildRecentActivities(Builder $baseQuery): array
    {
        $activities = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'activity_type_key',
                'location_name',
                'is_indoor',
                'distance_meters',
                'duration_seconds',
                'avg_speed',
                'avg_hr',
                'avg_cadence',
                'calories',
                'training_load',
            ])
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $result = [];

        foreach ($activities as $activity) {
            $result[] = [
                'id' => $activity->id,
                'name' => $activity->name,
                'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
                'activity_type_key' => $activity->activity_type_key,
                'location_name' => $activity->location_name,
                'is_indoor' => $activity->is_indoor,
                'distance_meters' => $activity->distance_meters,
                'duration_seconds' => $activity->duration_seconds,
                'avg_speed' => $activity->avg_speed,
                'avg_hr' => $activity->avg_hr,
                'avg_cadence' => $activity->avg_cadence,
                'calories' => $activity->calories,
                'training_load' => $activity->training_load,
            ];
        }

        return $result;
    }

    private function parseUtcDate(string $ymd): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $ymd, 'UTC');

        return $parsed instanceof CarbonImmutable
            ? $parsed
            : CarbonImmutable::parse($ymd, 'UTC')->startOfDay();
    }

    private function rowInt(?object $row, string $property): int
    {
        if ($row === null || ! property_exists($row, $property)) {
            return 0;
        }

        $value = $row->{$property};

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowFloat(?object $row, string $property): float
    {
        if ($row === null || ! property_exists($row, $property)) {
            return 0.0;
        }

        $value = $row->{$property};

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function rowNullableFloat(?object $row, string $property, int $precision): ?float
    {
        if ($row === null || ! property_exists($row, $property)) {
            return null;
        }

        $value = $row->{$property};

        return is_numeric($value) ? round((float) $value, $precision) : null;
    }
}
