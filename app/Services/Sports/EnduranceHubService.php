<?php

namespace App\Services\Sports;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Services\Garmin\BestEffortCalculator;
use App\Services\Goals\GoalProgressService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

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
 * @phpstan-type ZoneAggregate array{zone: int, label: string, seconds: float, pct: float}
 * @phpstan-type OffroadLeaderboardSession array{
 *     id: int,
 *     name: string,
 *     date: string,
 *     location_name: ?string,
 *     grit: ?float,
 *     avg_flow: ?float,
 *     elevation_gain: ?float,
 *     distance_meters: float
 * }
 * @phpstan-type OffroadJumpSession array{
 *     id: int,
 *     name: string,
 *     date: string,
 *     location_name: ?string,
 *     jump_count: int,
 *     grit: ?float,
 *     avg_flow: ?float,
 *     elevation_gain: ?float,
 *     distance_meters: float
 * }
 */
class EnduranceHubService
{
    private const int MAX_ALL_RANGE_WEEKS = 260;

    private const int WEEKLY_TSS_WEEKS = 24;

    private const int FITNESS_SERIES_DAYS = 180;

    private const int FITNESS_WARMUP_DAYS = 42;

    private const float RIEGEL_EXPONENT = 1.06;

    private const float FASTEST_CYCLING_MIN_DISTANCE_METERS = 20000.0;

    private const float FASTEST_RUNNING_MIN_DISTANCE_METERS = 3000.0;

    private const float OFFROAD_MIN_UNPAVED_PCT = 15.0;

    /** @var list<string> */
    private const array VALID_ENVIRONMENTS = ['all', 'outdoor', 'indoor'];

    /** @var list<string> */
    private const array VALID_TERRAINS = ['all', 'flat', 'rolling', 'steep'];

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

    /** @var array<int, string> */
    private const array POWER_ZONE_LABELS = [
        1 => 'Active Recovery',
        2 => 'Endurance',
        3 => 'Tempo',
        4 => 'Lactate Threshold',
        5 => 'VO2 Max',
        6 => 'Anaerobic Capacity',
        7 => 'Neuromuscular',
    ];

    /** @var array<int, string> */
    private const array POWER_CURVE_DURATIONS = [
        1 => '1s',
        5 => '5s',
        10 => '10s',
        15 => '15s',
        20 => '20s',
        30 => '30s',
        60 => '1m',
        120 => '2m',
        300 => '5m',
        600 => '10m',
        1200 => '20m',
        1800 => '30m',
        3600 => '1h',
        7200 => '2h',
    ];

    /** @var array<string, string> */
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

    /** @var array<string, string> */
    private const array CYCLING_DISTANCE_LABELS = [
        '5k' => '5K',
        '10k' => '10K',
        '20k' => '20K',
        '40k' => '40K',
        '50k' => '50K',
        '80k' => '80K',
        '100k' => '100K',
    ];

    /** @var list<string> */
    private const array PREDICTOR_RECENT_PREFERRED_KEYS = ['10k', '5k', 'half_marathon'];

    /** @var list<string> */
    private const array PREDICTOR_QUALIFYING_KEYS = [
        '10k',
        'half_marathon',
        '5k',
        '10mi',
        '15k',
        '2mi',
        '1mi',
    ];

    /** @var list<string> */
    private const array PREDICTOR_TARGET_KEYS = ['5k', '10k', 'half_marathon', 'marathon'];

    public function __construct(
        private readonly SportRegistry $sports,
        private readonly GoalProgressService $goalProgressService,
    ) {}

