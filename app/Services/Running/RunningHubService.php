<?php

namespace App\Services\Running;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Vo2MaxRecord;
use App\Services\Garmin\BestEffortCalculator;
use App\Services\Goals\GoalProgressService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @phpstan-type BestEffortBoardEntry array{
 *     id: int,
 *     activity_id: int,
 *     activity_name: string,
 *     achieved_at: string,
 *     elapsed_seconds: float,
 *     effective_seconds: float,
 *     override_seconds: ?float,
 *     pace_seconds_per_km: float,
 *     source: string,
 *     is_excluded: bool,
 *     rank: ?int
 * }
 * @phpstan-type BestEffortProgressionPoint array{
 *     date: string,
 *     effective_seconds: float,
 *     is_pr: bool,
 *     activity_id: int,
 *     activity_name: string
 * }
 * @phpstan-type BestEffortDistanceBoard array{
 *     key: string,
 *     label: string,
 *     distance_meters: float,
 *     allTimeTop3: list<BestEffortBoardEntry>,
 *     annualTop10: list<BestEffortBoardEntry>,
 *     progression: list<BestEffortProgressionPoint>,
 *     excluded: list<BestEffortBoardEntry>
 * }
 * @phpstan-type RacePredictionItem array{
 *     key: string,
 *     label: string,
 *     distance_meters: float,
 *     predicted_seconds: float,
 *     pace_seconds_per_km: float
 * }
 */
class RunningHubService
{
    public function __construct(
        private readonly GoalProgressService $goalProgressService = new GoalProgressService,
    ) {}

    private const int MAX_ALL_RANGE_WEEKS = 260;

    private const float RIEGEL_EXPONENT = 1.06;

    /**
     * @var list<string>
     */
    private const array VALID_ENVIRONMENTS = ['all', 'outdoor', 'treadmill'];

    /**
     * @var list<string>
     */
    private const array VALID_VOLUME_RANGES = ['12w', '6m', '1y', 'all'];

    /**
     * @var list<string>
     */
    private const array VALID_ZONE_RANGES = ['30d', '90d'];

    /**
     * @var array<string, string>
     */
    private const array RUNNING_DISTANCE_LABELS = [
        '400m' => '400m',
        'half_mile' => '½ Mile',
        '1k' => '1K',
        '1mi' => '1 Mile',
        '2mi' => '2 Miles',
        '5k' => '5K',
        '10k' => '10K',
        '15k' => '15K',
        '10mi' => '10 Miles',
        '20k' => '20K',
        'half_marathon' => 'Half Marathon',
        '30k' => '30K',
        'marathon' => 'Marathon',
        '50k' => '50K',
    ];

    /**
     * @var array<int, string>
     */
    private const array HR_ZONE_LABELS = [
        1 => 'Warm Up',
        2 => 'Easy',
        3 => 'Aerobic',
        4 => 'Threshold',
        5 => 'Maximum',
    ];

    /**
     * @var list<string>
     */
    private const array PREDICTOR_RECENT_PREFERRED_KEYS = ['10k', '5k', 'half_marathon'];

    /**
     * @var list<string>
     */
    private const array PREDICTOR_QUALIFYING_KEYS = [
        '10k',
        'half_marathon',
        '5k',
        '10mi',
        '15k',
        '2mi',
        '1mi',
    ];

    /**
     * @var list<string>
     */
    private const array PREDICTOR_TARGET_KEYS = ['5k', '10k', 'half_marathon', 'marathon'];

