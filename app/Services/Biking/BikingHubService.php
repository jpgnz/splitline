<?php

namespace App\Services\Biking;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Services\Garmin\BestEffortCalculator;
use App\Services\Goals\GoalProgressService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type DisciplineOverviewSummary array{
 *     ytd_rides: int,
 *     ytd_distance_meters: float,
 *     ytd_elevation_gain: float,
 *     ytd_duration_seconds: float,
 *     total_rides: int,
 *     total_distance_meters: float,
 *     total_elevation_gain: float,
 *     total_duration_seconds: float,
 *     latest_ftp: ?float,
 *     avg_grit: ?float,
 *     avg_flow: ?float
 * }
 * @phpstan-type ZoneAggregate array{zone: int, label: string, seconds: float, pct: float}
 * @phpstan-type MilestoneItem array{
 *     activity_id: int,
 *     name: string,
 *     date: string,
 *     value_formatted: string,
 *     meta: string
 * }
 * @phpstan-type MtbLeaderboardRide array{
 *     id: int,
 *     name: string,
 *     date: string,
 *     location_name: ?string,
 *     grit: ?float,
 *     avg_flow: ?float,
 *     elevation_gain: ?float,
 *     distance_meters: float
 * }
 * @phpstan-type MtbJumpRide array{
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
class BikingHubService
{
    public function __construct(
        private readonly GoalProgressService $goalProgressService = new GoalProgressService,
    ) {}

    private const int WEEKLY_WINDOW_WEEKS = 24;

    private const int FITNESS_SERIES_DAYS = 180;

    private const int FITNESS_WARMUP_DAYS = 42;

    private const float FASTEST_ROAD_SPEED_MIN_DISTANCE_METERS = 20000.0;

    /**
     * @var array<int, string>
     */
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

    /**
     * @var array<string, string>
     */
    private const array ROAD_BEST_EFFORT_LABELS = [
        '5k' => '5K',
        '10k' => '10K',
        '20k' => '20K',
        '40k' => '40K',
        '50k' => '50K',
        '80k' => '80K',
        '100k' => '100K',
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
     * @var array<int, string>
     */
    private const array POWER_ZONE_LABELS = [
        1 => 'Active Recovery',
        2 => 'Endurance',
        3 => 'Tempo',
        4 => 'Lactate Threshold',
        5 => 'VO2 Max',
        6 => 'Anaerobic Capacity',
        7 => 'Neuromuscular',
    ];

    /**
     * Build the Biking Overview payload (/biking).
     *
     * @return array{
     *     roadSummary: DisciplineOverviewSummary,
     *     mtbSummary: DisciplineOverviewSummary,
     *     weeklyVolume: list<array{
     *         week_start: string,
     *         label: string,
     *         road_km: float,
     *         mtb_km: float,
     *         other_km: float,
     *         total_elevation_m: float
     *     }>,
     *     unclassifiedCount: int,
     *     unclassifiedRides: list<array{
     *         id: int,
     *         name: string,
     *         started_at_local: string,
     *         location_name: ?string,
     *         activity_type_key: string,
     *         distance_meters: float,
     *         duration_seconds: float,
     *         elevation_gain: ?float,
     *         avg_speed: ?float,
     *         avg_power: ?float,
     *         grit: ?float,
     *         unpaved_pct: ?float
     *     }>,
     *     goals: list<array<string, mixed>>
     * }
     */
    public function overview(): array
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();

        $roadSummary = $this->buildDisciplineOverviewSummary(Activity::query()->roadBiking(), $today);
        $mtbSummary = $this->buildDisciplineOverviewSummary(Activity::query()->mountainBiking(), $today);
        $weeklyVolume = $this->buildOverviewWeeklyVolume($today);

        $unclassifiedBase = Activity::query()
            ->otherBiking()
            ->whereNull('discipline_override');

        $unclassifiedCount = (clone $unclassifiedBase)->count();

        $unclassifiedModels = (clone $unclassifiedBase)
            ->select([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'activity_type_key',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'avg_speed',
                'avg_power',
                'grit',
                'unpaved_pct',
            ])
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $unclassifiedRides = [];

        foreach ($unclassifiedModels as $ride) {
            $unclassifiedRides[] = [
                'id' => $ride->id,
                'name' => $ride->name,
                'started_at_local' => $ride->started_at_local->format('Y-m-d H:i:s'),
                'location_name' => $ride->location_name,
                'activity_type_key' => $ride->activity_type_key,
                'distance_meters' => $ride->distance_meters,
                'duration_seconds' => $ride->duration_seconds,
                'elevation_gain' => $ride->elevation_gain,
                'avg_speed' => $ride->avg_speed,
                'avg_power' => $ride->avg_power,
                'grit' => $ride->grit,
                'unpaved_pct' => $ride->unpaved_pct,
            ];
        }

        return [
            'roadSummary' => $roadSummary,
            'mtbSummary' => $mtbSummary,
            'weeklyVolume' => $weeklyVolume,
            'unclassifiedCount' => $unclassifiedCount,
            'unclassifiedRides' => $unclassifiedRides,
            'goals' => [
                ...$this->goalProgressService->activeGoals(sport: 'biking'),
                ...$this->goalProgressService->activeGoals(sport: 'road_biking'),
                ...$this->goalProgressService->activeGoals(sport: 'mtb_biking'),
            ],
        ];
    }

    /**
     * Build the Road Biking Hub payload (/biking/road).
     *
     * @return array{
     *     filters: array{environment: string},
     *     summary: array{
     *         ytd_distance_meters: float,
     *         ytd_rides: int,
     *         ytd_elevation_gain: float,
     *         ytd_duration_seconds: float,
     *         latest_ftp: ?float,
     *         weight_kg: ?float,
     *         w_per_kg: ?float,
     *         current_ctl: float,
     *         current_atl: float,
     *         current_tsb: float,
     *         avg_np: ?float,
     *         avg_if: ?float,
     *         avg_vi: ?float,
     *         avg_left_balance: ?float,
     *         latest_vo2_max: ?float
     *     },
     *     powerCurve: list<array{
     *         seconds: int,
     *         label: string,
     *         allTime: ?float,
     *         season: ?float,
     *         last90d: ?float,
     *         allTimeWkg: ?float,
     *         seasonWkg: ?float,
     *         last90dWkg: ?float
     *     }>,
     *     fitnessLoad: list<array{date: string, tss: float, ctl: float, atl: float, tsb: float}>,
     *     weeklyTss: list<array{week_start: string, label: string, tss: float, distance_km: float, rides: int}>,
     *     ftpTrend: list<array{date: string, ftp: ?float, max_20min_power: ?float}>,
     *     powerZones: list<ZoneAggregate>,
     *     hrZones: list<ZoneAggregate>,
     *     bestEfforts: list<array{
     *         key: string,
     *         label: string,
     *         distance_meters: float,
     *         best: ?array{
     *             id: int,
     *             activity_id: int,
     *             activity_name: string,
     *             achieved_at: string,
     *             effective_seconds: float,
     *             avg_speed_kmh: float
     *         }
     *     }>,
     *     milestones: array{
     *         longest_ride: ?MilestoneItem,
     *         biggest_climb: ?MilestoneItem,
     *         fastest_avg_speed: ?MilestoneItem,
     *         highest_np: ?MilestoneItem,
     *         highest_tss: ?MilestoneItem
     *     },
     *     recentRides: list<array{
     *         id: int,
     *         name: string,
     *         started_at_local: string,
     *         location_name: ?string,
     *         is_indoor: bool,
     *         distance_meters: float,
     *         duration_seconds: float,
     *         elevation_gain: ?float,
     *         avg_speed: ?float,
     *         avg_hr: ?int,
     *         avg_power: ?float,
     *         normalized_power: ?float,
     *         tss: ?float,
     *         intensity_factor: ?float,
     *         ftp: ?float,
     *         left_balance: ?float
     *     }>,
     *     goals: list<array<string, mixed>>
     * }
     */
    public function road(string $environment = 'all'): array
    {
        $normalizedEnvironment = in_array($environment, ['all', 'outdoor', 'indoor'], true)
            ? $environment
            : 'all';

        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $baseQuery = Activity::query()->roadBiking();

        if ($normalizedEnvironment === 'outdoor') {
            $baseQuery->where('is_indoor', false);
        } elseif ($normalizedEnvironment === 'indoor') {
            $baseQuery->where('is_indoor', true);
        }

        $latestWeight = WeightMeasurement::query()
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        $weightKg = $latestWeight !== null
            ? round($latestWeight->weight_grams / 1000, 2)
            : null;

        $fitnessLoad = $this->buildFitnessLoad($baseQuery, $today);
        $latestLoad = $fitnessLoad !== [] ? $fitnessLoad[count($fitnessLoad) - 1] : null;

        $latestFtp = $this->resolveLatestFtp($baseQuery);
        $wPerKg = ($latestFtp !== null && $weightKg !== null && $weightKg > 0.0)
            ? round($latestFtp / $weightKg, 2)
            : null;

        $ytdBounds = $this->yearBounds($today);
        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->first();

        $powerAvgRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('AVG(CASE WHEN normalized_power > 0 THEN normalized_power END) as avg_np')
            ->selectRaw('AVG(CASE WHEN intensity_factor > 0 THEN intensity_factor END) as avg_if')
            ->selectRaw('AVG(CASE WHEN normalized_power > 0 AND avg_power > 0 THEN normalized_power / avg_power END) as avg_vi')
            ->selectRaw('AVG(CASE WHEN left_balance > 0 THEN left_balance END) as avg_left_balance')
            ->first();

        $latestVo2Record = Vo2MaxRecord::query()
            ->where('sport', 'cycling')
            ->orderByDesc('calendar_date')
            ->orderByDesc('id')
            ->first();

        $latestVo2Max = $latestVo2Record !== null
            ? ($latestVo2Record->vo2_max_precise ?? $latestVo2Record->vo2_max)
            : null;

        return [
            'filters' => [
                'environment' => $normalizedEnvironment,
            ],
            'summary' => [
                'ytd_distance_meters' => round($this->rowFloat($ytdRow, 'distance_meters'), 1),
                'ytd_rides' => $this->rowInt($ytdRow, 'rides'),
                'ytd_elevation_gain' => round($this->rowFloat($ytdRow, 'elevation_gain'), 1),
                'ytd_duration_seconds' => round($this->rowFloat($ytdRow, 'duration_seconds'), 1),
                'latest_ftp' => $latestFtp,
                'weight_kg' => $weightKg,
                'w_per_kg' => $wPerKg,
                'current_ctl' => $latestLoad['ctl'] ?? 0.0,
                'current_atl' => $latestLoad['atl'] ?? 0.0,
                'current_tsb' => $latestLoad['tsb'] ?? 0.0,
                'avg_np' => $this->rowNullableFloat($powerAvgRow, 'avg_np', 1),
                'avg_if' => $this->rowNullableFloat($powerAvgRow, 'avg_if', 2),
                'avg_vi' => $this->rowNullableFloat($powerAvgRow, 'avg_vi', 2),
                'avg_left_balance' => $this->rowNullableFloat($powerAvgRow, 'avg_left_balance', 1),
                'latest_vo2_max' => $latestVo2Max !== null ? round($latestVo2Max, 1) : null,
            ],
            'powerCurve' => $this->buildPowerCurve($baseQuery, $today, $weightKg),
            'fitnessLoad' => $fitnessLoad,
            'weeklyTss' => $this->buildWeeklyTss($baseQuery, $today),
            'ftpTrend' => $this->buildFtpTrend($baseQuery),
            'powerZones' => $this->buildPowerZonesWithFallback($baseQuery, $today),
            'hrZones' => $this->buildHrZonesWithFallback($baseQuery, $today, true),
            'bestEfforts' => $this->buildRoadBestEfforts($normalizedEnvironment),
            'milestones' => $this->buildRoadMilestones($baseQuery),
            'recentRides' => $this->buildRecentRoadRides($baseQuery),
            'goals' => $this->goalProgressService->activeGoals(sport: 'road_biking'),
        ];
    }

    /**
     * Build the Mountain Biking Hub payload (/biking/mtb).
     *
     * @return array{
     *     summary: array{
     *         ytd_elevation_gain: float,
     *         ytd_elevation_loss: float,
     *         ytd_rides: int,
     *         ytd_distance_meters: float,
     *         ytd_duration_seconds: float,
     *         total_elevation_gain: float,
     *         total_elevation_loss: float,
     *         total_rides: int,
     *         total_distance_meters: float,
     *         avg_vam: ?float,
     *         steepness_density: ?float,
     *         avg_grit: ?float,
     *         avg_flow: ?float,
     *         total_jumps: int,
     *         avg_unpaved_pct: ?float
     *     },
     *     weeklyVertical: list<array{
     *         week_start: string,
     *         label: string,
     *         elevation_gain: float,
     *         elevation_loss: float,
     *         distance_km: float,
     *         rides: int
     *     }>,
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
     *     hardestRides: list<MtbLeaderboardRide>,
     *     smoothestRides: list<MtbLeaderboardRide>,
     *     jumpHighlights: list<MtbJumpRide>,
     *     trailRegions: list<array{
     *         name: string,
     *         rides: int,
     *         elevation_gain: float,
     *         distance_meters: float,
     *         avg_grit: ?float,
     *         pct: float
     *     }>,
     *     hrZones: list<ZoneAggregate>,
     *     staminaAndFeel: array{
     *         avg_begin_stamina: ?float,
     *         avg_end_stamina: ?float,
     *         avg_stamina_drain: ?float,
     *         avg_feel: ?float,
     *         avg_rpe: ?float,
     *         stamina_samples: int,
     *         feel_samples: int
     *     },
     *     milestones: array{
     *         biggest_climb: ?MilestoneItem,
     *         longest_ride: ?MilestoneItem,
     *         hardest_grit: ?MilestoneItem,
     *         smoothest_flow: ?MilestoneItem,
     *         most_jumps: ?MilestoneItem
     *     },
     *     recentRides: list<array{
     *         id: int,
     *         name: string,
     *         started_at_local: string,
     *         location_name: ?string,
     *         distance_meters: float,
     *         duration_seconds: float,
     *         elevation_gain: ?float,
     *         elevation_loss: ?float,
     *         avg_speed: ?float,
     *         avg_hr: ?int,
     *         grit: ?float,
     *         avg_flow: ?float,
     *         jump_count: ?int,
     *         unpaved_pct: ?float,
     *         training_load: ?float
     *     }>,
     *     goals: list<array<string, mixed>>
     * }
     */
    public function mtb(): array
    {
        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $baseQuery = Activity::query()->mountainBiking();
        $ytdBounds = $this->yearBounds($today);

        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_loss, 0)), 0) as elevation_loss')
            ->first();

        $totalRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_loss, 0)), 0) as elevation_loss')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) > 0 THEN elevation_gain ELSE 0 END), 0) as vam_elevation')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain > 0 AND COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) > 0 THEN COALESCE(NULLIF(moving_duration_seconds, 0), duration_seconds) ELSE 0 END), 0) as vam_seconds')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain IS NOT NULL AND distance_meters > 0 THEN elevation_gain ELSE 0 END), 0) as steepness_elevation')
            ->selectRaw('COALESCE(SUM(CASE WHEN elevation_gain IS NOT NULL AND distance_meters > 0 THEN distance_meters ELSE 0 END), 0) as steepness_distance')
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->selectRaw('AVG(CASE WHEN avg_flow > 0 THEN avg_flow END) as avg_flow')
            ->selectRaw('COALESCE(SUM(COALESCE(jump_count, 0)), 0) as total_jumps')
            ->selectRaw('AVG(CASE WHEN unpaved_pct IS NOT NULL THEN unpaved_pct END) as avg_unpaved_pct')
            ->first();

        $totalRides = $this->rowInt($totalRow, 'rides');
        $totalElevationGain = round($this->rowFloat($totalRow, 'elevation_gain'), 1);

        $vamElevation = $this->rowFloat($totalRow, 'vam_elevation');
        $vamSeconds = $this->rowFloat($totalRow, 'vam_seconds');
        $avgVam = ($vamElevation > 0.0 && $vamSeconds > 0.0)
            ? round($vamElevation / ($vamSeconds / 3600.0), 1)
            : null;

        $steepnessElevation = $this->rowFloat($totalRow, 'steepness_elevation');
        $steepnessDistance = $this->rowFloat($totalRow, 'steepness_distance');
        $steepnessDensity = $steepnessDistance > 0.0
            ? round($steepnessElevation / ($steepnessDistance / 1000.0), 1)
            : null;

        return [
            'summary' => [
                'ytd_elevation_gain' => round($this->rowFloat($ytdRow, 'elevation_gain'), 1),
                'ytd_elevation_loss' => round($this->rowFloat($ytdRow, 'elevation_loss'), 1),
                'ytd_rides' => $this->rowInt($ytdRow, 'rides'),
                'ytd_distance_meters' => round($this->rowFloat($ytdRow, 'distance_meters'), 1),
                'ytd_duration_seconds' => round($this->rowFloat($ytdRow, 'duration_seconds'), 1),
                'total_elevation_gain' => $totalElevationGain,
                'total_elevation_loss' => round($this->rowFloat($totalRow, 'elevation_loss'), 1),
                'total_rides' => $totalRides,
                'total_distance_meters' => round($this->rowFloat($totalRow, 'distance_meters'), 1),
                'avg_vam' => $avgVam,
                'steepness_density' => $steepnessDensity,
                'avg_grit' => $this->rowNullableFloat($totalRow, 'avg_grit', 1),
                'avg_flow' => $this->rowNullableFloat($totalRow, 'avg_flow', 1),
                'total_jumps' => $this->rowInt($totalRow, 'total_jumps'),
                'avg_unpaved_pct' => $this->rowNullableFloat($totalRow, 'avg_unpaved_pct', 1),
            ],
            'weeklyVertical' => $this->buildWeeklyVertical($baseQuery, $today),
            'dynamicsTrend' => $this->buildMtbDynamicsTrend($baseQuery),
            'hardestRides' => $this->buildMtbLeaderboard(
                (clone $baseQuery)->where('grit', '>', 0)->orderByDesc('grit')->orderByDesc('started_at_local')->orderByDesc('id'),
            ),
            'smoothestRides' => $this->buildMtbLeaderboard(
                (clone $baseQuery)->where('avg_flow', '>', 0)->orderBy('avg_flow')->orderByDesc('started_at_local')->orderByDesc('id'),
            ),
            'jumpHighlights' => $this->buildMtbJumpHighlights($baseQuery),
            'trailRegions' => $this->buildTrailRegions($baseQuery, $totalElevationGain, $totalRides),
            'hrZones' => $this->buildHrZonesWithFallback($baseQuery, $today, false),
            'staminaAndFeel' => $this->buildStaminaAndFeel($baseQuery),
            'milestones' => $this->buildMtbMilestones($baseQuery),
            'recentRides' => $this->buildRecentMtbRides($baseQuery),
            'goals' => $this->goalProgressService->activeGoals(sport: 'mtb_biking'),
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
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
     * @return DisciplineOverviewSummary
     */
    private function buildDisciplineOverviewSummary(Builder $baseQuery, CarbonImmutable $today): array
    {
        $ytdBounds = $this->yearBounds($today);

        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->first();

        $totalRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->selectRaw('AVG(CASE WHEN avg_flow > 0 THEN avg_flow END) as avg_flow')
            ->first();

        return [
            'ytd_rides' => $this->rowInt($ytdRow, 'rides'),
            'ytd_distance_meters' => round($this->rowFloat($ytdRow, 'distance_meters'), 1),
            'ytd_elevation_gain' => round($this->rowFloat($ytdRow, 'elevation_gain'), 1),
            'ytd_duration_seconds' => round($this->rowFloat($ytdRow, 'duration_seconds'), 1),
            'total_rides' => $this->rowInt($totalRow, 'rides'),
            'total_distance_meters' => round($this->rowFloat($totalRow, 'distance_meters'), 1),
            'total_elevation_gain' => round($this->rowFloat($totalRow, 'elevation_gain'), 1),
            'total_duration_seconds' => round($this->rowFloat($totalRow, 'duration_seconds'), 1),
            'latest_ftp' => $this->resolveLatestFtp($baseQuery),
            'avg_grit' => $this->rowNullableFloat($totalRow, 'avg_grit', 1),
            'avg_flow' => $this->rowNullableFloat($totalRow, 'avg_flow', 1),
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
     * @return list<array{
     *     week_start: string,
     *     label: string,
     *     road_km: float,
     *     mtb_km: float,
     *     other_km: float,
     *     total_elevation_m: float
     * }>
     */
    private function buildOverviewWeeklyVolume(CarbonImmutable $today): array
    {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $currentWeekStart->subWeeks(self::WEEKLY_WINDOW_WEEKS - 1);
        $lastWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY);

        /** @var array<string, array{week_start: string, label: string, road_km: float, mtb_km: float, other_km: float, total_elevation_m: float}> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_WINDOW_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $key = $weekStart->format('Y-m-d');
            $buckets[$key] = [
                'week_start' => $key,
                'label' => $weekStart->format('j M'),
                'road_km' => 0.0,
                'mtb_km' => 0.0,
                'other_km' => 0.0,
                'total_elevation_m' => 0.0,
            ];
        }

        $activities = Activity::query()
            ->biking()
            ->select([
                'started_at_local',
                'sport_discipline',
                'discipline_override',
                'distance_meters',
                'elevation_gain',
            ])
            ->whereBetween('started_at_local', [
                $firstWeekStart->format('Y-m-d').' 00:00:00',
                $lastWeekEnd->format('Y-m-d').' 23:59:59',
            ])
            ->get();

        foreach ($activities as $activity) {
            $weekKey = $activity->started_at_local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

            if (! isset($buckets[$weekKey])) {
                continue;
            }

            $distanceKm = $activity->distance_meters / 1000.0;
            $discipline = $activity->effectiveDiscipline();

            if ($discipline === 'road') {
                $buckets[$weekKey]['road_km'] += $distanceKm;
            } elseif ($discipline === 'mtb') {
                $buckets[$weekKey]['mtb_km'] += $distanceKm;
            } else {
                $buckets[$weekKey]['other_km'] += $distanceKm;
            }

            $buckets[$weekKey]['total_elevation_m'] += $activity->elevation_gain ?? 0.0;
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'road_km' => round($bucket['road_km'], 1),
                'mtb_km' => round($bucket['mtb_km'], 1),
                'other_km' => round($bucket['other_km'], 1),
                'total_elevation_m' => round($bucket['total_elevation_m'], 1),
            ];
        }

        return $result;
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
    private function buildFitnessLoad(Builder $baseQuery, CarbonImmutable $today): array
    {
        $outputStart = $today->subDays(self::FITNESS_SERIES_DAYS - 1);
        $warmupStart = $outputStart->subDays(self::FITNESS_WARMUP_DAYS);

        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'tss', 'training_load'])
            ->whereBetween('started_at_local', [
                $warmupStart->format('Y-m-d').' 00:00:00',
                $today->format('Y-m-d').' 23:59:59',
            ])
            ->get();

        /** @var array<string, float> $dailyTss */
        $dailyTss = [];

        foreach ($rides as $ride) {
            $load = $ride->tss ?? $ride->training_load ?? 0.0;

            if ($load <= 0.0) {
                continue;
            }

            $dateKey = $ride->started_at_local->format('Y-m-d');
            $dailyTss[$dateKey] = ($dailyTss[$dateKey] ?? 0.0) + $load;
        }

        $ctl = 0.0;
        $atl = 0.0;
        $series = [];
        $cursor = $warmupStart;

        while ($cursor->lessThanOrEqualTo($today)) {
            $dateStr = $cursor->format('Y-m-d');
            $tss = $dailyTss[$dateStr] ?? 0.0;
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
     * @return list<array{week_start: string, label: string, tss: float, distance_km: float, rides: int}>
     */
    private function buildWeeklyTss(Builder $baseQuery, CarbonImmutable $today): array
    {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $currentWeekStart->subWeeks(self::WEEKLY_WINDOW_WEEKS - 1);
        $lastWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY);

        /** @var array<string, array{week_start: string, label: string, tss: float, distance_km: float, rides: int}> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_WINDOW_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $key = $weekStart->format('Y-m-d');
            $buckets[$key] = [
                'week_start' => $key,
                'label' => $weekStart->format('j M'),
                'tss' => 0.0,
                'distance_km' => 0.0,
                'rides' => 0,
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
            $buckets[$weekKey]['distance_km'] += $ride->distance_meters / 1000.0;
            $buckets[$weekKey]['rides']++;
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'tss' => round($bucket['tss'], 1),
                'distance_km' => round($bucket['distance_km'], 1),
                'rides' => $bucket['rides'],
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{date: string, ftp: ?float, max_20min_power: ?float}>
     */
    private function buildFtpTrend(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'ftp', 'max_20min_power'])
            ->where(function (Builder $query): void {
                $query
                    ->where('ftp', '>', 0)
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
     * @param  Builder<Activity>  $baseQuery
     * @return list<ZoneAggregate>
     */
    private function buildHrZonesWithFallback(Builder $baseQuery, CarbonImmutable $today, bool $preferLast90Days): array
    {
        if ($preferLast90Days) {
            $last90dQuery = (clone $baseQuery)->whereBetween('started_at_local', [
                $today->subDays(89)->format('Y-m-d').' 00:00:00',
                $today->format('Y-m-d').' 23:59:59',
            ]);

            $zones = $this->aggregateHrZones($last90dQuery);

            if ($zones !== []) {
                return $zones;
            }
        }

        return $this->aggregateHrZones(clone $baseQuery);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<ZoneAggregate>
     */
    private function aggregateHrZones(Builder $query): array
    {
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

        $total = array_sum($secondsByZone);

        if ($total <= 0.0) {
            return [];
        }

        $result = [];

        foreach ($secondsByZone as $zone => $seconds) {
            $result[] = [
                'zone' => $zone,
                'label' => self::HR_ZONE_LABELS[$zone],
                'seconds' => round($seconds, 1),
                'pct' => round(($seconds / $total) * 100.0, 1),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     distance_meters: float,
     *     best: ?array{
     *         id: int,
     *         activity_id: int,
     *         activity_name: string,
     *         achieved_at: string,
     *         effective_seconds: float,
     *         avg_speed_kmh: float
     *     }
     * }>
     */
    private function buildRoadBestEfforts(string $environment): array
    {
        $effortsQuery = BestEffort::query()
            ->with('activity:id,name,is_indoor')
            ->where('sport', 'road_biking')
            ->where('is_excluded', false)
            ->whereIn('distance_key', array_keys(self::ROAD_BEST_EFFORT_LABELS))
            ->orderByRaw('COALESCE(override_seconds, elapsed_seconds) ASC')
            ->orderBy('achieved_at')
            ->orderBy('id');

        if ($environment === 'outdoor') {
            $effortsQuery->whereHas('activity', static function (Builder $query): void {
                $query->where('is_indoor', false);
            });
        } elseif ($environment === 'indoor') {
            $effortsQuery->whereHas('activity', static function (Builder $query): void {
                $query->where('is_indoor', true);
            });
        }

        /** @var array<string, BestEffort> $bestByKey */
        $bestByKey = [];

        foreach ($effortsQuery->get() as $effort) {
            if (! isset($bestByKey[$effort->distance_key])) {
                $bestByKey[$effort->distance_key] = $effort;
            }
        }

        $result = [];

        foreach (self::ROAD_BEST_EFFORT_LABELS as $key => $label) {
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
                    'avg_speed_kmh' => $effectiveSeconds > 0.0
                        ? round(($best->distance_meters / $effectiveSeconds) * 3.6, 1)
                        : 0.0,
                ],
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     longest_ride: ?MilestoneItem,
     *     biggest_climb: ?MilestoneItem,
     *     fastest_avg_speed: ?MilestoneItem,
     *     highest_np: ?MilestoneItem,
     *     highest_tss: ?MilestoneItem
     * }
     */
    private function buildRoadMilestones(Builder $baseQuery): array
    {
        $longest = (clone $baseQuery)
            ->where('distance_meters', '>', 0)
            ->orderByDesc('distance_meters')
            ->orderByDesc('started_at_local')
            ->first();

        $biggestClimb = (clone $baseQuery)
            ->where('elevation_gain', '>', 0)
            ->orderByDesc('elevation_gain')
            ->orderByDesc('started_at_local')
            ->first();

        $fastestAvgSpeed = (clone $baseQuery)
            ->where('distance_meters', '>=', self::FASTEST_ROAD_SPEED_MIN_DISTANCE_METERS)
            ->where('avg_speed', '>', 0)
            ->orderByDesc('avg_speed')
            ->orderByDesc('started_at_local')
            ->first();

        $highestNp = (clone $baseQuery)
            ->where('normalized_power', '>', 0)
            ->orderByDesc('normalized_power')
            ->orderByDesc('started_at_local')
            ->first();

        $highestTss = (clone $baseQuery)
            ->where('tss', '>', 0)
            ->orderByDesc('tss')
            ->orderByDesc('started_at_local')
            ->first();

        return [
            'longest_ride' => $longest !== null
                ? $this->formatMilestone(
                    $longest,
                    number_format($longest->distance_meters / 1000.0, 1).' km',
                    $this->formatDuration($longest->duration_seconds),
                )
                : null,
            'biggest_climb' => ($biggestClimb !== null && $biggestClimb->elevation_gain !== null)
                ? $this->formatMilestone(
                    $biggestClimb,
                    number_format($biggestClimb->elevation_gain, 0).' m',
                    number_format($biggestClimb->distance_meters / 1000.0, 1).' km',
                )
                : null,
            'fastest_avg_speed' => ($fastestAvgSpeed !== null && $fastestAvgSpeed->avg_speed !== null)
                ? $this->formatMilestone(
                    $fastestAvgSpeed,
                    number_format($fastestAvgSpeed->avg_speed * 3.6, 1).' km/h',
                    number_format($fastestAvgSpeed->distance_meters / 1000.0, 1).' km',
                )
                : null,
            'highest_np' => ($highestNp !== null && $highestNp->normalized_power !== null)
                ? $this->formatMilestone(
                    $highestNp,
                    number_format($highestNp->normalized_power, 0).' W',
                    $this->formatDuration($highestNp->duration_seconds),
                )
                : null,
            'highest_tss' => ($highestTss !== null && $highestTss->tss !== null)
                ? $this->formatMilestone(
                    $highestTss,
                    number_format($highestTss->tss, 0).' TSS',
                    number_format($highestTss->distance_meters / 1000.0, 1).' km · '.$this->formatDuration($highestTss->duration_seconds),
                )
                : null,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     id: int,
     *     name: string,
     *     started_at_local: string,
     *     location_name: ?string,
     *     is_indoor: bool,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     elevation_gain: ?float,
     *     avg_speed: ?float,
     *     avg_hr: ?int,
     *     avg_power: ?float,
     *     normalized_power: ?float,
     *     tss: ?float,
     *     intensity_factor: ?float,
     *     ftp: ?float,
     *     left_balance: ?float
     * }>
     */
    private function buildRecentRoadRides(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'is_indoor',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'avg_speed',
                'avg_hr',
                'avg_power',
                'normalized_power',
                'tss',
                'intensity_factor',
                'ftp',
                'left_balance',
            ])
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $result = [];

        foreach ($rides as $ride) {
            $result[] = [
                'id' => $ride->id,
                'name' => $ride->name,
                'started_at_local' => $ride->started_at_local->format('Y-m-d H:i:s'),
                'location_name' => $ride->location_name,
                'is_indoor' => $ride->is_indoor,
                'distance_meters' => $ride->distance_meters,
                'duration_seconds' => $ride->duration_seconds,
                'elevation_gain' => $ride->elevation_gain,
                'avg_speed' => $ride->avg_speed,
                'avg_hr' => $ride->avg_hr,
                'avg_power' => $ride->avg_power,
                'normalized_power' => $ride->normalized_power,
                'tss' => $ride->tss,
                'intensity_factor' => $ride->intensity_factor,
                'ftp' => $ride->ftp,
                'left_balance' => $ride->left_balance,
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     week_start: string,
     *     label: string,
     *     elevation_gain: float,
     *     elevation_loss: float,
     *     distance_km: float,
     *     rides: int
     * }>
     */
    private function buildWeeklyVertical(Builder $baseQuery, CarbonImmutable $today): array
    {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $currentWeekStart->subWeeks(self::WEEKLY_WINDOW_WEEKS - 1);
        $lastWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY);

        /** @var array<string, array{week_start: string, label: string, elevation_gain: float, elevation_loss: float, distance_km: float, rides: int}> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_WINDOW_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $key = $weekStart->format('Y-m-d');
            $buckets[$key] = [
                'week_start' => $key,
                'label' => $weekStart->format('j M'),
                'elevation_gain' => 0.0,
                'elevation_loss' => 0.0,
                'distance_km' => 0.0,
                'rides' => 0,
            ];
        }

        $rides = (clone $baseQuery)
            ->select(['started_at_local', 'elevation_gain', 'elevation_loss', 'distance_meters'])
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

            $buckets[$weekKey]['elevation_gain'] += $ride->elevation_gain ?? 0.0;
            $buckets[$weekKey]['elevation_loss'] += $ride->elevation_loss ?? 0.0;
            $buckets[$weekKey]['distance_km'] += $ride->distance_meters / 1000.0;
            $buckets[$weekKey]['rides']++;
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'elevation_loss' => round($bucket['elevation_loss'], 1),
                'distance_km' => round($bucket['distance_km'], 1),
                'rides' => $bucket['rides'],
            ];
        }

        return $result;
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
    private function buildMtbDynamicsTrend(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
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
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('grit')
                    ->orWhereNotNull('avg_flow');
            })
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        $trend = [];

        foreach ($rides as $ride) {
            $trend[] = [
                'date' => $ride->started_at_local->format('Y-m-d'),
                'activity_id' => $ride->id,
                'name' => $ride->name,
                'grit' => $ride->grit !== null ? round($ride->grit, 1) : null,
                'avg_flow' => $ride->avg_flow !== null ? round($ride->avg_flow, 1) : null,
                'jump_count' => $ride->jump_count,
                'unpaved_pct' => $ride->unpaved_pct !== null ? round($ride->unpaved_pct, 1) : null,
                'elevation_gain' => $ride->elevation_gain !== null ? round($ride->elevation_gain, 1) : null,
            ];
        }

        return $trend;
    }

    /**
     * @param  Builder<Activity>  $query
     * @return list<MtbLeaderboardRide>
     */
    private function buildMtbLeaderboard(Builder $query): array
    {
        $rides = $query
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

        foreach ($rides as $ride) {
            $result[] = [
                'id' => $ride->id,
                'name' => $ride->name,
                'date' => $ride->started_at_local->format('Y-m-d'),
                'location_name' => $ride->location_name,
                'grit' => $ride->grit !== null ? round($ride->grit, 1) : null,
                'avg_flow' => $ride->avg_flow !== null ? round($ride->avg_flow, 1) : null,
                'elevation_gain' => $ride->elevation_gain !== null ? round($ride->elevation_gain, 1) : null,
                'distance_meters' => round($ride->distance_meters, 1),
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<MtbJumpRide>
     */
    private function buildMtbJumpHighlights(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
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

        foreach ($rides as $ride) {
            $result[] = [
                'id' => $ride->id,
                'name' => $ride->name,
                'date' => $ride->started_at_local->format('Y-m-d'),
                'location_name' => $ride->location_name,
                'jump_count' => $ride->jump_count ?? 0,
                'grit' => $ride->grit !== null ? round($ride->grit, 1) : null,
                'avg_flow' => $ride->avg_flow !== null ? round($ride->avg_flow, 1) : null,
                'elevation_gain' => $ride->elevation_gain !== null ? round($ride->elevation_gain, 1) : null,
                'distance_meters' => round($ride->distance_meters, 1),
            ];
        }

        return $result;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     name: string,
     *     rides: int,
     *     elevation_gain: float,
     *     distance_meters: float,
     *     avg_grit: ?float,
     *     pct: float
     * }>
     */
    private function buildTrailRegions(Builder $baseQuery, float $totalElevationGain, int $totalRides): array
    {
        $rows = (clone $baseQuery)
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(TRIM(location_name), ''), 'Unknown Location') as region_name")
            ->selectRaw('COUNT(*) as rides')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->groupByRaw("COALESCE(NULLIF(TRIM(location_name), ''), 'Unknown Location')")
            ->orderByDesc('elevation_gain')
            ->orderByDesc('rides')
            ->orderBy('region_name')
            ->limit(10)
            ->get();

        $regions = [];

        foreach ($rows as $row) {
            $regionName = isset($row->region_name) && is_string($row->region_name)
                ? $row->region_name
                : 'Unknown Location';
            $rides = $this->rowInt($row, 'rides');
            $elevationGain = round($this->rowFloat($row, 'elevation_gain'), 1);

            $pct = $totalElevationGain > 0.0
                ? round(($elevationGain / $totalElevationGain) * 100.0, 1)
                : ($totalRides > 0 ? round(($rides / $totalRides) * 100.0, 1) : 0.0);

            $regions[] = [
                'name' => $regionName,
                'rides' => $rides,
                'elevation_gain' => $elevationGain,
                'distance_meters' => round($this->rowFloat($row, 'distance_meters'), 1),
                'avg_grit' => $this->rowNullableFloat($row, 'avg_grit', 1),
                'pct' => $pct,
            ];
        }

        return $regions;
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     avg_begin_stamina: ?float,
     *     avg_end_stamina: ?float,
     *     avg_stamina_drain: ?float,
     *     avg_feel: ?float,
     *     avg_rpe: ?float,
     *     stamina_samples: int,
     *     feel_samples: int
     * }
     */
    private function buildStaminaAndFeel(Builder $baseQuery): array
    {
        $row = (clone $baseQuery)
            ->toBase()
            ->selectRaw('AVG(begin_stamina) as avg_begin_stamina')
            ->selectRaw('AVG(end_stamina) as avg_end_stamina')
            ->selectRaw('AVG(CASE WHEN begin_stamina IS NOT NULL AND end_stamina IS NOT NULL THEN begin_stamina - end_stamina END) as avg_stamina_drain')
            ->selectRaw('SUM(CASE WHEN begin_stamina IS NOT NULL AND end_stamina IS NOT NULL THEN 1 ELSE 0 END) as stamina_samples')
            ->selectRaw('AVG(workout_feel) as avg_feel')
            ->selectRaw('AVG(workout_rpe) as avg_rpe')
            ->selectRaw('SUM(CASE WHEN workout_feel IS NOT NULL OR workout_rpe IS NOT NULL THEN 1 ELSE 0 END) as feel_samples')
            ->first();

        return [
            'avg_begin_stamina' => $this->rowNullableFloat($row, 'avg_begin_stamina', 1),
            'avg_end_stamina' => $this->rowNullableFloat($row, 'avg_end_stamina', 1),
            'avg_stamina_drain' => $this->rowNullableFloat($row, 'avg_stamina_drain', 1),
            'avg_feel' => $this->rowNullableFloat($row, 'avg_feel', 1),
            'avg_rpe' => $this->rowNullableFloat($row, 'avg_rpe', 1),
            'stamina_samples' => $this->rowInt($row, 'stamina_samples'),
            'feel_samples' => $this->rowInt($row, 'feel_samples'),
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return array{
     *     biggest_climb: ?MilestoneItem,
     *     longest_ride: ?MilestoneItem,
     *     hardest_grit: ?MilestoneItem,
     *     smoothest_flow: ?MilestoneItem,
     *     most_jumps: ?MilestoneItem
     * }
     */
    private function buildMtbMilestones(Builder $baseQuery): array
    {
        $biggestClimb = (clone $baseQuery)
            ->where('elevation_gain', '>', 0)
            ->orderByDesc('elevation_gain')
            ->orderByDesc('started_at_local')
            ->first();

        $longest = (clone $baseQuery)
            ->where('distance_meters', '>', 0)
            ->orderByDesc('distance_meters')
            ->orderByDesc('started_at_local')
            ->first();

        $hardestGrit = (clone $baseQuery)
            ->where('grit', '>', 0)
            ->orderByDesc('grit')
            ->orderByDesc('started_at_local')
            ->first();

        $smoothestFlow = (clone $baseQuery)
            ->where('avg_flow', '>', 0)
            ->orderBy('avg_flow')
            ->orderByDesc('started_at_local')
            ->first();

        $mostJumps = (clone $baseQuery)
            ->where('jump_count', '>', 0)
            ->orderByDesc('jump_count')
            ->orderByDesc('started_at_local')
            ->first();

        return [
            'biggest_climb' => ($biggestClimb !== null && $biggestClimb->elevation_gain !== null)
                ? $this->formatMilestone(
                    $biggestClimb,
                    number_format($biggestClimb->elevation_gain, 0).' m',
                    number_format($biggestClimb->distance_meters / 1000.0, 1).' km',
                )
                : null,
            'longest_ride' => $longest !== null
                ? $this->formatMilestone(
                    $longest,
                    number_format($longest->distance_meters / 1000.0, 1).' km',
                    $this->formatDuration($longest->duration_seconds),
                )
                : null,
            'hardest_grit' => ($hardestGrit !== null && $hardestGrit->grit !== null)
                ? $this->formatMilestone(
                    $hardestGrit,
                    number_format($hardestGrit->grit, 1).' Grit',
                    $hardestGrit->location_name ?? number_format($hardestGrit->distance_meters / 1000.0, 1).' km',
                )
                : null,
            'smoothest_flow' => ($smoothestFlow !== null && $smoothestFlow->avg_flow !== null)
                ? $this->formatMilestone(
                    $smoothestFlow,
                    number_format($smoothestFlow->avg_flow, 1).' Flow',
                    $smoothestFlow->location_name ?? number_format($smoothestFlow->distance_meters / 1000.0, 1).' km',
                )
                : null,
            'most_jumps' => ($mostJumps !== null && $mostJumps->jump_count !== null)
                ? $this->formatMilestone(
                    $mostJumps,
                    number_format($mostJumps->jump_count, 0).($mostJumps->jump_count === 1 ? ' jump' : ' jumps'),
                    $mostJumps->location_name ?? number_format($mostJumps->distance_meters / 1000.0, 1).' km',
                )
                : null,
        ];
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<array{
     *     id: int,
     *     name: string,
     *     started_at_local: string,
     *     location_name: ?string,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     elevation_gain: ?float,
     *     elevation_loss: ?float,
     *     avg_speed: ?float,
     *     avg_hr: ?int,
     *     grit: ?float,
     *     avg_flow: ?float,
     *     jump_count: ?int,
     *     unpaved_pct: ?float,
     *     training_load: ?float
     * }>
     */
    private function buildRecentMtbRides(Builder $baseQuery): array
    {
        $rides = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'location_name',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'elevation_loss',
                'avg_speed',
                'avg_hr',
                'grit',
                'avg_flow',
                'jump_count',
                'unpaved_pct',
                'training_load',
            ])
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $result = [];

        foreach ($rides as $ride) {
            $result[] = [
                'id' => $ride->id,
                'name' => $ride->name,
                'started_at_local' => $ride->started_at_local->format('Y-m-d H:i:s'),
                'location_name' => $ride->location_name,
                'distance_meters' => $ride->distance_meters,
                'duration_seconds' => $ride->duration_seconds,
                'elevation_gain' => $ride->elevation_gain,
                'elevation_loss' => $ride->elevation_loss,
                'avg_speed' => $ride->avg_speed,
                'avg_hr' => $ride->avg_hr,
                'grit' => $ride->grit,
                'avg_flow' => $ride->avg_flow,
                'jump_count' => $ride->jump_count,
                'unpaved_pct' => $ride->unpaved_pct,
                'training_load' => $ride->training_load,
            ];
        }

        return $result;
    }

    /**
     * @return MilestoneItem
     */
    private function formatMilestone(Activity $activity, string $valueFormatted, string $meta): array
    {
        return [
            'activity_id' => $activity->id,
            'name' => $activity->name,
            'date' => $activity->started_at_local->format('Y-m-d'),
            'value_formatted' => $valueFormatted,
            'meta' => $meta,
        ];
    }

    private function formatDuration(float $seconds): string
    {
        $totalMinutes = (int) round($seconds / 60.0);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
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
