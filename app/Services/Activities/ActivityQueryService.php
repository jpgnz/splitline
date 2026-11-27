<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Models\HeartRateZone;
use App\Services\Gear\GearService;
use App\Services\Routes\MatchedRouteService;
use App\Services\Segments\SegmentQueryService;
use App\Support\DateWindowResolver;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class ActivityQueryService
{
    public function __construct(
        private readonly GearService $gearService = new GearService,
        private readonly ActivityEffortService $effortService = new ActivityEffortService,
        private readonly PaceDistributionService $paceDistributionService = new PaceDistributionService,
        private readonly ?SportRegistry $sports = null,
        private readonly ?DateWindowResolver $dateWindows = null,
        private readonly ?MatchedRouteService $matchedRoutes = null,
        private readonly ?SegmentQueryService $segments = null,
        private readonly ?HealthSourceRegistry $healthSources = null,
        private readonly ActivityDerivedMetricsService $derivedMetricsService = new ActivityDerivedMetricsService,
    ) {}

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    private function matchedRouteService(): MatchedRouteService
    {
        return $this->matchedRoutes ?? app(MatchedRouteService::class);
    }

    private function segmentQueryService(): SegmentQueryService
    {
        return $this->segments ?? app(SegmentQueryService::class);
    }

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    private function dateWindowResolver(): DateWindowResolver
    {
        return $this->dateWindows ?? app(DateWindowResolver::class);
    }

    private const int PER_PAGE = 25;

    /**
     * @var list<string>
     */
    private const array VALID_SPORTS = [
        'all',
        'running',
        'road_running',
        'trail_running',
        'track_running',
        'treadmill_running',
        'cycling',
        'road_cycling',
        'gravel_cycling',
        'mtb_cycling',
        'indoor_cycling',
        'ebike_cycling',
        'commute_cycling',
        'biking',
        'road',
        'mtb',
        'biking_other',
        'walking',
        'hiking',
        'swimming',
        'cardio',
        'other',
    ];

    /**
     * @var list<string>
     */
    private const array VALID_RANGES = ['30d', '12w', '6m', '1y', 'this_year', 'last_year', 'all', 'custom'];

    /**
     * @var list<string>
     */
    private const array VALID_VIEWS = ['table', 'calendar'];

    /**
     * @var list<string>
     */
    private const array VALID_SORTS = ['date', 'distance', 'duration', 'elevation', 'hr', 'load'];

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
     * @var array<int, string>
     */
    private const array RUNNING_POWER_ZONE_LABELS = [
        1 => 'Easy',
        2 => 'Moderate',
        3 => 'Threshold',
        4 => 'Interval',
        5 => 'Repetition',
    ];

    /**
     * @var array<string, string>
     */
    private const array DISTANCE_LABELS = [
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
        '40k' => '40K',
        'marathon' => 'Marathon',
        '50k' => '50K',
        '80k' => '80K',
        '100k' => '100K',
    ];

    /**
     * Query paginated activities, aggregate summary stats, and training calendar grid.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     summary: array{
     *         count: int,
     *         total_distance_meters: float,
     *         total_duration_seconds: float,
     *         total_elevation_gain: float,
     *         total_calories: int,
     *         avg_hr: ?float,
     *         total_load: float
     *     },
     *     activities: array<string, mixed>,
     *     calendar: array{
     *         month: string,
     *         monthLabel: string,
     *         prevMonth: string,
     *         nextMonth: string,
     *         weeks: list<array{
     *             weekNumber: int,
     *             isFuture: bool,
     *             isCurrent: bool,
     *             days: list<array{
     *                 date: string,
     *                 dayOfMonth: int,
     *                 inMonth: bool,
     *                 isToday: bool,
     *                 activities: list<array{
     *                     id: int,
     *                     name: string,
     *                     sport_category: string,
     *                     effective_discipline: ?string,
     *                     distance_meters: float,
     *                     duration_seconds: float,
     *                     elevation_gain: ?float,
     *                     training_load: ?float
     *                 }>,
     *                 total_distance_meters: float,
     *                 total_duration_seconds: float
     *             }>,
     *             totals: array{
     *                 count: int,
     *                 distance_meters: float,
     *                 duration_seconds: float,
     *                 elevation_gain: float,
     *                 load: float
     *             }
     *         }>
     *     },
     *     filters: array{
     *         search: string,
     *         sport: string,
     *         range: string,
     *         from: ?string,
     *         to: ?string,
     *         pr: bool,
     *         favorite: bool,
     *         view: string,
     *         month: string,
     *         sort: string,
     *         dir: string
     *     }
     * }
     */
    public function list(array $filters = []): array
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $search = $this->normalizeSearch($filters['search'] ?? null);
        $sport = $this->normalizeChoice($filters['sport'] ?? null, self::VALID_SPORTS, 'all');
        $rawRange = is_string($filters['range'] ?? null) ? $filters['range'] : null;
        $rawEnd = is_string($filters['end'] ?? null) ? $filters['end'] : null;
        $rawFrom = is_string($filters['from'] ?? null) ? $filters['from'] : null;
        $rawTo = is_string($filters['to'] ?? null) ? $filters['to'] : null;

        $dateWindow = $this->dateWindowResolver()->resolveDayWindow(
            $rawRange,
            $rawEnd,
            $rawFrom,
            $rawTo,
            $today,
            self::VALID_RANGES,
            'all',
        );
        $range = $dateWindow['range'];
        $from = $dateWindow['from'];
        $to = $dateWindow['to'];

        $pr = $this->normalizeBool($filters['pr'] ?? false);
        $favorite = $this->normalizeBool($filters['favorite'] ?? false);
        $view = $this->normalizeChoice($filters['view'] ?? null, self::VALID_VIEWS, 'table');
        $sort = $this->normalizeChoice($filters['sort'] ?? null, self::VALID_SORTS, 'date');
        $dir = ($filters['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        $filteredQuery = Activity::query();
        $this->applyFacetFilters($filteredQuery, $search, $sport, $pr, $favorite);
        $this->applyDateWindowFilter($filteredQuery, $dateWindow);

        $summary = $this->buildSummary($filteredQuery);

        $listQuery = (clone $filteredQuery)->withExists('stream');
        $this->applySorting($listQuery, $sort, $dir);

        $paginator = $listQuery
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Activity $activity): array => $this->mapListItem($activity));

        $resolvedMonth = $this->resolveMonth($filters['month'] ?? null, $filteredQuery, $today, $timezone);
        $calendar = $this->buildCalendar($resolvedMonth, $today, $search, $sport, $pr, $favorite);

        return [
            'summary' => $summary,
            'activities' => $paginator->toArray(),
            'calendar' => $calendar,
            'dateWindow' => $dateWindow,
            'available_sports' => $this->sportRegistry()->filterableSports(),
            'filters' => [
                'search' => $search ?? '',
                'sport' => $sport,
                'range' => $range,
                'from' => $range === 'custom' ? $from : null,
                'to' => $range === 'custom' ? $to : null,
                'pr' => $pr,
                'favorite' => $favorite,
                'view' => $view,
                'month' => $resolvedMonth->format('Y-m'),
                'sort' => $sort,
                'dir' => $dir,
            ],
        ];
    }

    /**
     * Build the full activity detail payload including zones, streams, best efforts, effort context, pace distribution, and adjacent activities.
     *
     * @return array{
     *     activity: array<string, mixed>,
     *     hrZones: list<array{zone: int, label: string, min_hr: ?int, max_hr: ?int, seconds: float, pct: float}>,
     *     powerZones: list<array{zone: int, label: string, min_watts: ?int, max_watts: ?int, seconds: float, pct: float}>,
     *     paceDistribution: ?array<string, mixed>,
     *     effortContext: array<string, mixed>,
     *     stream: ?array{
     *         series: array<int|string, mixed>,
     *         polyline: array<int|string, mixed>,
     *         laps: array<int|string, mixed>,
     *         km_splits: array<int|string, mixed>,
     *         run_walk_segments: array<int|string, mixed>,
     *         jumps: array<int|string, mixed>
     *     },
     *     weather: ?array<string, mixed>,
     *     derivedMetrics: ?array<string, mixed>,
     *     bestEfforts: list<array<string, mixed>>,
     *     prevActivity: ?array{id: int, name: string},
     *     nextActivity: ?array{id: int, name: string},
     *     gear: array<string, mixed>,
     *     matchedRoute: ?array<string, mixed>,
     *     segmentEfforts: list<array<string, mixed>>,
     *     discipline_options: list<array{value: string, label: string}>
     * }
     */
    public function detail(Activity $activity): array
    {
        $activity->load(['stream', 'weather', 'derivedMetrics', 'bestEfforts', 'routeFingerprint.cluster.representativeActivity']);

        $startedAtLocal = $activity->started_at_local->format('Y-m-d H:i:s');

        $prev = Activity::query()
            ->select(['id', 'name'])
            ->where('started_at_local', '<', $startedAtLocal)
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->first();

        $next = Activity::query()
            ->select(['id', 'name'])
            ->where('started_at_local', '>', $startedAtLocal)
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->first();

        $sortedEfforts = $activity->bestEfforts
            ->sortBy([
                ['distance_meters', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $allTimeRanks = $this->resolveBestEffortRanks(array_values($sortedEfforts->all()));
        $bestEfforts = [];

        foreach ($sortedEfforts as $effort) {
            $bestEfforts[] = $this->mapBestEffort($effort, $allTimeRanks[$effort->id] ?? null);
        }

        $stream = $activity->stream;
        $weather = $activity->weather;
        $derivedMetrics = $activity->derivedMetrics;
        $zoneConfig = $this->resolveHrZoneConfig($activity);
        $series = is_array($stream?->series) ? array_values($stream->series) : [];

        return [
            'activity' => [
                'id' => $activity->id,
                'provider' => $activity->provider,
                'external_id' => $activity->external_id,
                'garmin_activity_id' => $activity->garmin_activity_id,
                'name' => $activity->name,
                'garmin_name' => $activity->garmin_name,
                'name_override' => $activity->name_override,
                'activity_type_key' => $activity->activity_type_key,
                'sport_category' => $activity->sport_category,
                'garmin_sport_category' => $activity->garmin_sport_category,
                'sport_category_override' => $activity->sport_category_override,
                'sport_discipline' => $activity->sport_discipline,
                'biking_discipline' => $activity->sport_discipline,
                'discipline_override' => $activity->discipline_override,
                'effective_discipline' => $activity->effectiveDiscipline(),
                'is_indoor' => $activity->is_indoor,
                'started_at_local' => $startedAtLocal,
                'started_at_utc' => $activity->started_at_utc->toIso8601String(),
                'location_name' => $activity->location_name,
                'start_lat' => $activity->start_lat,
                'start_lng' => $activity->start_lng,
                'distance_meters' => $activity->distance_meters,
                'duration_seconds' => $activity->duration_seconds,
                'moving_duration_seconds' => $activity->moving_duration_seconds,
                'elapsed_duration_seconds' => $activity->elapsed_duration_seconds,
                'elevation_gain' => $activity->elevation_gain,
                'elevation_loss' => $activity->elevation_loss,
                'min_elevation' => $activity->min_elevation,
                'max_elevation' => $activity->max_elevation,
                'avg_speed' => $activity->avg_speed,
                'max_speed' => $activity->max_speed,
                'avg_grade_adjusted_speed' => $activity->avg_grade_adjusted_speed,
                'calories' => $activity->calories,
                'bmr_calories' => $activity->bmr_calories,
                'sweat_loss_ml' => $activity->sweat_loss_ml,
                'avg_hr' => $activity->avg_hr,
                'max_hr' => $activity->max_hr,
                'hr_max_configured' => $zoneConfig?->max_hr,
                'avg_cadence' => $activity->avg_cadence,
                'max_cadence' => $activity->max_cadence,
                'avg_stride_length' => $activity->avg_stride_length,
                'avg_vertical_oscillation' => $activity->avg_vertical_oscillation,
                'avg_vertical_ratio' => $activity->avg_vertical_ratio,
                'avg_ground_contact_time' => $activity->avg_ground_contact_time,
                'steps' => $activity->steps,
                'aerobic_te' => $activity->aerobic_te,
                'anaerobic_te' => $activity->anaerobic_te,
                'training_load' => $activity->training_load,
                'training_effect_label' => $activity->training_effect_label,
                'aerobic_te_message' => $activity->aerobic_te_message,
                'anaerobic_te_message' => $activity->anaerobic_te_message,
                'body_battery_diff' => $activity->body_battery_diff,
                'begin_body_battery' => $activity->begin_body_battery,
                'end_body_battery' => $activity->end_body_battery,
                'avg_power' => $activity->avg_power,
                'max_power' => $activity->max_power,
                'normalized_power' => $activity->normalized_power,
                'ftp' => $activity->ftp,
                'threshold_power' => $activity->threshold_power,
                'tss' => $activity->tss,
                'intensity_factor' => $activity->intensity_factor,
                'total_work' => $activity->total_work,
                'left_balance' => $activity->left_balance,
                'avg_left_torque_effectiveness' => $activity->avg_left_torque_effectiveness,
                'avg_right_torque_effectiveness' => $activity->avg_right_torque_effectiveness,
                'avg_left_pedal_smoothness' => $activity->avg_left_pedal_smoothness,
                'avg_right_pedal_smoothness' => $activity->avg_right_pedal_smoothness,
                'max_20min_power' => $activity->max_20min_power,
                'power_curve' => $activity->power_curve,
                'wind_enabled' => $activity->wind_enabled,
                'grit' => $activity->grit,
                'avg_flow' => $activity->avg_flow,
                'jump_count' => $activity->jump_count,
                'unpaved_pct' => $activity->unpaved_pct,
                'workout_feel' => $activity->workout_feel,
                'workout_rpe' => $activity->workout_rpe,
                'execution_score' => $activity->execution_score,
                'workout_meta' => $activity->workout_meta,
                'begin_stamina' => $activity->begin_stamina,
                'end_stamina' => $activity->end_stamina,
                'min_stamina' => $activity->min_stamina,
                'avg_respiration_rate' => $activity->avg_respiration_rate,
                'min_respiration_rate' => $activity->min_respiration_rate,
                'max_respiration_rate' => $activity->max_respiration_rate,
                'avg_temperature' => $activity->avg_temperature,
                'min_temperature' => $activity->min_temperature,
                'max_temperature' => $activity->max_temperature,
                'moderate_intensity_minutes' => $activity->moderate_intensity_minutes,
                'vigorous_intensity_minutes' => $activity->vigorous_intensity_minutes,
                'run_duration_seconds' => $activity->run_duration_seconds,
                'walk_duration_seconds' => $activity->walk_duration_seconds,
                'idle_duration_seconds' => $activity->idle_duration_seconds,
                'is_favorite' => $activity->is_favorite,
                'favorite_override' => $activity->favorite_override,
                'is_pr' => $activity->is_pr,
                'is_manual' => $activity->is_manual,
                'streams_synced_at' => $activity->streams_synced_at?->toIso8601String(),
            ],
            'hrZones' => $this->buildHrZones($activity, $zoneConfig),
            'powerZones' => $this->buildPowerZones($activity),
            'paceDistribution' => $this->paceDistributionService->forActivity($activity, $series),
            'effortContext' => $this->effortService->forActivity($activity),
            'stream' => $stream !== null ? [
                'series' => $stream->series ?? [],
                'polyline' => $stream->polyline ?? [],
                'laps' => $stream->laps ?? [],
                'km_splits' => $stream->km_splits ?? [],
                'run_walk_segments' => $stream->run_walk_segments ?? [],
                'jumps' => $stream->jumps ?? [],
            ] : null,
            'weather' => $weather !== null ? [
                'provider' => $weather->provider,
                'model_endpoint' => $weather->model_endpoint,
                'sampling_mode' => $weather->sampling_mode,
                'waypoint_count' => $weather->waypoint_count,
                'wmo_code' => $weather->wmo_code,
                'condition' => $weather->condition,
                'base_condition' => $weather->base_condition,
                'icon' => $weather->icon,
                'temperature_c' => $weather->temperature_c,
                'min_temperature_c' => $weather->min_temperature_c,
                'max_temperature_c' => $weather->max_temperature_c,
                'apparent_temperature_c' => $weather->apparent_temperature_c,
                'humidity_pct' => $weather->humidity_pct,
                'dew_point_c' => $weather->dew_point_c,
                'wind_speed_kmh' => $weather->wind_speed_kmh,
                'wind_gusts_kmh' => $weather->wind_gusts_kmh,
                'wind_direction_deg' => $weather->wind_direction_deg,
                'wind_direction_compass' => $weather->wind_direction_compass,
                'precipitation_mm' => $weather->precipitation_mm,
                'cloud_cover_pct' => $weather->cloud_cover_pct,
                'surface_pressure_hpa' => $weather->surface_pressure_hpa,
                'headwind_pct' => $weather->headwind_pct,
                'crosswind_pct' => $weather->crosswind_pct,
                'tailwind_pct' => $weather->tailwind_pct,
                'avg_effective_wind_kmh' => $weather->avg_effective_wind_kmh,
                'samples' => $weather->samples,
                'fetched_at' => $weather->fetched_at->toIso8601String(),
            ] : null,
            'derivedMetrics' => $derivedMetrics !== null ? [
                'decoupling_pct' => $derivedMetrics->decoupling_pct,
                'decoupling_metric' => $derivedMetrics->decoupling_metric,
                'decoupling_rating' => $derivedMetrics->decoupling_rating,
                'efficiency_factor' => $derivedMetrics->efficiency_factor,
                'first_half_ef' => $derivedMetrics->first_half_ef,
                'second_half_ef' => $derivedMetrics->second_half_ef,
                'pacing_strategy' => $derivedMetrics->pacing_strategy,
                'split_ratio' => $derivedMetrics->split_ratio,
                'pace_cv_pct' => $derivedMetrics->pace_cv_pct,
                ...$this->derivedMetricsService->polarizedHrSplit($activity),
                'computed_at' => $derivedMetrics->computed_at->toIso8601String(),
            ] : null,
            'bestEfforts' => $bestEfforts,
            'prevActivity' => $prev !== null ? ['id' => $prev->id, 'name' => $prev->name] : null,
            'nextActivity' => $next !== null ? ['id' => $next->id, 'name' => $next->name] : null,
            'gear' => $this->gearService->forActivity($activity),
            'matchedRoute' => $this->matchedRouteService()->forActivity($activity),
            'segmentEfforts' => $this->segmentQueryService()->forActivity($activity),
            'discipline_options' => $this->sportRegistry()->disciplinesForCategory($activity->sport_category),
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    private function normalizeSearch(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        return $default;
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applyFacetFilters(
        Builder $query,
        ?string $search,
        string $sport,
        bool $pr,
        bool $favorite,
    ): void {
        if ($search !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';

            $query->where(function (Builder $subQuery) use ($pattern): void {
                $subQuery
                    ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(location_name, '')) LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        if ($sport === 'biking_other') {
            $query->where('sport_category', 'biking')
                ->whereRaw('COALESCE(discipline_override, sport_discipline) = ?', ['other']);
        } elseif ($sport !== 'all') {
            $this->sportRegistry()->applyScope($query, $sport);
        }

        if ($pr) {
            $query->where('is_pr', true);
        }

        if ($favorite) {
            $query->where('is_favorite', true);
        }
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array{
     *     range: string,
     *     start: ?string,
     *     end: string,
     *     from: ?string,
     *     to: ?string
     * }  $dateWindow
     */
    private function applyDateWindowFilter(Builder $query, array $dateWindow): void
    {
        if ($dateWindow['range'] === 'all') {
            return;
        }

        if ($dateWindow['range'] === 'custom') {
            if ($dateWindow['from'] !== null) {
                $query->where('started_at_local', '>=', $dateWindow['from'].' 00:00:00');
            }

            if ($dateWindow['to'] !== null) {
                $query->where('started_at_local', '<=', $dateWindow['to'].' 23:59:59');
            }

            return;
        }

        if ($dateWindow['start'] !== null) {
            $query->whereBetween('started_at_local', [
                $dateWindow['start'].' 00:00:00',
                $dateWindow['end'].' 23:59:59',
            ]);
        }
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  'asc'|'desc'  $dir
     */
    private function applySorting(Builder $query, string $sort, string $dir): void
    {
        $column = match ($sort) {
            'distance' => 'distance_meters',
            'duration' => 'duration_seconds',
            'elevation' => 'elevation_gain',
            'hr' => 'avg_hr',
            'load' => 'training_load',
            default => 'started_at_local',
        };

        if ($column !== 'started_at_local') {
            $query->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END ASC");
        }

        $query->orderBy($column, $dir);

        if ($column !== 'started_at_local') {
            $query->orderByDesc('started_at_local');
        }

        $query->orderBy('id', $dir);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return array{
     *     count: int,
     *     total_distance_meters: float,
     *     total_duration_seconds: float,
     *     total_elevation_gain: float,
     *     total_calories: int,
     *     avg_hr: ?float,
     *     total_load: float
     * }
     */
    private function buildSummary(Builder $query): array
    {
        $row = (clone $query)
            ->toBase()
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as total_distance_meters')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as total_duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as total_elevation_gain')
            ->selectRaw('COALESCE(SUM(COALESCE(calories, 0)), 0) as total_calories')
            ->selectRaw('AVG(avg_hr) as avg_hr')
            ->selectRaw('COALESCE(SUM(COALESCE(training_load, 0)), 0) as total_load')
            ->first();

        if ($row === null) {
            return [
                'count' => 0,
                'total_distance_meters' => 0.0,
                'total_duration_seconds' => 0.0,
                'total_elevation_gain' => 0.0,
                'total_calories' => 0,
                'avg_hr' => null,
                'total_load' => 0.0,
            ];
        }

        return [
            'count' => (int) ($row->count ?? 0),
            'total_distance_meters' => round((float) ($row->total_distance_meters ?? 0.0), 1),
            'total_duration_seconds' => round((float) ($row->total_duration_seconds ?? 0.0), 1),
            'total_elevation_gain' => round((float) ($row->total_elevation_gain ?? 0.0), 1),
            'total_calories' => (int) ($row->total_calories ?? 0),
            'avg_hr' => isset($row->avg_hr) && is_numeric($row->avg_hr) ? round((float) $row->avg_hr, 1) : null,
            'total_load' => round((float) ($row->total_load ?? 0.0), 1),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     provider: string,
     *     external_id: ?string,
     *     garmin_activity_id: ?int,
     *     name: string,
     *     activity_type_key: string,
     *     sport_category: string,
     *     sport_discipline: ?string,
     *     biking_discipline: ?string,
     *     discipline_override: ?string,
     *     effective_discipline: ?string,
     *     is_indoor: bool,
     *     started_at_local: string,
     *     location_name: ?string,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     moving_duration_seconds: ?float,
     *     elevation_gain: ?float,
     *     avg_speed: ?float,
     *     avg_hr: ?int,
     *     max_hr: ?int,
     *     avg_power: ?float,
     *     normalized_power: ?float,
     *     tss: ?float,
     *     grit: ?float,
     *     avg_flow: ?float,
     *     training_load: ?float,
     *     calories: ?int,
     *     is_favorite: bool,
     *     is_pr: bool,
     *     has_streams: bool
     * }
     */
    private function mapListItem(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'provider' => $activity->provider,
            'external_id' => $activity->external_id,
            'garmin_activity_id' => $activity->garmin_activity_id,
            'name' => $activity->name,
            'activity_type_key' => $activity->activity_type_key,
            'sport_category' => $activity->sport_category,
            'sport_discipline' => $activity->sport_discipline,
            'biking_discipline' => $activity->sport_discipline,
            'discipline_override' => $activity->discipline_override,
            'effective_discipline' => $activity->effectiveDiscipline(),
            'is_indoor' => $activity->is_indoor,
            'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
            'location_name' => $activity->location_name,
            'distance_meters' => $activity->distance_meters,
            'duration_seconds' => $activity->duration_seconds,
            'moving_duration_seconds' => $activity->moving_duration_seconds,
            'elevation_gain' => $activity->elevation_gain,
            'avg_speed' => $activity->avg_speed,
            'avg_hr' => $activity->avg_hr,
            'max_hr' => $activity->max_hr,
            'avg_power' => $activity->avg_power,
            'normalized_power' => $activity->normalized_power,
            'tss' => $activity->tss,
            'grit' => $activity->grit,
            'avg_flow' => $activity->avg_flow,
            'training_load' => $activity->training_load,
            'calories' => $activity->calories,
            'is_favorite' => $activity->is_favorite,
            'is_pr' => $activity->is_pr,
            'has_streams' => $activity->streams_synced_at !== null || (bool) $activity->getAttribute('stream_exists'),
        ];
    }

    /**
     * @param  Builder<Activity>  $filteredQuery
     */
    private function resolveMonth(
        mixed $month,
        Builder $filteredQuery,
        CarbonImmutable $today,
        string $timezone,
    ): CarbonImmutable {
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone);

            if ($parsed !== null && $parsed->format('Y-m') === $month) {
                return $parsed->startOfMonth();
            }
        }

        $latestFiltered = (clone $filteredQuery)->max('started_at_local');

        if (is_string($latestFiltered) && trim($latestFiltered) !== '') {
            return CarbonImmutable::parse($latestFiltered, $timezone)->startOfMonth();
        }

        $latestGlobal = Activity::query()->max('started_at_local');

        if (is_string($latestGlobal) && trim($latestGlobal) !== '') {
            return CarbonImmutable::parse($latestGlobal, $timezone)->startOfMonth();
        }

        return $today->startOfMonth();
    }

    /**
     * @return array{
     *     month: string,
     *     monthLabel: string,
     *     prevMonth: string,
     *     nextMonth: string,
     *     weeks: list<array{
     *         weekNumber: int,
     *         isFuture: bool,
     *         isCurrent: bool,
     *         days: list<array{
     *             date: string,
     *             dayOfMonth: int,
     *             inMonth: bool,
     *             isToday: bool,
     *             activities: list<array{
     *                 id: int,
     *                 name: string,
     *                 sport_category: string,
     *                 effective_discipline: ?string,
     *                 distance_meters: float,
     *                 duration_seconds: float,
     *                 elevation_gain: ?float,
     *                 training_load: ?float
     *             }>,
     *             total_distance_meters: float,
     *             total_duration_seconds: float
     *         }>,
     *         totals: array{
     *             count: int,
     *             distance_meters: float,
     *             duration_seconds: float,
     *             elevation_gain: float,
     *             load: float
     *         }
     *     }>
     * }
     */
    private function buildCalendar(
        CarbonImmutable $monthStart,
        CarbonImmutable $today,
        ?string $search,
        string $sport,
        bool $pr,
        bool $favorite,
    ): array {
        $gridStart = $monthStart->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $gridEnd = $monthStart->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
        $todayStr = $today->format('Y-m-d');

        $calendarQuery = Activity::query()
            ->select([
                'id',
                'name',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'started_at_local',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'training_load',
            ])
            ->whereBetween('started_at_local', [
                $gridStart->format('Y-m-d').' 00:00:00',
                $gridEnd->format('Y-m-d').' 23:59:59',
            ])
            ->orderBy('started_at_local')
            ->orderBy('id');

        $this->applyFacetFilters($calendarQuery, $search, $sport, $pr, $favorite);

        $activities = $calendarQuery->get();

        /** @var array<string, list<Activity>> $byDate */
        $byDate = [];

        foreach ($activities as $activity) {
            $dateKey = $activity->started_at_local->format('Y-m-d');
            $byDate[$dateKey][] = $activity;
        }

        $weeks = [];
        $weekCursor = $gridStart;

        while ($weekCursor->lessThanOrEqualTo($gridEnd)) {
            $days = [];
            $weekCount = 0;
            $weekDistance = 0.0;
            $weekDuration = 0.0;
            $weekElevation = 0.0;
            $weekLoad = 0.0;

            for ($offset = 0; $offset < 7; $offset++) {
                $day = $weekCursor->addDays($offset);
                $dayStr = $day->format('Y-m-d');
                $dayActivities = $byDate[$dayStr] ?? [];

                $mappedDayActivities = [];
                $dayDistance = 0.0;
                $dayDuration = 0.0;

                foreach ($dayActivities as $activity) {
                    $distance = $activity->distance_meters;
                    $duration = $activity->duration_seconds;
                    $elevation = $activity->elevation_gain ?? 0.0;
                    $load = $activity->training_load ?? 0.0;

                    $dayDistance += $distance;
                    $dayDuration += $duration;

                    $weekCount++;
                    $weekDistance += $distance;
                    $weekDuration += $duration;
                    $weekElevation += $elevation;
                    $weekLoad += $load;

                    $mappedDayActivities[] = [
                        'id' => $activity->id,
                        'name' => $activity->name,
                        'sport_category' => $activity->sport_category,
                        'effective_discipline' => $activity->effectiveDiscipline(),
                        'distance_meters' => $distance,
                        'duration_seconds' => $duration,
                        'elevation_gain' => $activity->elevation_gain,
                        'training_load' => $activity->training_load,
                    ];
                }

                $days[] = [
                    'date' => $dayStr,
                    'dayOfMonth' => $day->day,
                    'inMonth' => $day->month === $monthStart->month && $day->year === $monthStart->year,
                    'isToday' => $dayStr === $todayStr,
                    'activities' => $mappedDayActivities,
                    'total_distance_meters' => round($dayDistance, 1),
                    'total_duration_seconds' => round($dayDuration, 1),
                ];
            }

            $weekEnd = $weekCursor->addDays(6);

            $weeks[] = [
                'weekNumber' => (int) $weekCursor->format('W'),
                'isFuture' => $weekCursor->greaterThan($today),
                'isCurrent' => $weekCursor->lessThanOrEqualTo($today) && $weekEnd->greaterThanOrEqualTo($today),
                'days' => $days,
                'totals' => [
                    'count' => $weekCount,
                    'distance_meters' => round($weekDistance, 1),
                    'duration_seconds' => round($weekDuration, 1),
                    'elevation_gain' => round($weekElevation, 1),
                    'load' => round($weekLoad, 1),
                ],
            ];

            $weekCursor = $weekCursor->addWeek();
        }

        return [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'prevMonth' => $monthStart->subMonth()->format('Y-m'),
            'nextMonth' => $monthStart->addMonth()->format('Y-m'),
            'weeks' => $weeks,
        ];
    }

    private function resolveHrZoneConfig(Activity $activity): ?HeartRateZone
    {
        $preferredSport = match ($activity->sport_category) {
            'running' => 'RUNNING',
            'biking' => 'CYCLING',
            default => 'DEFAULT',
        };

        $sportZones = $this->healthSources()->applyScope(HeartRateZone::query(), 'heart_rate_zones')
            ->whereIn('sport', array_values(array_unique([$preferredSport, 'DEFAULT'])))
            ->get()
            ->keyBy('sport');

        return $sportZones->get($preferredSport) ?? $sportZones->get('DEFAULT');
    }

    /**
     * @return list<array{zone: int, label: string, min_hr: ?int, max_hr: ?int, seconds: float, pct: float}>
     */
    private function buildHrZones(Activity $activity, ?HeartRateZone $zoneConfig = null): array
    {
        $zoneSeconds = [
            1 => $activity->hr_zone_1_seconds ?? 0.0,
            2 => $activity->hr_zone_2_seconds ?? 0.0,
            3 => $activity->hr_zone_3_seconds ?? 0.0,
            4 => $activity->hr_zone_4_seconds ?? 0.0,
            5 => $activity->hr_zone_5_seconds ?? 0.0,
        ];

        $totalSeconds = array_sum($zoneSeconds);

        if ($totalSeconds <= 0.0) {
            return [];
        }

        $zoneConfig ??= $this->resolveHrZoneConfig($activity);

        $bounds = [
            1 => [
                'min' => $zoneConfig?->zone1_floor,
                'max' => $zoneConfig?->zone2_floor !== null ? $zoneConfig->zone2_floor - 1 : null,
            ],
            2 => [
                'min' => $zoneConfig?->zone2_floor,
                'max' => $zoneConfig?->zone3_floor !== null ? $zoneConfig->zone3_floor - 1 : null,
            ],
            3 => [
                'min' => $zoneConfig?->zone3_floor,
                'max' => $zoneConfig?->zone4_floor !== null ? $zoneConfig->zone4_floor - 1 : null,
            ],
            4 => [
                'min' => $zoneConfig?->zone4_floor,
                'max' => $zoneConfig?->zone5_floor !== null ? $zoneConfig->zone5_floor - 1 : null,
            ],
            5 => [
                'min' => $zoneConfig?->zone5_floor,
                'max' => $zoneConfig?->max_hr,
            ],
        ];

        $result = [];

        foreach ($zoneSeconds as $zoneNumber => $seconds) {
            $result[] = [
                'zone' => $zoneNumber,
                'label' => self::HR_ZONE_LABELS[$zoneNumber],
                'min_hr' => $bounds[$zoneNumber]['min'],
                'max_hr' => $bounds[$zoneNumber]['max'],
                'seconds' => round($seconds, 1),
                'pct' => round(($seconds / $totalSeconds) * 100, 1),
            ];
        }

        return $result;
    }

    /**
     * @return list<array{zone: int, label: string, min_watts: ?int, max_watts: ?int, seconds: float, pct: float}>
     */
    private function buildPowerZones(Activity $activity): array
    {
        $isRunningFiveZone = $activity->sport_category === 'running'
            && ($activity->power_zone_6_seconds ?? 0.0) <= 0.0
            && ($activity->power_zone_7_seconds ?? 0.0) <= 0.0;

        $zoneSeconds = [
            1 => $activity->power_zone_1_seconds ?? 0.0,
            2 => $activity->power_zone_2_seconds ?? 0.0,
            3 => $activity->power_zone_3_seconds ?? 0.0,
            4 => $activity->power_zone_4_seconds ?? 0.0,
            5 => $activity->power_zone_5_seconds ?? 0.0,
        ];

        if (! $isRunningFiveZone) {
            $zoneSeconds[6] = $activity->power_zone_6_seconds ?? 0.0;
            $zoneSeconds[7] = $activity->power_zone_7_seconds ?? 0.0;
        }

        $totalSeconds = array_sum($zoneSeconds);

        if ($totalSeconds <= 0.0) {
            return [];
        }

        $rawBounds = is_array($activity->power_zone_bounds) ? $activity->power_zone_bounds : [];
        $labels = $isRunningFiveZone ? self::RUNNING_POWER_ZONE_LABELS : self::POWER_ZONE_LABELS;
        $zoneCount = count($zoneSeconds);

        $result = [];

        foreach ($zoneSeconds as $zoneNumber => $seconds) {
            $idx = $zoneNumber - 1;
            $minWatts = $rawBounds[$idx] ?? null;
            $nextFloor = $rawBounds[$idx + 1] ?? null;
            $maxWatts = ($zoneNumber < $zoneCount && $nextFloor !== null) ? max(0, $nextFloor - 1) : null;

            $result[] = [
                'zone' => $zoneNumber,
                'label' => $labels[$zoneNumber] ?? "Zone {$zoneNumber}",
                'min_watts' => $minWatts,
                'max_watts' => $maxWatts,
                'seconds' => round($seconds, 1),
                'pct' => round(($seconds / $totalSeconds) * 100, 1),
            ];
        }

        return $result;
    }

    /**
     * Resolve all-time Top 3 ranks for an activity's non-excluded best efforts in a single query.
     *
     * @param  list<BestEffort>  $efforts
     * @return array<int, int>
     */
    private function resolveBestEffortRanks(array $efforts): array
    {
        if ($efforts === []) {
            return [];
        }

        $sport = $efforts[0]->sport;
        $distanceKeys = array_values(array_unique(array_map(
            static fn (BestEffort $e): string => $e->distance_key,
            $efforts,
        )));

        $allForDistances = BestEffort::query()
            ->select(['id', 'distance_key', 'elapsed_seconds', 'override_seconds', 'achieved_at'])
            ->where('sport', $sport)
            ->where('is_excluded', false)
            ->whereIn('distance_key', $distanceKeys)
            ->get()
            ->groupBy('distance_key');

        $ranks = [];

        foreach ($efforts as $effort) {
            if ($effort->is_excluded) {
                continue;
            }

            $bucket = $allForDistances->get($effort->distance_key);

            if ($bucket === null) {
                continue;
            }

            $sorted = $bucket->all();
            usort($sorted, static function (BestEffort $a, BestEffort $b): int {
                $cmp = $a->effectiveSeconds() <=> $b->effectiveSeconds();

                if ($cmp !== 0) {
                    return $cmp;
                }

                return $a->id <=> $b->id;
            });

            foreach (array_slice($sorted, 0, 3) as $index => $candidate) {
                if ($candidate->id === $effort->id) {
                    $ranks[$effort->id] = $index + 1;
                    break;
                }
            }
        }

        return $ranks;
    }

    /**
     * @return array{
     *     id: int,
     *     sport: string,
     *     distance_key: string,
     *     label: string,
     *     distance_meters: float,
     *     elapsed_seconds: float,
     *     effective_seconds: float,
     *     moving_seconds: ?float,
     *     start_offset_seconds: ?float,
     *     avg_hr: ?int,
     *     elevation_delta: ?float,
     *     start_distance_meters: ?float,
     *     end_distance_meters: ?float,
     *     all_time_rank: ?int,
     *     source: string,
     *     override_seconds: ?float,
     *     is_excluded: bool
     * }
     */
    private function mapBestEffort(BestEffort $effort, ?int $allTimeRank = null): array
    {
        $label = self::DISTANCE_LABELS[$effort->distance_key]
            ?? strtoupper(str_replace('_', ' ', $effort->distance_key));

        return [
            'id' => $effort->id,
            'sport' => $effort->sport,
            'distance_key' => $effort->distance_key,
            'label' => $label,
            'distance_meters' => $effort->distance_meters,
            'elapsed_seconds' => $effort->elapsed_seconds,
            'effective_seconds' => $effort->effectiveSeconds(),
            'moving_seconds' => $effort->moving_seconds,
            'start_offset_seconds' => $effort->start_offset_seconds,
            'avg_hr' => $effort->avg_hr,
            'elevation_delta' => $effort->elevation_delta,
            'start_distance_meters' => $effort->start_distance_meters,
            'end_distance_meters' => $effort->end_distance_meters,
            'all_time_rank' => $allTimeRank,
            'source' => $effort->source,
            'override_seconds' => $effort->override_seconds,
            'is_excluded' => $effort->is_excluded,
        ];
    }
}