    /**
     * Build the full Running Hub analytics payload.
     *
     * @return array{
     *     summary: array{
     *         ytd_distance_meters: float,
     *         ytd_runs: int,
     *         ytd_duration_seconds: float,
     *         ytd_elevation_gain: float,
     *         ytd_avg_speed: ?float,
     *         latest_vo2_max: ?float,
     *         weekly_streak: int,
     *         daily_streak: int
     *     },
     *     bestEfforts: array{
     *         selectedYear: int,
     *         availableYears: list<int>,
     *         distances: list<BestEffortDistanceBoard>
     *     },
     *     weeklyMileage: list<array{
     *         week_start: string,
     *         label: string,
     *         km: float,
     *         runs: int,
     *         elevation_m: float,
     *         rolling_4w_km: float
     *     }>,
     *     ytdCumulative: list<array{
     *         year: int,
     *         total_km: float,
     *         points: list<float>
     *     }>,
     *     vo2Trend: list<array{date: string, value: float}>,
     *     aerobicEfficiency: list<array{
     *         date: string,
     *         activity_id: int,
     *         name: string,
     *         pace_sec_per_km: float,
     *         avg_hr: int,
     *         efficiency: float
     *     }>,
     *     hrZonePolarization: array{
     *         range: string,
     *         total_seconds: float,
     *         zones: list<array{zone: int, label: string, seconds: float, pct: float}>,
     *         easy_pct: float,
     *         moderate_pct: float,
     *         hard_pct: float
     *     },
     *     cadenceStrideTrend: list<array{
     *         date: string,
     *         name: string,
     *         cadence: float,
     *         stride_length_cm: ?float
     *     }>,
     *     racePredictor: ?array{
     *         source_label: string,
     *         source_time_seconds: float,
     *         source_date: string,
     *         predictions: list<RacePredictionItem>
     *     },
     *     recentRuns: list<array{
     *         id: int,
     *         name: string,
     *         started_at_local: string,
     *         location_name: ?string,
     *         is_indoor: bool,
     *         distance_meters: float,
     *         duration_seconds: float,
     *         avg_speed: ?float,
     *         avg_hr: ?int,
     *         avg_cadence: ?float,
     *         elevation_gain: ?float,
     *         training_load: ?float,
     *         is_pr: bool
     *     }>,
     *     filters: array{
     *         environment: string,
     *         volumeRange: string,
     *         year: int,
     *         zoneRange: string
     *     },
     *     goals: list<array<string, mixed>>
     * }
     */
    public function dashboard(
        string $environment = 'all',
        string $volumeRange = '12w',
        ?int $year = null,
        string $zoneRange = '30d',
    ): array {
        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $currentYear = (int) $now->year;

        $normalizedEnvironment = $this->normalizeChoice($environment, self::VALID_ENVIRONMENTS, 'all');
        $normalizedVolumeRange = $this->normalizeChoice($volumeRange, self::VALID_VOLUME_RANGES, '12w');
        $normalizedZoneRange = $this->normalizeChoice($zoneRange, self::VALID_ZONE_RANGES, '30d');
        $selectedYear = ($year !== null && $year >= 1970 && $year <= 2100) ? $year : $currentYear;

        $allRunningEfforts = BestEffort::query()
            ->where('sport', 'running')
            ->with('activity:id,name,started_at_local,distance_meters,location_name')
            ->get();

        return [
            'summary' => $this->buildSummary($normalizedEnvironment, $today),
            'bestEfforts' => $this->buildBestEfforts($allRunningEfforts, $selectedYear, $currentYear),
            'weeklyMileage' => $this->buildWeeklyMileage($normalizedEnvironment, $normalizedVolumeRange, $today, $timezone),
            'ytdCumulative' => $this->buildYtdCumulative($normalizedEnvironment, $today),
            'vo2Trend' => $this->buildVo2Trend(),
            'aerobicEfficiency' => $this->buildAerobicEfficiency($normalizedEnvironment),
            'hrZonePolarization' => $this->buildHrZonePolarization($normalizedEnvironment, $normalizedZoneRange, $today),
            'cadenceStrideTrend' => $this->buildCadenceStrideTrend($normalizedEnvironment),
            'racePredictor' => $this->buildRacePredictor($allRunningEfforts, $today),
            'recentRuns' => $this->buildRecentRuns($normalizedEnvironment),
            'filters' => [
                'environment' => $normalizedEnvironment,
                'volumeRange' => $normalizedVolumeRange,
                'year' => $selectedYear,
                'zoneRange' => $normalizedZoneRange,
            ],
            'goals' => $this->goalProgressService->activeGoals(sport: 'running'),
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(string $value, array $allowed, string $default): string
    {
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        return $default;
    }

    /**
     * @return Builder<Activity>
     */
    private function runningQuery(string $environment): Builder
    {
        $query = Activity::query()->running();

        if ($environment === 'outdoor') {
            $query->where('is_indoor', false);
        } elseif ($environment === 'treadmill') {
            $query->where('is_indoor', true);
        }

        return $query;
    }

    /**
     * @return array{
     *     ytd_distance_meters: float,
     *     ytd_runs: int,
     *     ytd_duration_seconds: float,
     *     ytd_elevation_gain: float,
     *     ytd_avg_speed: ?float,
     *     latest_vo2_max: ?float,
     *     weekly_streak: int,
     *     daily_streak: int
     * }
     */
    private function buildSummary(string $environment, CarbonImmutable $today): array
    {
        $yearStart = $today->startOfYear()->format('Y-m-d').' 00:00:00';
        $yearEnd = $today->endOfYear()->format('Y-m-d').' 23:59:59';

        $ytdRow = $this->runningQuery($environment)
            ->whereBetween('started_at_local', [$yearStart, $yearEnd])
            ->toBase()
            ->selectRaw('COUNT(*) as ytd_runs')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as ytd_distance_meters')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as ytd_duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as ytd_elevation_gain')
            ->first();

        $ytdRuns = (int) ($ytdRow->ytd_runs ?? 0);
        $ytdDistance = (float) ($ytdRow->ytd_distance_meters ?? 0.0);
        $ytdDuration = (float) ($ytdRow->ytd_duration_seconds ?? 0.0);
        $ytdElevation = (float) ($ytdRow->ytd_elevation_gain ?? 0.0);
        $ytdAvgSpeed = ($ytdDistance > 0.0 && $ytdDuration > 0.0)
            ? round($ytdDistance / $ytdDuration, 3)
            : null;

        $latestVo2Record = Vo2MaxRecord::query()
            ->where('sport', 'running')
            ->orderByDesc('calendar_date')
            ->first();

        $latestVo2 = $latestVo2Record !== null
            ? ($latestVo2Record->vo2_max_precise ?? $latestVo2Record->vo2_max)
            : null;

        [$weeklyStreak, $dailyStreak] = $this->calculateStreaks($environment, $today);

        return [
            'ytd_distance_meters' => round($ytdDistance, 1),
            'ytd_runs' => $ytdRuns,
            'ytd_duration_seconds' => round($ytdDuration, 1),
            'ytd_elevation_gain' => round($ytdElevation, 1),
            'ytd_avg_speed' => $ytdAvgSpeed,
            'latest_vo2_max' => $latestVo2 !== null ? round($latestVo2, 1) : null,
            'weekly_streak' => $weeklyStreak,
            'daily_streak' => $dailyStreak,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function calculateStreaks(string $environment, CarbonImmutable $today): array
    {
        $runs = $this->runningQuery($environment)
            ->where('started_at_local', '<=', $today->format('Y-m-d').' 23:59:59')
            ->get(['started_at_local']);

        /** @var array<string, bool> $runDays */
        $runDays = [];
        /** @var array<string, bool> $runWeeks */
        $runWeeks = [];

        foreach ($runs as $run) {
            $runDate = $run->started_at_local->startOfDay();
            $runDays[$runDate->format('Y-m-d')] = true;
            $runWeeks[$runDate->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d')] = true;
        }

        $weeklyStreak = 0;
        $thisWeekMonday = $today->startOfWeek(CarbonInterface::MONDAY);
        $weekCursor = null;

        if (isset($runWeeks[$thisWeekMonday->format('Y-m-d')])) {
            $weekCursor = $thisWeekMonday;
        } elseif (isset($runWeeks[$thisWeekMonday->subWeek()->format('Y-m-d')])) {
            $weekCursor = $thisWeekMonday->subWeek();
        }

        while ($weekCursor !== null && isset($runWeeks[$weekCursor->format('Y-m-d')])) {
            $weeklyStreak++;
            $weekCursor = $weekCursor->subWeek();
        }

        $dailyStreak = 0;
        $dayCursor = null;

        if (isset($runDays[$today->format('Y-m-d')])) {
            $dayCursor = $today;
        } elseif (isset($runDays[$today->subDay()->format('Y-m-d')])) {
            $dayCursor = $today->subDay();
        }

        while ($dayCursor !== null && isset($runDays[$dayCursor->format('Y-m-d')])) {
            $dailyStreak++;
            $dayCursor = $dayCursor->subDay();
        }

        return [$weeklyStreak, $dailyStreak];
    }

    /**
     * @param  Collection<int, BestEffort>  $efforts
     * @return array{
     *     selectedYear: int,
     *     availableYears: list<int>,
     *     distances: list<BestEffortDistanceBoard>
     * }
     */
    private function buildBestEfforts(Collection $efforts, int $selectedYear, int $currentYear): array
    {
        /** @var array<int, bool> $yearMap */
        $yearMap = [$currentYear => true];
        /** @var array<string, list<BestEffort>> $grouped */
        $grouped = [];

        foreach ($efforts as $effort) {
            $yearMap[(int) $effort->achieved_at->year] = true;
            $grouped[$effort->distance_key][] = $effort;
        }

        krsort($yearMap);
        $availableYears = array_keys($yearMap);

        $distances = [];

        foreach (BestEffortCalculator::RUNNING_DISTANCES as $key => $distanceMeters) {
            $label = self::RUNNING_DISTANCE_LABELS[$key];
            $bucket = $grouped[$key] ?? [];

            /** @var list<BestEffort> $included */
            $included = [];
            /** @var list<BestEffort> $excluded */
            $excluded = [];

            foreach ($bucket as $effort) {
                if ($effort->is_excluded) {
                    $excluded[] = $effort;
                } else {
                    $included[] = $effort;
                }
            }

            $byPerformance = $included;
            usort($byPerformance, function (BestEffort $a, BestEffort $b): int {
                $cmp = $a->effectiveSeconds() <=> $b->effectiveSeconds();

                if ($cmp !== 0) {
                    return $cmp;
                }

                $dateCmp = $a->achieved_at->getTimestamp() <=> $b->achieved_at->getTimestamp();

                if ($dateCmp !== 0) {
                    return $dateCmp;
                }

                return $a->id <=> $b->id;
            });

            $allTimeTop3 = [];

            foreach (array_slice($byPerformance, 0, 3) as $index => $effort) {
                $allTimeTop3[] = $this->mapBestEffortBoardEntry($effort, $distanceMeters, $index + 1);
            }

            $annualFiltered = array_values(array_filter(
                $byPerformance,
                fn (BestEffort $effort): bool => (int) $effort->achieved_at->year === $selectedYear,
            ));

            $annualTop10 = [];

            foreach (array_slice($annualFiltered, 0, 10) as $index => $effort) {
                $annualTop10[] = $this->mapBestEffortBoardEntry($effort, $distanceMeters, $index + 1);
            }

            $chronological = $included;
            usort($chronological, function (BestEffort $a, BestEffort $b): int {
                $dateCmp = $a->achieved_at->getTimestamp() <=> $b->achieved_at->getTimestamp();

                if ($dateCmp !== 0) {
                    return $dateCmp;
                }

                return $a->id <=> $b->id;
            });

            $progression = [];
            $priorMin = null;

            foreach ($chronological as $effort) {
                $effective = round($effort->effectiveSeconds(), 2);
                $isPr = $priorMin === null || $effective < $priorMin;

                if ($isPr) {
                    $priorMin = $effective;
                }

                $progression[] = [
                    'date' => $effort->achieved_at->format('Y-m-d'),
                    'effective_seconds' => $effective,
                    'is_pr' => $isPr,
                    'activity_id' => $effort->activity_id,
                    'activity_name' => $effort->activity->name,
                ];
            }

            usort($excluded, function (BestEffort $a, BestEffort $b): int {
                $cmp = $a->effectiveSeconds() <=> $b->effectiveSeconds();

                if ($cmp !== 0) {
                    return $cmp;
                }

                return $b->achieved_at->getTimestamp() <=> $a->achieved_at->getTimestamp();
            });

            $mappedExcluded = [];

            foreach ($excluded as $effort) {
                $mappedExcluded[] = $this->mapBestEffortBoardEntry($effort, $distanceMeters, null);
            }

            $distances[] = [
                'key' => $key,
                'label' => $label,
                'distance_meters' => $distanceMeters,
                'allTimeTop3' => $allTimeTop3,
                'annualTop10' => $annualTop10,
                'progression' => $progression,
                'excluded' => $mappedExcluded,
            ];
        }

        return [
            'selectedYear' => $selectedYear,
            'availableYears' => $availableYears,
            'distances' => $distances,
        ];
    }

    /**
     * @return BestEffortBoardEntry
     */
    private function mapBestEffortBoardEntry(BestEffort $effort, float $distanceMeters, ?int $rank): array
    {
        $effectiveSeconds = $effort->effectiveSeconds();
        $paceSecondsPerKm = $distanceMeters > 0.0
            ? round(($effectiveSeconds / $distanceMeters) * 1000.0, 1)
            : 0.0;

        return [
            'id' => $effort->id,
            'activity_id' => $effort->activity_id,
            'activity_name' => $effort->activity->name,
            'achieved_at' => $effort->achieved_at->format('Y-m-d'),
            'elapsed_seconds' => round($effort->elapsed_seconds, 2),
            'effective_seconds' => round($effectiveSeconds, 2),
            'override_seconds' => $effort->override_seconds !== null ? round($effort->override_seconds, 2) : null,
            'pace_seconds_per_km' => $paceSecondsPerKm,
            'source' => $effort->source,
            'is_excluded' => $effort->is_excluded,
            'rank' => $rank,
        ];
    }

    /**
     * @return list<array{
     *     week_start: string,
     *     label: string,
     *     km: float,
     *     runs: int,
     *     elevation_m: float,
     *     rolling_4w_km: float
     * }>
     */
    private function buildWeeklyMileage(
        string $environment,
        string $volumeRange,
        CarbonImmutable $today,
        string $timezone,
    ): array {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY);

        if ($volumeRange === '12w') {
            $windowStart = $currentWeekStart->subWeeks(11);
        } elseif ($volumeRange === '6m') {
            $windowStart = $currentWeekStart->subWeeks(25);
        } elseif ($volumeRange === '1y') {
            $windowStart = $currentWeekStart->subWeeks(51);
        } else {
            $earliest = $this->runningQuery($environment)->min('started_at_local');

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

        $queryStart = $windowStart->subWeeks(3)->format('Y-m-d').' 00:00:00';
        $queryEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY)->format('Y-m-d').' 23:59:59';

        $runs = $this->runningQuery($environment)
            ->whereBetween('started_at_local', [$queryStart, $queryEnd])
            ->get(['started_at_local', 'distance_meters', 'elevation_gain']);

        /** @var array<string, array{distance_meters: float, runs: int, elevation_gain: float}> $byWeek */
        $byWeek = [];

        foreach ($runs as $run) {
            $weekKey = $run->started_at_local
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');

            if (! isset($byWeek[$weekKey])) {
                $byWeek[$weekKey] = [
                    'distance_meters' => 0.0,
                    'runs' => 0,
                    'elevation_gain' => 0.0,
                ];
            }

            $byWeek[$weekKey]['distance_meters'] += $run->distance_meters;
            $byWeek[$weekKey]['runs']++;
            $byWeek[$weekKey]['elevation_gain'] += $run->elevation_gain ?? 0.0;
        }

        $result = [];
        $cursor = $windowStart;

        while ($cursor->lessThanOrEqualTo($currentWeekStart)) {
            $weekKey = $cursor->format('Y-m-d');
            $bucket = $byWeek[$weekKey] ?? ['distance_meters' => 0.0, 'runs' => 0, 'elevation_gain' => 0.0];

            $rollingMeters = 0.0;

            for ($offset = 0; $offset < 4; $offset++) {
                $priorKey = $cursor->subWeeks($offset)->format('Y-m-d');
                $rollingMeters += $byWeek[$priorKey]['distance_meters'] ?? 0.0;
            }

            $result[] = [
                'week_start' => $weekKey,
                'label' => $cursor->format('j M'),
                'km' => round($bucket['distance_meters'] / 1000.0, 2),
                'runs' => $bucket['runs'],
                'elevation_m' => round($bucket['elevation_gain'], 1),
                'rolling_4w_km' => round($rollingMeters / 4000.0, 2),
            ];

            $cursor = $cursor->addWeek();
        }

        return $result;
    }

    /**
     * @return list<array{
     *     year: int,
     *     total_km: float,
     *     points: list<float>
     * }>
     */
    private function buildYtdCumulative(string $environment, CarbonImmutable $today): array
    {
        $runs = $this->runningQuery($environment)
            ->where('started_at_local', '<=', $today->format('Y-m-d').' 23:59:59')
            ->get(['started_at_local', 'distance_meters']);

        /** @var array<int, array<int, float>> $weeklyMetersByYear */
        $weeklyMetersByYear = [];

        foreach ($runs as $run) {
            $runYear = (int) $run->started_at_local->year;
            $weekIndex = min(51, intdiv($run->started_at_local->dayOfYear - 1, 7));

            if (! isset($weeklyMetersByYear[$runYear])) {
                $weeklyMetersByYear[$runYear] = array_fill(0, 52, 0.0);
            }

            $weeklyMetersByYear[$runYear][$weekIndex] += $run->distance_meters;
        }

        krsort($weeklyMetersByYear);
        $recentYears = array_slice($weeklyMetersByYear, 0, 4, true);

        $currentYear = (int) $today->year;
        $currentWeekIndex = min(51, intdiv($today->dayOfYear - 1, 7));

        $result = [];

        foreach ($recentYears as $year => $weeklyMeters) {
            $maxIndex = $year === $currentYear ? $currentWeekIndex : 51;
            $cumulativeMeters = 0.0;
            $points = [];

            for ($i = 0; $i <= $maxIndex; $i++) {
                $cumulativeMeters += $weeklyMeters[$i] ?? 0.0;
                $points[] = round($cumulativeMeters / 1000.0, 2);
            }

            $result[] = [
                'year' => $year,
                'total_km' => round($cumulativeMeters / 1000.0, 2),
                'points' => $points,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{date: string, value: float}>
     */
    private function buildVo2Trend(): array
    {
        $records = Vo2MaxRecord::query()
            ->where('sport', 'running')
            ->orderBy('calendar_date')
            ->get(['calendar_date', 'vo2_max_precise', 'vo2_max']);

        $trend = [];

        foreach ($records as $record) {
            $value = $record->vo2_max_precise ?? $record->vo2_max;

            if ($value === null) {
                continue;
            }

            $trend[] = [
                'date' => $record->calendar_date,
                'value' => round($value, 1),
            ];
        }

        return $trend;
    }

    /**
     * @return list<array{
     *     date: string,
     *     activity_id: int,
     *     name: string,
     *     pace_sec_per_km: float,
     *     avg_hr: int,
     *     efficiency: float
     * }>
     */
    private function buildAerobicEfficiency(string $environment): array
    {
        $runs = $this->runningQuery($environment)
            ->whereNotNull('avg_speed')
            ->where('avg_speed', '>', 0)
            ->whereNotNull('avg_hr')
            ->where('avg_hr', '>', 0)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'name', 'started_at_local', 'avg_speed', 'avg_hr'])
            ->reverse()
            ->values();

        $series = [];

        foreach ($runs as $run) {
            $avgSpeed = $run->avg_speed;
            $avgHr = $run->avg_hr;

            if ($avgSpeed === null || $avgSpeed <= 0.0 || $avgHr === null || $avgHr <= 0) {
                continue;
            }

            $series[] = [
                'date' => $run->started_at_local->format('Y-m-d'),
                'activity_id' => $run->id,
                'name' => $run->name,
                'pace_sec_per_km' => round(1000.0 / $avgSpeed, 1),
                'avg_hr' => $avgHr,
                'efficiency' => round(($avgSpeed * 60.0) / $avgHr, 3),
            ];
        }

        return $series;
    }

    /**
     * @return array{
     *     range: string,
     *     total_seconds: float,
     *     zones: list<array{zone: int, label: string, seconds: float, pct: float}>,
     *     easy_pct: float,
     *     moderate_pct: float,
     *     hard_pct: float
     * }
     */
    private function buildHrZonePolarization(
        string $environment,
        string $zoneRange,
        CarbonImmutable $today,
    ): array {
        $days = $zoneRange === '90d' ? 90 : 30;
        $windowStart = $today->subDays($days - 1)->format('Y-m-d').' 00:00:00';
        $windowEnd = $today->format('Y-m-d').' 23:59:59';

        $row = $this->runningQuery($environment)
            ->whereBetween('started_at_local', [$windowStart, $windowEnd])
            ->toBase()
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_1_seconds, 0)), 0) as z1')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_2_seconds, 0)), 0) as z2')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_3_seconds, 0)), 0) as z3')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_4_seconds, 0)), 0) as z4')
            ->selectRaw('COALESCE(SUM(COALESCE(hr_zone_5_seconds, 0)), 0) as z5')
            ->first();

        $zoneSeconds = [
            1 => (float) ($row->z1 ?? 0.0),
            2 => (float) ($row->z2 ?? 0.0),
            3 => (float) ($row->z3 ?? 0.0),
            4 => (float) ($row->z4 ?? 0.0),
            5 => (float) ($row->z5 ?? 0.0),
        ];

        $totalSeconds = array_sum($zoneSeconds);
        $zones = [];

        foreach ($zoneSeconds as $zoneNumber => $seconds) {
            $zones[] = [
                'zone' => $zoneNumber,
                'label' => self::HR_ZONE_LABELS[$zoneNumber],
                'seconds' => round($seconds, 1),
                'pct' => $totalSeconds > 0.0 ? round(($seconds / $totalSeconds) * 100.0, 1) : 0.0,
            ];
        }

        $easyPct = $totalSeconds > 0.0
            ? round((($zoneSeconds[1] + $zoneSeconds[2]) / $totalSeconds) * 100.0, 1)
            : 0.0;
        $moderatePct = $totalSeconds > 0.0
            ? round(($zoneSeconds[3] / $totalSeconds) * 100.0, 1)
            : 0.0;
        $hardPct = $totalSeconds > 0.0
            ? round((($zoneSeconds[4] + $zoneSeconds[5]) / $totalSeconds) * 100.0, 1)
            : 0.0;

        return [
            'range' => $zoneRange,
            'total_seconds' => round($totalSeconds, 1),
            'zones' => $zones,
            'easy_pct' => $easyPct,
            'moderate_pct' => $moderatePct,
            'hard_pct' => $hardPct,
        ];
    }

    /**
     * @return list<array{
     *     date: string,
     *     name: string,
     *     cadence: float,
     *     stride_length_cm: ?float
     * }>
     */
    private function buildCadenceStrideTrend(string $environment): array
    {
        $runs = $this->runningQuery($environment)
            ->whereNotNull('avg_cadence')
            ->where('avg_cadence', '>', 0)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(40)
            ->get(['name', 'started_at_local', 'avg_cadence', 'avg_stride_length'])
            ->reverse()
            ->values();

        $series = [];

        foreach ($runs as $run) {
            $cadence = $run->avg_cadence;

            if ($cadence === null || $cadence <= 0.0) {
                continue;
            }

            $strideCm = null;

            if ($run->avg_stride_length !== null && $run->avg_stride_length > 0.0) {
                $strideCm = $run->avg_stride_length <= 10.0
                    ? round($run->avg_stride_length * 100.0, 1)
                    : round($run->avg_stride_length, 1);
            }

            $series[] = [
                'date' => $run->started_at_local->format('Y-m-d'),
                'name' => $run->name,
                'cadence' => round($cadence, 1),
                'stride_length_cm' => $strideCm,
            ];
        }

        return $series;
    }

    /**
     * Predict 5K, 10K, Half Marathon, and Marathon times using the Riegel formula:
     * T2 = T1 * (D2 / D1)^1.06.
     *
     * @param  Collection<int, BestEffort>  $efforts
     * @return ?array{
     *     source_label: string,
     *     source_time_seconds: float,
     *     source_date: string,
     *     predictions: list<RacePredictionItem>
     * }
     */
    private function buildRacePredictor(Collection $efforts, CarbonImmutable $today): ?array
    {
        $recentCutoff = $today->subMonths(12)->startOfDay();

        /** @var list<BestEffort> $recentPreferred */
        $recentPreferred = [];
        /** @var list<BestEffort> $allTimeQualifying */
        $allTimeQualifying = [];

        foreach ($efforts as $effort) {
            if ($effort->is_excluded || $effort->effectiveSeconds() <= 0.0) {
                continue;
            }

            if (! in_array($effort->distance_key, self::PREDICTOR_QUALIFYING_KEYS, true)) {
                continue;
            }

            $allTimeQualifying[] = $effort;

            if (
                in_array($effort->distance_key, self::PREDICTOR_RECENT_PREFERRED_KEYS, true)
                && $effort->achieved_at->greaterThanOrEqualTo($recentCutoff)
            ) {
                $recentPreferred[] = $effort;
            }
        }

        $pool = $recentPreferred !== [] ? $recentPreferred : $allTimeQualifying;

        if ($pool === []) {
            return null;
        }

        $source = $this->selectStrongestEffort($pool);
        $sourceMeters = BestEffortCalculator::RUNNING_DISTANCES[$source->distance_key];
        $sourceSeconds = $source->effectiveSeconds();

        $predictions = [];

        foreach (self::PREDICTOR_TARGET_KEYS as $targetKey) {
            $targetMeters = BestEffortCalculator::RUNNING_DISTANCES[$targetKey];
            $predictedSeconds = round(
                $sourceSeconds * (($targetMeters / $sourceMeters) ** self::RIEGEL_EXPONENT),
                1,
            );

            $predictions[] = [
                'key' => $targetKey,
                'label' => self::RUNNING_DISTANCE_LABELS[$targetKey],
                'distance_meters' => $targetMeters,
                'predicted_seconds' => $predictedSeconds,
                'pace_seconds_per_km' => round(($predictedSeconds / $targetMeters) * 1000.0, 1),
            ];
        }

        return [
            'source_label' => self::RUNNING_DISTANCE_LABELS[$source->distance_key] ?? strtoupper($source->distance_key),
            'source_time_seconds' => round($sourceSeconds, 1),
            'source_date' => $source->achieved_at->format('Y-m-d'),
            'predictions' => $predictions,
        ];
    }

    /**
     * Select the effort with the fastest Riegel-normalized 10K equivalent time.
     *
     * @param  non-empty-list<BestEffort>  $efforts
     */
    private function selectStrongestEffort(array $efforts): BestEffort
    {
        $best = $efforts[0];
        $bestEquivalent = $this->riegelEquivalent10k($best);

        foreach (array_slice($efforts, 1) as $candidate) {
            $candidateEquivalent = $this->riegelEquivalent10k($candidate);

            if ($candidateEquivalent < $bestEquivalent) {
                $best = $candidate;
                $bestEquivalent = $candidateEquivalent;
            }
        }

        return $best;
    }

    private function riegelEquivalent10k(BestEffort $effort): float
    {
        $distanceMeters = BestEffortCalculator::RUNNING_DISTANCES[$effort->distance_key] ?? $effort->distance_meters;

        return $effort->effectiveSeconds() * ((10000.0 / $distanceMeters) ** self::RIEGEL_EXPONENT);
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     started_at_local: string,
     *     location_name: ?string,
     *     is_indoor: bool,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     avg_speed: ?float,
     *     avg_hr: ?int,
     *     avg_cadence: ?float,
     *     elevation_gain: ?float,
     *     training_load: ?float,
     *     is_pr: bool
     * }>
     */
    private function buildRecentRuns(string $environment): array
    {
        $runs = $this->runningQuery($environment)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(15)
            ->get([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'is_indoor',
                'distance_meters',
                'duration_seconds',
                'avg_speed',
                'avg_hr',
                'avg_cadence',
                'elevation_gain',
                'training_load',
                'is_pr',
            ]);

        $result = [];

        foreach ($runs as $run) {
            $result[] = [
                'id' => $run->id,
                'name' => $run->name,
                'started_at_local' => $run->started_at_local->format('Y-m-d H:i:s'),
                'location_name' => $run->location_name,
                'is_indoor' => $run->is_indoor,
                'distance_meters' => $run->distance_meters,
                'duration_seconds' => $run->duration_seconds,
                'avg_speed' => $run->avg_speed,
                'avg_hr' => $run->avg_hr,
                'avg_cadence' => $run->avg_cadence,
                'elevation_gain' => $run->elevation_gain,
                'training_load' => $run->training_load,
                'is_pr' => $run->is_pr,
            ];
        }

        return $result;
    }
}