    /**
     * Build the composable Endurance Hub payload for any running, cycling, or hiking sport key.
     *
     * @return array<string, mixed>
     */
    public function hub(
        string $sportKey,
        string $environment = 'all',
        string $terrain = 'all',
        string $volumeRange = '12w',
        ?int $year = null,
        string $zoneRange = '30d',
    ): array {
        $normalizedKey = $this->sports->normalizeKey($sportKey);
        $entry = $this->sports->get($normalizedKey);

        if ($entry === null || $entry['hub_type'] !== 'endurance') {
            throw new InvalidArgumentException("Unsupported endurance hub sport: {$sportKey}");
        }

        $timezone = $this->timezone();
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        $currentYear = (int) $now->year;

        $showEnvironmentFilter = ! $entry['indoor_only'] && $normalizedKey !== 'hiking';
        $showTerrainFilter = ! $entry['indoor_only'] && $normalizedKey !== 'track_running';

        $envInput = $environment === 'treadmill' ? 'indoor' : $environment;
        $normalizedEnvironment = $showEnvironmentFilter
            ? $this->normalizeChoice($envInput, self::VALID_ENVIRONMENTS, 'all')
            : 'all';
        $normalizedTerrain = $showTerrainFilter
            ? $this->normalizeChoice($terrain, self::VALID_TERRAINS, 'all')
            : 'all';
        $normalizedVolumeRange = $this->normalizeChoice($volumeRange, self::VALID_VOLUME_RANGES, '12w');
        $normalizedZoneRange = $this->normalizeChoice($zoneRange, self::VALID_ZONE_RANGES, '30d');
        $selectedYear = ($year !== null && $year >= 1970 && $year <= 2100) ? $year : $currentYear;

        $baseQuery = $this->sports->applyScope(
            Activity::query(),
            $normalizedKey,
            $normalizedEnvironment,
            $normalizedTerrain,
        );

        $familyKey = $entry['parent']
            ?? (in_array($entry['key'], ['running', 'cycling'], true) ? $entry['key'] : null);
        $tabs = $familyKey !== null ? $this->sports->familyTabs($familyKey) : [];

        $latestWeight = WeightMeasurement::query()
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();
        $weightKg = $latestWeight !== null ? round($latestWeight->weight_grams / 1000.0, 2) : null;

        $latestFtp = $this->resolveLatestFtp($baseQuery);
        $wPerKg = ($latestFtp !== null && $weightKg !== null && $weightKg > 0.0)
            ? round($latestFtp / $weightKg, 2)
            : null;

        $fitnessSeries = $this->buildFitnessSeries($baseQuery, $today);
        $latestFitness = $fitnessSeries !== [] ? $fitnessSeries[count($fitnessSeries) - 1] : null;

        $summary = $this->buildSummary(
            $baseQuery,
            $entry['sport_category'],
            $today,
            $latestFtp,
            $wPerKg,
            $latestFitness,
        );

        $availableYears = $this->buildAvailableYears($baseQuery, $currentYear);

        $paceModule = $this->buildPaceModule(
            $baseQuery,
            $normalizedKey,
            $entry['sport_category'],
            $entry['best_effort_profile'],
            $normalizedEnvironment,
            $normalizedTerrain,
            $selectedYear,
            $currentYear,
            $today,
            $summary['total_sessions'],
        );

        if ($paceModule !== null && is_array($paceModule['bestEfforts'])) {
            $availableYears = array_values(array_unique([
                ...$availableYears,
                ...$paceModule['bestEfforts']['availableYears'],
            ]));
            rsort($availableYears);
        }

        $powerModule = $this->buildPowerModule(
            $baseQuery,
            $normalizedKey,
            $entry['sport_category'],
            $entry['best_effort_profile'],
            $normalizedEnvironment,
            $normalizedTerrain,
            $today,
            $latestFtp,
            $weightKg,
            $wPerKg,
            $fitnessSeries,
            $latestFitness,
        );

        $climbingModule = $this->buildClimbingModule(
            $baseQuery,
            $entry['indoor_only'],
            $normalizedEnvironment,
            $summary['total_sessions'],
            $summary['total_elevation_gain'],
        );

        $offroadModule = $this->buildOffroadModule($baseQuery);

        return [
            'sport' => [
                ...$entry,
                'family_key' => $familyKey,
                'tabs' => $tabs,
            ],
            'filters' => [
                'environment' => $normalizedEnvironment,
                'terrain' => $normalizedTerrain,
                'volumeRange' => $normalizedVolumeRange,
                'year' => $selectedYear,
                'zoneRange' => $normalizedZoneRange,
                'availableYears' => $availableYears,
                'showEnvironmentFilter' => $showEnvironmentFilter,
                'showTerrainFilter' => $showTerrainFilter,
            ],
            'summary' => $summary,
            'weeklyVolume' => $this->buildWeeklyVolume($baseQuery, $normalizedVolumeRange, $today, $timezone),
            'hrZones' => $this->buildHrZones($baseQuery, $normalizedZoneRange, $today),
            'goals' => $this->collectSportGoals($normalizedKey),
            'milestones' => $this->buildMilestones($baseQuery, $entry['sport_category']),
            'recentActivities' => $this->buildRecentActivities($baseQuery),
            'modules' => [
                'pace' => $paceModule,
                'power' => $powerModule,
                'climbing' => $climbingModule,
                'offroad' => $offroadModule,
            ],
        ];
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function yearBounds(CarbonImmutable $today): array
    {
        return [
            $today->startOfYear()->format('Y-m-d').' 00:00:00',
            $today->endOfYear()->format('Y-m-d').' 23:59:59',
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @param  ?array{date: string, tss: float, ctl: float, atl: float, tsb: float}  $latestFitness
     * @return array{
     *     ytd_sessions: int,
     *     ytd_distance_meters: float,
     *     ytd_duration_seconds: float,
     *     ytd_elevation_gain: float,
     *     ytd_load: float,
     *     ytd_avg_speed: ?float,
     *     ytd_avg_gap_speed: ?float,
     *     ytd_avg_hr: ?float,
     *     total_sessions: int,
     *     total_distance_meters: float,
     *     total_elevation_gain: float,
     *     weekly_avg_distance_meters: float,
     *     weekly_avg_elevation_gain: float,
     *     latest_vo2_max: ?float,
     *     latest_ftp: ?float,
     *     w_per_kg: ?float,
     *     latest_ctl: float,
     *     latest_atl: float,
     *     latest_tsb: float
     * }
     */
    private function buildSummary(
        Builder $baseQuery,
        string $sportCategory,
        CarbonImmutable $today,
        ?float $latestFtp,
        ?float $wPerKg,
        ?array $latestFitness,
    ): array {
        $ytdBounds = $this->yearBounds($today);

        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(COALESCE(tss, training_load, 0)), 0) as load')
            ->selectRaw('AVG(CASE WHEN avg_grade_adjusted_speed > 0 THEN avg_grade_adjusted_speed END) as avg_gap_speed')
            ->selectRaw('AVG(CASE WHEN avg_hr > 0 THEN avg_hr END) as avg_hr')
            ->first();

        $totalRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->first();

        $ytdDistance = $this->rowFloat($ytdRow, 'distance_meters');
        $ytdDuration = $this->rowFloat($ytdRow, 'duration_seconds');
        $ytdElevation = $this->rowFloat($ytdRow, 'elevation_gain');
        $ytdAvgSpeed = ($ytdDistance > 0.0 && $ytdDuration > 0.0)
            ? round($ytdDistance / $ytdDuration, 3)
            : null;

        $ytdWeeksElapsed = max(1.0, $today->dayOfYear / 7.0);

        $vo2Sport = match ($sportCategory) {
            'running' => 'running',
            'biking' => 'cycling',
            default => null,
        };

        $latestVo2 = null;

        if ($vo2Sport !== null) {
            $latestVo2Record = Vo2MaxRecord::query()
                ->where('sport', $vo2Sport)
                ->orderByDesc('calendar_date')
                ->orderByDesc('id')
                ->first();

            if ($latestVo2Record !== null) {
                $val = $latestVo2Record->vo2_max_precise ?? $latestVo2Record->vo2_max;
                $latestVo2 = $val !== null ? round($val, 1) : null;
            }
        }

        return [
            'ytd_sessions' => $this->rowInt($ytdRow, 'sessions'),
            'ytd_distance_meters' => round($ytdDistance, 1),
            'ytd_duration_seconds' => round($ytdDuration, 1),
            'ytd_elevation_gain' => round($ytdElevation, 1),
            'ytd_load' => round($this->rowFloat($ytdRow, 'load'), 1),
            'ytd_avg_speed' => $ytdAvgSpeed,
            'ytd_avg_gap_speed' => $this->rowNullableFloat($ytdRow, 'avg_gap_speed', 3),
            'ytd_avg_hr' => $this->rowNullableFloat($ytdRow, 'avg_hr', 1),
            'total_sessions' => $this->rowInt($totalRow, 'sessions'),
            'total_distance_meters' => round($this->rowFloat($totalRow, 'distance_meters'), 1),
            'total_elevation_gain' => round($this->rowFloat($totalRow, 'elevation_gain'), 1),
            'weekly_avg_distance_meters' => round($ytdDistance / $ytdWeeksElapsed, 1),
            'weekly_avg_elevation_gain' => round($ytdElevation / $ytdWeeksElapsed, 1),
            'latest_vo2_max' => $latestVo2,
            'latest_ftp' => $latestFtp,
            'w_per_kg' => $wPerKg,
            'latest_ctl' => $latestFitness['ctl'] ?? 0.0,
            'latest_atl' => $latestFitness['atl'] ?? 0.0,
            'latest_tsb' => $latestFitness['tsb'] ?? 0.0,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<int>
     */
    private function buildAvailableYears(Builder $baseQuery, int $currentYear): array
    {
        $dates = (clone $baseQuery)
            ->select(['started_at_local'])
            ->get();

        /** @var array<int, bool> $yearMap */
        $yearMap = [$currentYear => true];

        foreach ($dates as $row) {
            $yearMap[(int) $row->started_at_local->year] = true;
        }

        krsort($yearMap);

        return array_keys($yearMap);
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     week_start: string,
     *     label: string,
     *     distance_meters: float,
     *     elevation_gain: float,
     *     duration_seconds: float,
     *     training_load: float,
     *     tss: float,
     *     sessions: int
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
            ->get([
                'started_at_local',
                'distance_meters',
                'elevation_gain',
                'duration_seconds',
                'training_load',
                'tss',
            ]);

        /** @var array<string, array{distance_meters: float, elevation_gain: float, duration_seconds: float, training_load: float, tss: float, sessions: int}> $byWeek */
        $byWeek = [];

        foreach ($activities as $activity) {
            $weekKey = $activity->started_at_local
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');

            if (! isset($byWeek[$weekKey])) {
                $byWeek[$weekKey] = [
                    'distance_meters' => 0.0,
                    'elevation_gain' => 0.0,
                    'duration_seconds' => 0.0,
                    'training_load' => 0.0,
                    'tss' => 0.0,
                    'sessions' => 0,
                ];
            }

            $byWeek[$weekKey]['distance_meters'] += $activity->distance_meters;
            $byWeek[$weekKey]['elevation_gain'] += $activity->elevation_gain ?? 0.0;
            $byWeek[$weekKey]['duration_seconds'] += $activity->duration_seconds;
            $byWeek[$weekKey]['training_load'] += max(0.0, $activity->training_load ?? $activity->tss ?? 0.0);
            $byWeek[$weekKey]['tss'] += max(0.0, $activity->tss ?? 0.0);
            $byWeek[$weekKey]['sessions']++;
        }

        $result = [];
        $cursor = $windowStart;

        while ($cursor->lessThanOrEqualTo($currentWeekStart)) {
            $weekKey = $cursor->format('Y-m-d');
            $bucket = $byWeek[$weekKey] ?? [
                'distance_meters' => 0.0,
                'elevation_gain' => 0.0,
                'duration_seconds' => 0.0,
                'training_load' => 0.0,
                'tss' => 0.0,
                'sessions' => 0,
            ];

            $result[] = [
                'week_start' => $weekKey,
                'label' => $cursor->format('j M'),
                'distance_meters' => round($bucket['distance_meters'], 1),
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'training_load' => round($bucket['training_load'], 1),
                'tss' => round($bucket['tss'], 1),
                'sessions' => $bucket['sessions'],
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
     * @return list<array<string, mixed>>
     */
    private function collectSportGoals(string $sportKey): array
    {
        $keys = [$sportKey];

        if ($sportKey === 'cycling') {
            $keys[] = 'biking';
        } elseif ($sportKey === 'road_cycling') {
            $keys[] = 'road_biking';
        } elseif ($sportKey === 'mtb_cycling') {
            $keys[] = 'mtb_biking';
        }

        $goals = [];
        $seen = [];

        foreach ($keys as $key) {
            foreach ($this->goalProgressService->activeGoals(sport: $key) as $goal) {
                $id = $goal['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $goals[] = $goal;
            }
        }

        return $goals;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     longest: ?array{activity_id: int, name: string, date: string, distance_meters: float, duration_seconds: float},
     *     fastest: ?array{activity_id: int, name: string, date: string, avg_speed: float, distance_meters: float},
     *     biggest_climb: ?array{activity_id: int, name: string, date: string, elevation_gain: float, distance_meters: float},
     *     highest_load: ?array{activity_id: int, name: string, date: string, training_load: ?float, tss: ?float, duration_seconds: float},
     *     hardest_grit: ?array{activity_id: int, name: string, date: string, grit: float, avg_flow: ?float, distance_meters: float, location_name: ?string}
     * }
     */
    private function buildMilestones(Builder $baseQuery, string $sportCategory): array
    {
        $longest = (clone $baseQuery)
            ->where('distance_meters', '>', 0)
            ->orderByDesc('distance_meters')
            ->orderByDesc('started_at_local')
            ->first();

        $minFastestMeters = match ($sportCategory) {
            'biking' => self::FASTEST_CYCLING_MIN_DISTANCE_METERS,
            'running' => self::FASTEST_RUNNING_MIN_DISTANCE_METERS,
            default => 0.0,
        };

        $fastest = null;

        if ($minFastestMeters > 0.0) {
            $fastest = (clone $baseQuery)
                ->where('distance_meters', '>=', $minFastestMeters)
                ->where('avg_speed', '>', 0)
                ->orderByDesc('avg_speed')
                ->orderByDesc('started_at_local')
                ->first();
        }

        if ($fastest === null) {
            $fastest = (clone $baseQuery)
                ->where('distance_meters', '>', 0)
                ->where('avg_speed', '>', 0)
                ->orderByDesc('avg_speed')
                ->orderByDesc('started_at_local')
                ->first();
        }

        $biggestClimb = (clone $baseQuery)
            ->where('elevation_gain', '>', 0)
            ->orderByDesc('elevation_gain')
            ->orderByDesc('started_at_local')
            ->first();

        $highestLoad = (clone $baseQuery)
            ->whereRaw('COALESCE(tss, training_load, 0) > 0')
            ->orderByRaw('COALESCE(tss, training_load, 0) DESC')
            ->orderByDesc('started_at_local')
            ->first();

        $hardestGrit = (clone $baseQuery)
            ->where('grit', '>', 0)
            ->orderByDesc('grit')
            ->orderByDesc('started_at_local')
            ->first();

        return [
            'longest' => $longest !== null ? [
                'activity_id' => $longest->id,
                'name' => $longest->name,
                'date' => $longest->started_at_local->format('Y-m-d'),
                'distance_meters' => round($longest->distance_meters, 1),
                'duration_seconds' => round($longest->duration_seconds, 1),
            ] : null,
            'fastest' => ($fastest !== null && $fastest->avg_speed !== null) ? [
                'activity_id' => $fastest->id,
                'name' => $fastest->name,
                'date' => $fastest->started_at_local->format('Y-m-d'),
                'avg_speed' => round($fastest->avg_speed, 3),
                'distance_meters' => round($fastest->distance_meters, 1),
            ] : null,
            'biggest_climb' => ($biggestClimb !== null && $biggestClimb->elevation_gain !== null) ? [
                'activity_id' => $biggestClimb->id,
                'name' => $biggestClimb->name,
                'date' => $biggestClimb->started_at_local->format('Y-m-d'),
                'elevation_gain' => round($biggestClimb->elevation_gain, 1),
                'distance_meters' => round($biggestClimb->distance_meters, 1),
            ] : null,
            'highest_load' => $highestLoad !== null ? [
                'activity_id' => $highestLoad->id,
                'name' => $highestLoad->name,
                'date' => $highestLoad->started_at_local->format('Y-m-d'),
                'training_load' => $highestLoad->training_load !== null ? round($highestLoad->training_load, 1) : null,
                'tss' => $highestLoad->tss !== null ? round($highestLoad->tss, 1) : null,
                'duration_seconds' => round($highestLoad->duration_seconds, 1),
            ] : null,
            'hardest_grit' => ($hardestGrit !== null && $hardestGrit->grit !== null) ? [
                'activity_id' => $hardestGrit->id,
                'name' => $hardestGrit->name,
                'date' => $hardestGrit->started_at_local->format('Y-m-d'),
                'grit' => round($hardestGrit->grit, 1),
                'avg_flow' => $hardestGrit->avg_flow !== null ? round($hardestGrit->avg_flow, 1) : null,
                'distance_meters' => round($hardestGrit->distance_meters, 1),
                'location_name' => $hardestGrit->location_name,
            ] : null,
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
                'elevation_gain',
                'elevation_loss',
                'avg_speed',
                'avg_grade_adjusted_speed',
                'avg_hr',
                'avg_cadence',
                'avg_power',
                'normalized_power',
                'tss',
                'intensity_factor',
                'training_load',
                'grit',
                'avg_flow',
                'jump_count',
                'unpaved_pct',
                'is_pr',
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
                'elevation_gain' => $activity->elevation_gain,
                'elevation_loss' => $activity->elevation_loss,
                'avg_speed' => $activity->avg_speed,
                'avg_grade_adjusted_speed' => $activity->avg_grade_adjusted_speed,
                'avg_hr' => $activity->avg_hr,
                'avg_cadence' => $activity->avg_cadence,
                'avg_power' => $activity->avg_power,
                'normalized_power' => $activity->normalized_power,
                'tss' => $activity->tss,
                'intensity_factor' => $activity->intensity_factor,
                'training_load' => $activity->training_load,
                'grit' => $activity->grit,
                'avg_flow' => $activity->avg_flow,
                'jump_count' => $activity->jump_count,
                'unpaved_pct' => $activity->unpaved_pct,
                'is_pr' => $activity->is_pr,
            ];
        }

        return $result;
    }

    /**
     * Build the Pace & Running Dynamics module for running hubs.
     *
     * @param  Builder<Activity>  $baseQuery
     * @return ?array{
     *     efficiency: list<array{
     *         date: string,
     *         activity_id: int,
     *         name: string,
     *         raw_speed: ?float,
     *         gap_speed: ?float,
     *         effective_speed: float,
     *         pace_sec_per_km: float,
     *         avg_hr: int,
     *         efficiency: float
     *     }>,
     *     cadenceStride: list<array{date: string, name: string, cadence: float, stride_length_cm: ?float}>,
     *     runWalkBreakdown: ?array{
     *         run_seconds: float,
     *         walk_seconds: float,
     *         idle_seconds: float,
     *         total_seconds: float,
     *         run_pct: float,
     *         walk_pct: float,
     *         idle_pct: float
     *     },
     *     vo2MaxTrend: list<array{date: string, value: float}>,
     *     bestEfforts: ?array{
     *         selectedYear: int,
     *         availableYears: list<int>,
     *         distances: list<BestEffortDistanceBoard>
     *     },
     *     racePredictor: ?array{
     *         source_label: string,
     *         source_time_seconds: float,
     *         source_date: string,
     *         predictions: list<RacePredictionItem>
     *     }
     * }
     */
    private function buildPaceModule(
        Builder $baseQuery,
        string $sportKey,
        string $sportCategory,
        ?string $bestEffortProfile,
        string $environment,
        string $terrain,
        int $selectedYear,
        int $currentYear,
        CarbonImmutable $today,
        int $totalSessions,
    ): ?array {
        if ($sportCategory !== 'running') {
            return null;
        }

        $vo2MaxTrend = $this->buildRunningVo2Trend();
        $efforts = $bestEffortProfile === 'running'
            ? $this->queryRunningBestEfforts($sportKey, $environment, $terrain)
            : new Collection;

        if ($totalSessions === 0 && $vo2MaxTrend === [] && $efforts->isEmpty()) {
            return null;
        }

        $config = $this->sports->resolvedConfig();
        $showRacePredictor = $bestEffortProfile === 'running'
            && ($sportKey !== 'trail_running' || $config['best_efforts']['trail_in_running_prs']);

        return [
            'efficiency' => $this->buildRunningEfficiency($baseQuery),
            'cadenceStride' => $this->buildCadenceStrideTrend($baseQuery),
            'runWalkBreakdown' => $this->buildRunWalkBreakdown($baseQuery),
            'vo2MaxTrend' => $vo2MaxTrend,
            'bestEfforts' => $bestEffortProfile === 'running'
                ? $this->buildRunningBestEfforts($efforts, $selectedYear, $currentYear)
                : null,
            'racePredictor' => $showRacePredictor
                ? $this->buildRacePredictor($efforts, $today)
                : null,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     date: string,
     *     activity_id: int,
     *     name: string,
     *     raw_speed: ?float,
     *     gap_speed: ?float,
     *     effective_speed: float,
     *     pace_sec_per_km: float,
     *     avg_hr: int,
     *     efficiency: float
     * }>
     */
    private function buildRunningEfficiency(Builder $baseQuery): array
    {
        $runs = (clone $baseQuery)
            ->where(static function (Builder $query): void {
                $query->where('avg_grade_adjusted_speed', '>', 0)
                    ->orWhere('avg_speed', '>', 0);
            })
            ->whereNotNull('avg_hr')
            ->where('avg_hr', '>', 0)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(60)
            ->get(['id', 'name', 'started_at_local', 'avg_speed', 'avg_grade_adjusted_speed', 'avg_hr'])
            ->reverse()
            ->values();

        $series = [];

        foreach ($runs as $run) {
            $gapSpeed = ($run->avg_grade_adjusted_speed !== null && $run->avg_grade_adjusted_speed > 0.0)
                ? round($run->avg_grade_adjusted_speed, 3)
                : null;
            $rawSpeed = ($run->avg_speed !== null && $run->avg_speed > 0.0)
                ? round($run->avg_speed, 3)
                : null;
            $effectiveSpeed = $gapSpeed ?? $rawSpeed;
            $avgHr = $run->avg_hr;

            if ($effectiveSpeed === null || $effectiveSpeed <= 0.0 || $avgHr === null || $avgHr <= 0) {
                continue;
            }

            $series[] = [
                'date' => $run->started_at_local->format('Y-m-d'),
                'activity_id' => $run->id,
                'name' => $run->name,
                'raw_speed' => $rawSpeed,
                'gap_speed' => $gapSpeed,
                'effective_speed' => $effectiveSpeed,
                'pace_sec_per_km' => round(1000.0 / $effectiveSpeed, 1),
                'avg_hr' => $avgHr,
                'efficiency' => round(($effectiveSpeed * 60.0) / $avgHr, 3),
            ];
        }

        return $series;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{date: string, name: string, cadence: float, stride_length_cm: ?float}>
     */
    private function buildCadenceStrideTrend(Builder $baseQuery): array
    {
        $runs = (clone $baseQuery)
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
     * @param  Builder<Activity>  $baseQuery
     * @return ?array{
     *     run_seconds: float,
     *     walk_seconds: float,
     *     idle_seconds: float,
     *     total_seconds: float,
     *     run_pct: float,
     *     walk_pct: float,
     *     idle_pct: float
     * }
     */
    private function buildRunWalkBreakdown(Builder $baseQuery): ?array
    {
        $row = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COALESCE(SUM(COALESCE(run_duration_seconds, 0)), 0) as run_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(walk_duration_seconds, 0)), 0) as walk_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(idle_duration_seconds, 0)), 0) as idle_seconds')
            ->first();

        $runSeconds = $this->rowFloat($row, 'run_seconds');
        $walkSeconds = $this->rowFloat($row, 'walk_seconds');
        $idleSeconds = $this->rowFloat($row, 'idle_seconds');
        $totalSeconds = $runSeconds + $walkSeconds + $idleSeconds;

        if ($totalSeconds <= 0.0) {
            return null;
        }

        return [
            'run_seconds' => round($runSeconds, 1),
            'walk_seconds' => round($walkSeconds, 1),
            'idle_seconds' => round($idleSeconds, 1),
            'total_seconds' => round($totalSeconds, 1),
            'run_pct' => round(($runSeconds / $totalSeconds) * 100.0, 1),
            'walk_pct' => round(($walkSeconds / $totalSeconds) * 100.0, 1),
            'idle_pct' => round(($idleSeconds / $totalSeconds) * 100.0, 1),
        ];
    }

    /**
     * @return list<array{date: string, value: float}>
     */
    private function buildRunningVo2Trend(): array
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
     * @return Collection<int, BestEffort>
     */
    private function queryRunningBestEfforts(string $sportKey, string $environment, string $terrain): Collection
    {
        $config = $this->sports->resolvedConfig();
        $beSports = match (true) {
            $sportKey === 'trail_running' => ['trail_running', 'running'],
            $sportKey === 'running' && $config['best_efforts']['trail_in_running_prs'] => ['running', 'trail_running'],
            default => ['running'],
        };

        return BestEffort::query()
            ->whereIn('sport', $beSports)
            ->whereHas('activity', function (Builder $query) use ($sportKey, $environment, $terrain): void {
                $this->sports->applyScope($query, $sportKey, $environment, $terrain);
            })
            ->with('activity:id,name,started_at_local,distance_meters,location_name')
            ->get();
    }

    /**
     * @param  Collection<int, BestEffort>  $efforts
     * @return array{
     *     selectedYear: int,
     *     availableYears: list<int>,
     *     distances: list<BestEffortDistanceBoard>
     * }
     */
    private function buildRunningBestEfforts(Collection $efforts, int $selectedYear, int $currentYear): array
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
            usort($byPerformance, static function (BestEffort $a, BestEffort $b): int {
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
                static fn (BestEffort $effort): bool => (int) $effort->achieved_at->year === $selectedYear,
            ));

            $annualTop10 = [];

            foreach (array_slice($annualFiltered, 0, 10) as $index => $effort) {
                $annualTop10[] = $this->mapBestEffortBoardEntry($effort, $distanceMeters, $index + 1);
            }

            $chronological = $included;
            usort($chronological, static function (BestEffort $a, BestEffort $b): int {
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

            usort($excluded, static function (BestEffort $a, BestEffort $b): int {
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
     * Build the Speed & Power module for cycling hubs whenever power telemetry exists.
     *
     * @param  Builder<Activity>  $baseQuery
     * @param  list<array{date: string, tss: float, ctl: float, atl: float, tsb: float}>  $fitnessSeries
     * @param  ?array{date: string, tss: float, ctl: float, atl: float, tsb: float}  $latestFitness
     * @return ?array<string, mixed>
     */
    private function buildPowerModule(
        Builder $baseQuery,
        string $sportKey,
        string $sportCategory,
        ?string $bestEffortProfile,
        string $environment,
        string $terrain,
        CarbonImmutable $today,
        ?float $latestFtp,
        ?float $weightKg,
        ?float $wPerKg,
        array $fitnessSeries,
        ?array $latestFitness,
    ): ?array {
        if ($sportCategory !== 'biking') {
            return null;
        }

        $hasPowerData = (clone $baseQuery)
            ->where(static function (Builder $query): void {
                $query->where('avg_power', '>', 0)
                    ->orWhere('normalized_power', '>', 0)
                    ->orWhereNotNull('power_curve')
                    ->orWhere('power_zone_1_seconds', '>', 0)
                    ->orWhere('power_zone_2_seconds', '>', 0)
                    ->orWhere('power_zone_3_seconds', '>', 0)
                    ->orWhere('power_zone_4_seconds', '>', 0)
                    ->orWhere('power_zone_5_seconds', '>', 0)
                    ->orWhere('power_zone_6_seconds', '>', 0)
                    ->orWhere('power_zone_7_seconds', '>', 0);
            })
            ->exists();

        if (! $hasPowerData) {
            return null;
        }

        $powerAvgRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('AVG(CASE WHEN normalized_power > 0 THEN normalized_power END) as avg_np')
            ->selectRaw('AVG(CASE WHEN intensity_factor > 0 THEN intensity_factor END) as avg_if')
            ->selectRaw('AVG(CASE WHEN normalized_power > 0 AND avg_power > 0 THEN normalized_power / avg_power END) as avg_vi')
            ->selectRaw('AVG(CASE WHEN left_balance > 0 THEN left_balance END) as avg_left_balance')
            ->first();

        return [
            'ftp' => $latestFtp,
            'weight_kg' => $weightKg,
            'w_per_kg' => $wPerKg,
            'latest_ctl' => $latestFitness['ctl'] ?? 0.0,
            'latest_atl' => $latestFitness['atl'] ?? 0.0,
            'latest_tsb' => $latestFitness['tsb'] ?? 0.0,
            'avg_np' => $this->rowNullableFloat($powerAvgRow, 'avg_np', 1),
            'avg_if' => $this->rowNullableFloat($powerAvgRow, 'avg_if', 2),
            'avg_vi' => $this->rowNullableFloat($powerAvgRow, 'avg_vi', 2),
            'avg_left_balance' => $this->rowNullableFloat($powerAvgRow, 'avg_left_balance', 1),
            'powerCurve' => $this->buildPowerCurve($baseQuery, $today, $weightKg),
            'fitnessSeries' => $fitnessSeries,
            'weeklyTss' => $this->buildWeeklyTss($baseQuery, $today),
            'ftpHistory' => $this->buildFtpHistory($baseQuery),
            'powerZones' => $this->buildPowerZonesWithFallback($baseQuery, $today),
            'bestEfforts' => $bestEffortProfile === 'cycling'
                ? $this->buildCyclingBestEfforts($sportKey, $environment, $terrain)
                : null,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     */
    private function resolveLatestFtp(Builder $baseQuery): ?float
    {
        $withFtp = (clone $baseQuery)
            ->select(['ftp'])
            ->where('ftp', '>', 0)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->first();

        if ($withFtp !== null && $withFtp->ftp !== null && $withFtp->ftp > 0.0) {
            return round($withFtp->ftp, 1);
        }

        $with20Min = (clone $baseQuery)
            ->select(['max_20min_power'])
            ->where('max_20min_power', '>', 0)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->first();

        if ($with20Min !== null && $with20Min->max_20min_power !== null && $with20Min->max_20min_power > 0.0) {
            return round($with20Min->max_20min_power * 0.95, 1);
        }

        return null;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     seconds: int,
     *     label: string,
     *     allTime: ?float,
     *     season: ?float,
     *     last90d: ?float,
     *     allTimeWkg: ?float,
     *     seasonWkg: ?float,
     *     last90dWkg: ?float
     * }>
     */
    private function buildPowerCurve(Builder $baseQuery, CarbonImmutable $today, ?float $weightKg): array
    {
        $seasonStart = $today->startOfYear()->format('Y-m-d').' 00:00:00';
        $seasonEnd = $today->endOfYear()->format('Y-m-d').' 23:59:59';
        $last90dStart = $today->subDays(89)->format('Y-m-d').' 00:00:00';
        $last90dEnd = $today->format('Y-m-d').' 23:59:59';

        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'power_curve'])
            ->whereNotNull('power_curve')
            ->get();

        /** @var array<int, ?float> $allTimeMax */
        $allTimeMax = [];
        /** @var array<int, ?float> $seasonMax */
        $seasonMax = [];
        /** @var array<int, ?float> $last90dMax */
        $last90dMax = [];

        foreach (array_keys(self::POWER_CURVE_DURATIONS) as $seconds) {
            $allTimeMax[$seconds] = null;
            $seasonMax[$seconds] = null;
            $last90dMax[$seconds] = null;
        }

        foreach ($rides as $ride) {
            $curve = $ride->power_curve;

            if (! is_array($curve) || $curve === []) {
                continue;
            }

            $startedStr = $ride->started_at_local->format('Y-m-d H:i:s');
            $inSeason = $startedStr >= $seasonStart && $startedStr <= $seasonEnd;
            $inLast90d = $startedStr >= $last90dStart && $startedStr <= $last90dEnd;

            foreach (array_keys(self::POWER_CURVE_DURATIONS) as $seconds) {
                $rawWatts = $curve[$seconds] ?? $curve[(string) $seconds] ?? null;

                if (! is_numeric($rawWatts)) {
                    continue;
                }

                $watts = (float) $rawWatts;

                if ($watts <= 0.0) {
                    continue;
                }

                if ($allTimeMax[$seconds] === null || $watts > $allTimeMax[$seconds]) {
                    $allTimeMax[$seconds] = $watts;
                }

                if ($inSeason && ($seasonMax[$seconds] === null || $watts > $seasonMax[$seconds])) {
                    $seasonMax[$seconds] = $watts;
                }

                if ($inLast90d && ($last90dMax[$seconds] === null || $watts > $last90dMax[$seconds])) {
                    $last90dMax[$seconds] = $watts;
                }
            }
        }

        $points = [];

        foreach (self::POWER_CURVE_DURATIONS as $seconds => $label) {
            $allTime = $allTimeMax[$seconds];
            $season = $seasonMax[$seconds];
            $last90d = $last90dMax[$seconds];

            if ($allTime === null && $season === null && $last90d === null) {
                continue;
            }

            $allTimeRounded = $allTime !== null ? round($allTime, 1) : null;
            $seasonRounded = $season !== null ? round($season, 1) : null;
            $last90dRounded = $last90d !== null ? round($last90d, 1) : null;

            $points[] = [
                'seconds' => $seconds,
                'label' => $label,
                'allTime' => $allTimeRounded,
                'season' => $seasonRounded,
                'last90d' => $last90dRounded,
                'allTimeWkg' => ($allTimeRounded !== null && $weightKg !== null && $weightKg > 0.0)
                    ? round($allTimeRounded / $weightKg, 2)
                    : null,
                'seasonWkg' => ($seasonRounded !== null && $weightKg !== null && $weightKg > 0.0)
                    ? round($seasonRounded / $weightKg, 2)
                    : null,
                'last90dWkg' => ($last90dRounded !== null && $weightKg !== null && $weightKg > 0.0)
                    ? round($last90dRounded / $weightKg, 2)
                    : null,
            ];
        }

        return $points;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{date: string, tss: float, ctl: float, atl: float, tsb: float}>
     */
    private function buildFitnessSeries(Builder $baseQuery, CarbonImmutable $today): array
    {
        $outputStart = $today->subDays(self::FITNESS_SERIES_DAYS - 1);
        $warmupStart = $outputStart->subDays(self::FITNESS_WARMUP_DAYS);

        $activities = (clone $baseQuery)
            ->select(['started_at_local', 'tss', 'training_load'])
            ->whereBetween('started_at_local', [
                $warmupStart->format('Y-m-d').' 00:00:00',
                $today->format('Y-m-d').' 23:59:59',
            ])
            ->get();

        /** @var array<string, float> $dailyLoad */
        $dailyLoad = [];

        foreach ($activities as $activity) {
            $load = $activity->tss ?? $activity->training_load ?? 0.0;

            if ($load <= 0.0) {
                continue;
            }

            $dateKey = $activity->started_at_local->format('Y-m-d');
            $dailyLoad[$dateKey] = ($dailyLoad[$dateKey] ?? 0.0) + $load;
        }

        $ctl = 0.0;
        $atl = 0.0;
        $series = [];
        $cursor = $warmupStart;

        while ($cursor->lessThanOrEqualTo($today)) {
            $dateStr = $cursor->format('Y-m-d');
            $tss = $dailyLoad[$dateStr] ?? 0.0;
            $tsb = $ctl - $atl;

            $ctl = $ctl + (($tss - $ctl) / 42.0);
            $atl = $atl + (($tss - $atl) / 7.0);

            if ($cursor->greaterThanOrEqualTo($outputStart)) {
                $series[] = [
                    'date' => $dateStr,
                    'tss' => round($tss, 1),
                    'ctl' => round($ctl, 1),
                    'atl' => round($atl, 1),
                    'tsb' => round($tsb, 1),
                ];
            }

            $cursor = $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{week_start: string, label: string, tss: float, distance_meters: float, sessions: int}>
     */
    private function buildWeeklyTss(Builder $baseQuery, CarbonImmutable $today): array
    {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $currentWeekStart->subWeeks(self::WEEKLY_TSS_WEEKS - 1);
        $lastWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY);

        /** @var array<string, array{week_start: string, label: string, tss: float, distance_meters: float, sessions: int}> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_TSS_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $key = $weekStart->format('Y-m-d');
            $buckets[$key] = [
                'week_start' => $key,
                'label' => $weekStart->format('j M'),
                'tss' => 0.0,
                'distance_meters' => 0.0,
                'sessions' => 0,
            ];
        }

        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'tss', 'training_load', 'distance_meters'])
            ->whereBetween('started_at_local', [
                $firstWeekStart->format('Y-m-d').' 00:00:00',
                $lastWeekEnd->format('Y-m-d').' 23:59:59',
            ])
            ->get();

        foreach ($rides as $ride) {
            $weekKey = $ride->started_at_local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

            if (! isset($buckets[$weekKey])) {
                continue;
            }

            $load = $ride->tss ?? $ride->training_load ?? 0.0;
            $buckets[$weekKey]['tss'] += max(0.0, $load);
            $buckets[$weekKey]['distance_meters'] += $ride->distance_meters;
            $buckets[$weekKey]['sessions']++;
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'tss' => round($bucket['tss'], 1),
                'distance_meters' => round($bucket['distance_meters'], 1),
                'sessions' => $bucket['sessions'],
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{date: string, ftp: ?float, max_20min_power: ?float}>
     */
    private function buildFtpHistory(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'ftp', 'max_20min_power'])
            ->where(static function (Builder $query): void {
                $query->where('ftp', '>', 0)
                    ->orWhere('max_20min_power', '>', 0);
            })
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        $trend = [];

        foreach ($rides as $ride) {
            $trend[] = [
                'date' => $ride->started_at_local->format('Y-m-d'),
                'ftp' => ($ride->ftp !== null && $ride->ftp > 0.0) ? round($ride->ftp, 1) : null,
                'max_20min_power' => ($ride->max_20min_power !== null && $ride->max_20min_power > 0.0)
                    ? round($ride->max_20min_power, 1)
                    : null,
            ];
        }

        return $trend;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<ZoneAggregate>
     */
    private function buildPowerZonesWithFallback(Builder $baseQuery, CarbonImmutable $today): array
    {
        $last90dQuery = (clone $baseQuery)->whereBetween('started_at_local', [
            $today->subDays(89)->format('Y-m-d').' 00:00:00',
            $today->format('Y-m-d').' 23:59:59',
        ]);

        $zones = $this->aggregatePowerZones($last90dQuery);

        if ($zones !== []) {
            return $zones;
        }

        return $this->aggregatePowerZones(clone $baseQuery);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<ZoneAggregate>
     */
    private function aggregatePowerZones(Builder $query): array
    {
        $row = $query
            ->toBase()
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_1_seconds, 0)), 0) as z1')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_2_seconds, 0)), 0) as z2')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_3_seconds, 0)), 0) as z3')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_4_seconds, 0)), 0) as z4')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_5_seconds, 0)), 0) as z5')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_6_seconds, 0)), 0) as z6')
            ->selectRaw('COALESCE(SUM(COALESCE(power_zone_7_seconds, 0)), 0) as z7')
            ->first();

        $secondsByZone = [
            1 => $this->rowFloat($row, 'z1'),
            2 => $this->rowFloat($row, 'z2'),
            3 => $this->rowFloat($row, 'z3'),
            4 => $this->rowFloat($row, 'z4'),
            5 => $this->rowFloat($row, 'z5'),
            6 => $this->rowFloat($row, 'z6'),
            7 => $this->rowFloat($row, 'z7'),
        ];

        $total = array_sum($secondsByZone);

        if ($total <= 0.0) {
            return [];
        }

        $result = [];

        foreach ($secondsByZone as $zone => $seconds) {
            $result[] = [
                'zone' => $zone,
                'label' => self::POWER_ZONE_LABELS[$zone],
                'seconds' => round($seconds, 1),
                'pct' => round(($seconds / $total) * 100.0, 1),
            ];
        }

        return $result;
    }

    /**
     * @return ?list<array{
     *     key: string,
     *     label: string,
     *     distance_meters: float,
     *     best: ?array{
     *         id: int,
     *         activity_id: int,
     *         activity_name: string,
     *         achieved_at: string,
     *         effective_seconds: float,
     *         avg_speed: float
     *     }
     * }>
     */
    private function buildCyclingBestEfforts(string $sportKey, string $environment, string $terrain): ?array
    {
        $config = $this->sports->resolvedConfig();
        $enabledDisciplines = $config['best_efforts']['cycling_disciplines'];

        if ($enabledDisciplines === []) {
            return null;
        }

        $beSports = match ($sportKey) {
            'cycling' => array_values(array_filter(array_map(
                static fn (string $disc): ?string => match ($disc) {
                    'road' => 'road_biking',
                    'gravel' => 'gravel_cycling',
                    'mtb' => 'mtb_cycling',
                    'ebike' => 'ebike_cycling',
                    default => null,
                },
                $enabledDisciplines,
            ))),
            'road_cycling' => in_array('road', $enabledDisciplines, true) ? ['road_biking', 'road_cycling'] : [],
            'gravel_cycling' => in_array('gravel', $enabledDisciplines, true) ? ['gravel_cycling'] : [],
            'mtb_cycling' => in_array('mtb', $enabledDisciplines, true) ? ['mtb_cycling'] : [],
            'ebike_cycling' => in_array('ebike', $enabledDisciplines, true) ? ['ebike_cycling'] : [],
            default => [],
        };

        if ($beSports === []) {
            return null;
        }

        $efforts = BestEffort::query()
            ->with('activity:id,name,is_indoor')
            ->whereIn('sport', $beSports)
            ->where('is_excluded', false)
            ->whereIn('distance_key', array_keys(self::CYCLING_DISTANCE_LABELS))
            ->whereHas('activity', function (Builder $query) use ($sportKey, $environment, $terrain): void {
                $this->sports->applyScope($query, $sportKey, $environment, $terrain);
            })
            ->orderByRaw('COALESCE(override_seconds, elapsed_seconds) ASC')
            ->orderBy('achieved_at')
            ->orderBy('id')
            ->get();

        /** @var array<string, BestEffort> $bestByKey */
        $bestByKey = [];

        foreach ($efforts as $effort) {
            if (! isset($bestByKey[$effort->distance_key])) {
                $bestByKey[$effort->distance_key] = $effort;
            }
        }

        $result = [];

        foreach (self::CYCLING_DISTANCE_LABELS as $key => $label) {
            $distanceMeters = BestEffortCalculator::ROAD_BIKING_DISTANCES[$key];
            $best = $bestByKey[$key] ?? null;

            if ($best === null) {
                $result[] = [
                    'key' => $key,
                    'label' => $label,
                    'distance_meters' => $distanceMeters,
                    'best' => null,
                ];

                continue;
            }

            $effectiveSeconds = $best->effectiveSeconds();

            $result[] = [
                'key' => $key,
                'label' => $label,
                'distance_meters' => $distanceMeters,
                'best' => [
                    'id' => $best->id,
                    'activity_id' => $best->activity_id,
                    'activity_name' => $best->activity->name,
                    'achieved_at' => $best->achieved_at->format('Y-m-d'),
                    'effective_seconds' => round($effectiveSeconds, 1),
                    'avg_speed' => $effectiveSeconds > 0.0
                        ? round($best->distance_meters / $effectiveSeconds, 3)
                        : 0.0,
                ],
            ];
        }

        return $result;
    }

    /**
     * Build the Climbing & Regions module for outdoor hubs with elevation gain > 0.
     *
     * @param  Builder<Activity>  $baseQuery
     * @return ?array{
     *     total_elevation_gain: float,
     *     total_elevation_loss: float,
     *     avg_climb_density_m_per_km: ?float,
     *     avg_vam: ?float,
     *     max_vam: ?float,
     *     regions: list<array{
     *         name: string,
     *         sessions: int,
     *         distance_meters: float,
     *         elevation_gain: float,
     *         avg_grit: ?float,
     *         pct: float
     *     }>,
     *     stamina: array{avg_begin: ?float, avg_end: ?float, avg_drop: ?float, count: int},
     *     feelRpe: array{avg_feel: ?float, avg_rpe: ?float, count: int}
     * }
     */
    private function buildClimbingModule(
        Builder $baseQuery,
        bool $indoorOnly,
        string $environment,
        int $totalSessions,
        float $totalElevationGain,
    ): ?array {
        if ($indoorOnly || $environment === 'indoor' || $totalElevationGain <= 0.0) {
            return null;
        }

        $row = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_loss, 0)), 0) as elevation_loss')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) > 0 THEN elevation_gain ELSE 0 END), 0) as vam_elevation')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) > 0 THEN COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) ELSE 0 END), 0) as vam_seconds')
            ->selectRaw('MAX(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) >= 600 THEN elevation_gain / (COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) / 3600.0) END) as max_vam_long')
            ->selectRaw('MAX(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) > 0 THEN elevation_gain / (COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) / 3600.0) END) as max_vam_any')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain IS NOT NULL AND distance_meters > 0 THEN elevation_gain ELSE 0 END), 0) as steepness_elevation')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain IS NOT NULL AND distance_meters > 0 THEN distance_meters ELSE 0 END), 0) as steepness_distance')
            ->selectRaw('AVG(begin_stamina) as avg_begin')
            ->selectRaw('AVG(end_stamina) as avg_end')
            ->selectRaw('AVG(CASE WHEN begin_stamina IS NOT NULL AND end_stamina IS NOT NULL THEN begin_stamina - end_stamina END) as avg_drop')
            ->selectRaw('SUM(CASE WHEN begin_stamina IS NOT NULL AND end_stamina IS NOT NULL THEN 1 ELSE 0 END) as stamina_count')
            ->selectRaw('AVG(workout_feel) as avg_feel')
            ->selectRaw('AVG(workout_rpe) as avg_rpe')
            ->selectRaw('SUM(CASE WHEN workout_feel IS NOT NULL OR workout_rpe IS NOT NULL THEN 1 ELSE 0 END) as feel_count')
            ->first();

        $vamElevation = $this->rowFloat($row, 'vam_elevation');
        $vamSeconds = $this->rowFloat($row, 'vam_seconds');
        $avgVam = ($vamElevation > 0.0 && $vamSeconds > 0.0)
            ? round($vamElevation / ($vamSeconds / 3600.0), 1)
            : null;
        $maxVam = $this->rowNullableFloat($row, 'max_vam_long', 1)
            ?? $this->rowNullableFloat($row, 'max_vam_any', 1);

        $steepnessElevation = $this->rowFloat($row, 'steepness_elevation');
        $steepnessDistance = $this->rowFloat($row, 'steepness_distance');
        $avgClimbDensity = $steepnessDistance > 0.0
            ? round($steepnessElevation / ($steepnessDistance / 1000.0), 1)
            : null;

        return [
            'total_elevation_gain' => round($totalElevationGain, 1),
            'total_elevation_loss' => round($this->rowFloat($row, 'elevation_loss'), 1),
            'avg_climb_density_m_per_km' => $avgClimbDensity,
            'avg_vam' => $avgVam,
            'max_vam' => $maxVam,
            'regions' => $this->buildRegions($baseQuery, $totalElevationGain, $totalSessions),
            'stamina' => [
                'avg_begin' => $this->rowNullableFloat($row, 'avg_begin', 1),
                'avg_end' => $this->rowNullableFloat($row, 'avg_end', 1),
                'avg_drop' => $this->rowNullableFloat($row, 'avg_drop', 1),
                'count' => $this->rowInt($row, 'stamina_count'),
            ],
            'feelRpe' => [
                'avg_feel' => $this->rowNullableFloat($row, 'avg_feel', 1),
                'avg_rpe' => $this->rowNullableFloat($row, 'avg_rpe', 1),
                'count' => $this->rowInt($row, 'feel_count'),
            ],
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     name: string,
     *     sessions: int,
     *     distance_meters: float,
     *     elevation_gain: float,
     *     avg_grit: ?float,
     *     pct: float
     * }>
     */
    private function buildRegions(Builder $baseQuery, float $totalElevationGain, int $totalSessions): array
    {
        $rows = (clone $baseQuery)
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(TRIM(location_name), ''), 'Unknown Location') as region_name")
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->groupByRaw("COALESCE(NULLIF(TRIM(location_name), ''), 'Unknown Location')")
            ->orderByDesc('elevation_gain')
            ->orderByDesc('sessions')
            ->orderBy('region_name')
            ->limit(10)
            ->get();

        $regions = [];

        foreach ($rows as $row) {
            $regionName = isset($row->region_name) && is_string($row->region_name)
                ? $row->region_name
                : 'Unknown Location';
            $sessions = $this->rowInt($row, 'sessions');
            $elevationGain = round($this->rowFloat($row, 'elevation_gain'), 1);

            $pct = $totalElevationGain > 0.0
                ? round(($elevationGain / $totalElevationGain) * 100.0, 1)
                : ($totalSessions > 0 ? round(($sessions / $totalSessions) * 100.0, 1) : 0.0);

            $regions[] = [
                'name' => $regionName,
                'sessions' => $sessions,
                'distance_meters' => round($this->rowFloat($row, 'distance_meters'), 1),
                'elevation_gain' => $elevationGain,
                'avg_grit' => $this->rowNullableFloat($row, 'avg_grit', 1),
                'pct' => $pct,
            ];
        }

        return $regions;
    }

    /**
     * Build the Off-Road Dynamics module whenever any activity in scope has grit, flow, jumps, or unpaved %.
     *
     * @param  Builder<Activity>  $baseQuery
     * @return ?array{
     *     avg_grit: ?float,
     *     avg_flow: ?float,
     *     avg_unpaved_pct: ?float,
     *     total_jumps: int,
     *     dynamicsTrend: list<array{
     *         date: string,
     *         activity_id: int,
     *         name: string,
     *         grit: ?float,
     *         avg_flow: ?float,
     *         jump_count: ?int,
     *         unpaved_pct: ?float,
     *         elevation_gain: ?float
     *     }>,
     *     hardestSessions: list<OffroadLeaderboardSession>,
     *     smoothestSessions: list<OffroadLeaderboardSession>,
     *     topJumpSessions: list<OffroadJumpSession>
     * }
     */
    private function buildOffroadModule(Builder $baseQuery): ?array
    {
        $hasOffroad = (clone $baseQuery)
            ->where(static function (Builder $query): void {
                $query->where('grit', '>', 0)
                    ->orWhere('avg_flow', '>', 0)
                    ->orWhere('jump_count', '>', 0)
                    ->orWhere('unpaved_pct', '>=', self::OFFROAD_MIN_UNPAVED_PCT);
            })
            ->exists();

        if (! $hasOffroad) {
            return null;
        }

        $row = (clone $baseQuery)
            ->toBase()
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->selectRaw('AVG(CASE WHEN avg_flow > 0 THEN avg_flow END) as avg_flow')
            ->selectRaw('AVG(CASE WHEN unpaved_pct IS NOT NULL THEN unpaved_pct END) as avg_unpaved_pct')
            ->selectRaw('COALESCE(SUM(COALESCE(jump_count, 0)), 0) as total_jumps')
            ->first();

        return [
            'avg_grit' => $this->rowNullableFloat($row, 'avg_grit', 1),
            'avg_flow' => $this->rowNullableFloat($row, 'avg_flow', 1),
            'avg_unpaved_pct' => $this->rowNullableFloat($row, 'avg_unpaved_pct', 1),
            'total_jumps' => $this->rowInt($row, 'total_jumps'),
            'dynamicsTrend' => $this->buildOffroadDynamicsTrend($baseQuery),
            'hardestSessions' => $this->buildOffroadLeaderboard(
                (clone $baseQuery)->where('grit', '>', 0)->orderByDesc('grit')->orderByDesc('started_at_local')->orderByDesc('id'),
            ),
            'smoothestSessions' => $this->buildOffroadLeaderboard(
                (clone $baseQuery)->where('avg_flow', '>', 0)->orderBy('avg_flow')->orderByDesc('started_at_local')->orderByDesc('id'),
            ),
            'topJumpSessions' => $this->buildOffroadJumpHighlights($baseQuery),
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     date: string,
     *     activity_id: int,
     *     name: string,
     *     grit: ?float,
     *     avg_flow: ?float,
     *     jump_count: ?int,
     *     unpaved_pct: ?float,
     *     elevation_gain: ?float
     * }>
     */
    private function buildOffroadDynamicsTrend(Builder $baseQuery): array
    {
        $sessions = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'grit',
                'avg_flow',
                'jump_count',
                'unpaved_pct',
                'elevation_gain',
            ])
            ->where(static function (Builder $query): void {
                $query->where('grit', '>', 0)
                    ->orWhere('avg_flow', '>', 0);
            })
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        $trend = [];

        foreach ($sessions as $session) {
            $trend[] = [
                'date' => $session->started_at_local->format('Y-m-d'),
                'activity_id' => $session->id,
                'name' => $session->name,
                'grit' => $session->grit !== null ? round($session->grit, 1) : null,
                'avg_flow' => $session->avg_flow !== null ? round($session->avg_flow, 1) : null,
                'jump_count' => $session->jump_count,
                'unpaved_pct' => $session->unpaved_pct !== null ? round($session->unpaved_pct, 1) : null,
                'elevation_gain' => $session->elevation_gain !== null ? round($session->elevation_gain, 1) : null,
            ];
        }

        return $trend;
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<OffroadLeaderboardSession>
     */
    private function buildOffroadLeaderboard(Builder $query): array
    {
        $sessions = $query
            ->select([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'grit',
                'avg_flow',
                'elevation_gain',
                'distance_meters',
            ])
            ->limit(5)
            ->get();

        $result = [];

        foreach ($sessions as $session) {
            $result[] = [
                'id' => $session->id,
                'name' => $session->name,
                'date' => $session->started_at_local->format('Y-m-d'),
                'location_name' => $session->location_name,
                'grit' => $session->grit !== null ? round($session->grit, 1) : null,
                'avg_flow' => $session->avg_flow !== null ? round($session->avg_flow, 1) : null,
                'elevation_gain' => $session->elevation_gain !== null ? round($session->elevation_gain, 1) : null,
                'distance_meters' => round($session->distance_meters, 1),
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<OffroadJumpSession>
     */
    private function buildOffroadJumpHighlights(Builder $baseQuery): array
    {
        $sessions = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'jump_count',
                'grit',
                'avg_flow',
                'elevation_gain',
                'distance_meters',
            ])
            ->where('jump_count', '>', 0)
            ->orderByDesc('jump_count')
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $result = [];

        foreach ($sessions as $session) {
            $result[] = [
                'id' => $session->id,
                'name' => $session->name,
                'date' => $session->started_at_local->format('Y-m-d'),
                'location_name' => $session->location_name,
                'jump_count' => $session->jump_count ?? 0,
                'grit' => $session->grit !== null ? round($session->grit, 1) : null,
                'avg_flow' => $session->avg_flow !== null ? round($session->avg_flow, 1) : null,
                'elevation_gain' => $session->elevation_gain !== null ? round($session->elevation_gain, 1) : null,
                'distance_meters' => round($session->distance_meters, 1),
            ];
        }

        return $result;
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
