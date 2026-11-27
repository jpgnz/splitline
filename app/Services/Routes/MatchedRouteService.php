<?php

namespace App\Services\Routes;

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityWeather;
use App\Models\RouteCluster;
use App\Services\Heatmap\HeatmapService;
use App\Support\SportRegistry;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query and presentation service for Matched Routes index, detail, and Activity detail integration.
 *
 * @phpstan-type AttemptWeatherSummary array{
 *     condition: string,
 *     icon: string,
 *     temperature_c: ?float,
 *     apparent_temperature_c: ?float,
 *     wind_speed_kmh: ?float,
 *     wind_direction_compass: ?string,
 *     headwind_pct: ?float,
 *     crosswind_pct: ?float,
 *     tailwind_pct: ?float,
 *     avg_effective_wind_kmh: ?float,
 *     wind_label: 'Headwind'|'Tailwind'|'Crosswind'|'Calm'
 * }
 * @phpstan-type RouteAttemptItem array{
 *     activity_id: int,
 *     name: string,
 *     sport_key: string,
 *     date: string,
 *     started_at_local: string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     normalized_duration_seconds: float,
 *     moving_duration_seconds: ?float,
 *     elevation_gain: ?float,
 *     avg_speed: ?float,
 *     avg_hr: ?int,
 *     max_hr: ?int,
 *     avg_power: ?float,
 *     normalized_power: ?float,
 *     delta_seconds: float,
 *     delta_median_seconds: float,
 *     rank: int,
 *     is_pr: bool,
 *     is_current: bool,
 *     weather: ?AttemptWeatherSummary
 * }
 * @phpstan-type WindImpactBucket array{
 *     count: int,
 *     avg_duration_seconds: float,
 *     avg_speed: ?float,
 *     avg_hr: ?float
 * }
 * @phpstan-type TempImpactBucket array{
 *     label: string,
 *     count: int,
 *     avg_temp_c: float,
 *     avg_duration_seconds: float,
 *     avg_speed: ?float,
 *     avg_hr: ?float
 * }
 * @phpstan-type WeatherImpactBreakdown array{
 *     sample_count: int,
 *     wind: ?array{
 *         headwind: ?WindImpactBucket,
 *         neutral: ?WindImpactBucket,
 *         tailwind: ?WindImpactBucket,
 *         headwind_vs_tailwind_delta_seconds: ?float
 *     },
 *     temperature: ?array{
 *         threshold_c: float,
 *         cool: TempImpactBucket,
 *         warm: TempImpactBucket,
 *         warm_vs_cool_delta_seconds: float
 *     }
 * }
 */
class MatchedRouteService
{
    /**
     * @var list<string>
     */
    private const array VALID_SORTS = ['count', 'recent', 'distance', 'elevation'];

    /**
     * Maximum canonical polyline points sent to the route detail map.
     */
    private const int CANONICAL_POLYLINE_MAX_POINTS = 1000;

    /**
     * Activity columns needed to summarize attempts on the routes directory.
     *
     * @var list<string>
     */
    private const array INDEX_ACTIVITY_COLUMNS = [
        'id',
        'sport_category',
        'sport_discipline',
        'discipline_override',
        'is_indoor',
        'distance_meters',
        'duration_seconds',
        'avg_speed',
        'started_at_local',
        'location_name',
    ];

    public function __construct(
        private readonly ?SportRegistry $sports = null,
        private readonly RouteMatchingService $matcher = new RouteMatchingService,
    ) {}

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    /**
     * Build the Matched Routes directory payload (clusters with >= 2 attempts).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters = []): array
    {
        $sport = $this->normalizeSport($filters['sport'] ?? null);
        $search = $this->normalizeSearch($filters['search'] ?? null);
        $sort = $this->normalizeSort($filters['sort'] ?? null);

        $query = RouteCluster::query()
            ->matched()
            ->with([
                'representativeActivity',
                'fingerprints' => function ($fpQuery): void {
                    $fpQuery
                        ->select(['id', 'activity_id', 'route_cluster_id', 'is_unlinked'])
                        ->where('is_unlinked', false)
                        ->with('activity:'.implode(',', self::INDEX_ACTIVITY_COLUMNS));
                },
            ]);

        $registry = $this->sportRegistry();

        if ($sport !== 'all') {
            $entry = $registry->get($sport);

            if ($entry !== null) {
                $query->where('sport_category', $entry['sport_category']);
            }
        }

        if ($search !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';

            $query->where(function (Builder $sub) use ($pattern): void {
                $sub
                    ->whereRaw("LOWER(COALESCE(name_override, name)) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(place_label, '')) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereHas('representativeActivity', function (Builder $activityQuery) use ($pattern): void {
                        $activityQuery->whereRaw("LOWER(COALESCE(location_name, '')) LIKE ? ESCAPE '\\'", [$pattern]);
                    });
            });
        }

        match ($sort) {
            'recent' => $query
                ->orderByDesc('last_activity_at')
                ->orderByDesc('activity_count')
                ->orderByDesc('id'),
            'distance' => $query
                ->orderByDesc('distance_meters')
                ->orderByDesc('activity_count')
                ->orderByDesc('id'),
            'elevation' => $query
                ->orderByRaw('CASE WHEN elevation_gain IS NULL THEN 1 ELSE 0 END ASC')
                ->orderByDesc('elevation_gain')
                ->orderByDesc('activity_count')
                ->orderByDesc('id'),
            default => $query
                ->orderByDesc('activity_count')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id'),
        };

        $clusters = $query->get();

        $routes = [];
        $totalAttempts = 0;
        $totalDistanceMeters = 0.0;
        $mostRepeatedCount = 0;
        $mostRepeatedName = null;

        foreach ($clusters as $cluster) {
            /** @var list<Activity> $activities */
            $activities = [];

            foreach ($cluster->fingerprints as $fp) {
                $activity = $fp->getRelation('activity');

                if ($activity instanceof Activity) {
                    $activities[] = $activity;
                }
            }

            $sportCounts = $this->buildActivitySportCounts($activities);
            $sportMeta = $this->resolveClusterSportMeta($cluster, $sportCounts);

            if ($sport !== 'all' && ! $registry->sportKeyMatchesScope($sportMeta['sport_key'], $sport)) {
                continue;
            }

            $stats = $this->summarizeAttemptStats($activities);
            $locationName = $this->resolveClusterLocation($cluster, $activities);

            $attemptCount = max($cluster->activity_count, count($activities));
            $totalAttempts += $attemptCount;
            $totalDistanceMeters += $stats['total_distance_meters'] > 0.0
                ? $stats['total_distance_meters']
                : ($cluster->distance_meters * $attemptCount);

            if ($attemptCount > $mostRepeatedCount) {
                $mostRepeatedCount = $attemptCount;
                $mostRepeatedName = $cluster->displayName();
            }

            $routes[] = [
                'id' => $cluster->id,
                'name' => $cluster->displayName(),
                'auto_name' => $cluster->name,
                'name_override' => $cluster->name_override,
                'place_label' => $cluster->place_label,
                'sport_category' => $cluster->sport_category,
                'effective_discipline' => $sportMeta['effective_discipline'],
                'sport_key' => $sportMeta['sport_key'],
                'accent_hex' => $sportMeta['accent_hex'],
                'metric_mode' => $sportMeta['metric_mode'],
                'location_name' => $locationName,
                'is_loop' => $cluster->is_loop,
                'distance_meters' => round($cluster->distance_meters, 1),
                'elevation_gain' => $cluster->elevation_gain !== null ? round($cluster->elevation_gain, 1) : null,
                'encoded_polyline' => $cluster->encoded_polyline,
                'activity_count' => $attemptCount,
                'last_activity_at' => $cluster->last_activity_at?->format('Y-m-d'),
                'pr_duration_seconds' => $stats['pr_duration_seconds'],
                'pr_speed_mps' => $stats['pr_speed_mps'],
                'pr_activity_id' => $stats['pr_activity_id'],
                'pr_date' => $stats['pr_date'],
                'median_duration_seconds' => $stats['median_duration_seconds'],
                'median_speed_mps' => $stats['median_speed_mps'],
                'latest_duration_seconds' => $stats['latest_duration_seconds'],
                'latest_speed_mps' => $stats['latest_speed_mps'],
                'latest_activity_id' => $stats['latest_activity_id'],
                'latest_date' => $stats['latest_date'],
                'trend_delta_seconds' => $stats['trend_delta_seconds'],
            ];
        }

        return [
            'filters' => [
                'sport' => $sport,
                'search' => $search ?? '',
                'sort' => $sort,
            ],
            'available_sports' => $this->outdoorFilterableSports(),
            'summary' => [
                'route_count' => count($routes),
                'total_attempts' => $totalAttempts,
                'most_repeated_count' => $mostRepeatedCount,
                'most_repeated_name' => $mostRepeatedName,
                'total_distance_meters' => round($totalDistanceMeters, 1),
                'unfingerprinted_count' => $this->matcher->unfingerprintedCount(),
            ],
            'routes' => $routes,
        ];
    }

    /**
     * Build the full detail payload for a RouteCluster.
     *
     * @return array<string, mixed>
     */
    public function show(RouteCluster $cluster, ?string $sport = null): array
    {
        $registry = $this->sportRegistry();
        $cluster->load(['representativeActivity.stream:id,activity_id,polyline']);

        $activities = $this->loadClusterActivities($cluster);
        $sportCounts = $this->buildActivitySportCounts($activities);
        $sportMeta = $this->resolveClusterSportMeta($cluster, $sportCounts);
        $availableSports = $registry->buildSportFilterOptions($sportCounts);
        $selectedSport = $registry->resolveSportFilterSelection($availableSports, $sport);

        $filteredActivities = $selectedSport !== null
            ? array_values(array_filter(
                $activities,
                static fn (Activity $act): bool => $registry->sportKeysShareBucket($registry->resolveActivitySportKey($act), $selectedSport),
            ))
            : $activities;

        $analyzed = $this->analyzeAttempts($filteredActivities);
        $locationName = $this->resolveClusterLocation($cluster, $activities);

        return [
            'route' => [
                'id' => $cluster->id,
                'name' => $cluster->displayName(),
                'auto_name' => $cluster->name,
                'name_override' => $cluster->name_override,
                'place_label' => $cluster->place_label,
                'sport_category' => $cluster->sport_category,
                'effective_discipline' => $sportMeta['effective_discipline'],
                'sport_key' => $sportMeta['sport_key'],
                'accent_hex' => $sportMeta['accent_hex'],
                'metric_mode' => $sportMeta['metric_mode'],
                'location_name' => $locationName,
                'is_loop' => $cluster->is_loop,
                'distance_meters' => round($cluster->distance_meters, 1),
                'elevation_gain' => $cluster->elevation_gain !== null ? round($cluster->elevation_gain, 1) : null,
                'activity_count' => max($cluster->activity_count, count($activities)),
                'last_activity_at' => $cluster->last_activity_at?->format('Y-m-d'),
            ],
            'polyline' => $this->resolveCanonicalPolyline($cluster),
            'summary' => $analyzed['summary'],
            'available_sports' => $availableSports,
            'selected_sport' => $selectedSport,
            'progression' => $analyzed['progression'],
            'attempts' => $analyzed['attempts'],
            'weather_impact' => $analyzed['weather_impact'],
        ];
    }

    /**
     * Build the compact matched-route payload for an Activity detail page when it belongs to a cluster with >= 2 attempts.
     *
     * @return array<string, mixed>|null
     */
    public function forActivity(Activity $activity): ?array
    {
        $fingerprint = $activity->relationLoaded('routeFingerprint')
            ? $activity->routeFingerprint
            : $activity->routeFingerprint()->with('cluster.representativeActivity')->first();

        if (
            ! $fingerprint instanceof ActivityRouteFingerprint
            || $fingerprint->is_unlinked
            || $fingerprint->route_cluster_id === null
        ) {
            return null;
        }

        $cluster = $fingerprint->relationLoaded('cluster')
            ? $fingerprint->cluster
            : RouteCluster::query()->with('representativeActivity')->find($fingerprint->route_cluster_id);

        if (! $cluster instanceof RouteCluster || $cluster->activity_count < 2) {
            return null;
        }

        $activities = $this->loadClusterActivities($cluster);

        if (count($activities) < 2) {
            return null;
        }

        $analyzed = $this->analyzeAttempts($activities, $activity->id);
        $sportMeta = $this->resolveClusterSportMeta($cluster, $this->buildActivitySportCounts($activities));
        $locationName = $this->resolveClusterLocation($cluster, $activities);

        $currentAttempt = null;

        foreach ($analyzed['progression'] as $attempt) {
            if ($attempt['activity_id'] === $activity->id) {
                $currentAttempt = $attempt;
                break;
            }
        }

        if ($currentAttempt === null) {
            return null;
        }

        return [
            'route' => [
                'id' => $cluster->id,
                'name' => $cluster->displayName(),
                'place_label' => $cluster->place_label,
                'sport_category' => $cluster->sport_category,
                'effective_discipline' => $sportMeta['effective_discipline'],
                'sport_key' => $sportMeta['sport_key'],
                'accent_hex' => $sportMeta['accent_hex'],
                'metric_mode' => $sportMeta['metric_mode'],
                'location_name' => $locationName,
                'is_loop' => $cluster->is_loop,
                'distance_meters' => round($cluster->distance_meters, 1),
                'elevation_gain' => $cluster->elevation_gain !== null ? round($cluster->elevation_gain, 1) : null,
                'activity_count' => count($activities),
            ],
            'current' => [
                'activity_id' => $currentAttempt['activity_id'],
                'rank' => $currentAttempt['rank'],
                'total_attempts' => count($activities),
                'is_pr' => $currentAttempt['is_pr'],
                'duration_seconds' => $currentAttempt['duration_seconds'],
                'avg_speed' => $currentAttempt['avg_speed'],
                'delta_pr_seconds' => $currentAttempt['delta_seconds'],
                'delta_median_seconds' => $currentAttempt['delta_median_seconds'],
            ],
            'summary' => $analyzed['summary'],
            'progression' => $analyzed['progression'],
            'attempts' => $analyzed['attempts'],
            'weather_impact' => $analyzed['weather_impact'],
        ];
    }

    /**
     * @return list<Activity>
     */
    private function loadClusterActivities(RouteCluster $cluster): array
    {
        $fingerprints = ActivityRouteFingerprint::query()
            ->with(['activity.weather'])
            ->where('route_cluster_id', $cluster->id)
            ->where('is_unlinked', false)
            ->get();

        /** @var list<Activity> $activities */
        $activities = [];

        foreach ($fingerprints as $fp) {
            $activity = $fp->getRelation('activity');

            if ($activity instanceof Activity) {
                $activities[] = $activity;
            }
        }

        usort($activities, static function (Activity $a, Activity $b): int {
            $cmp = $a->started_at_local <=> $b->started_at_local;

            if ($cmp !== 0) {
                return $cmp;
            }

            return $a->id <=> $b->id;
        });

        return $activities;
    }

    /**
     * Rank attempts by pace and express deltas, medians, and trends as distance-normalised times
     * (elapsed time scaled to the median attempt distance), so a shortcut attempt cannot claim the PR.
     *
     * @param  list<Activity>  $activities
     * @return array{
     *     summary: array{
     *         attempt_count: int,
     *         pr_duration_seconds: ?float,
     *         pr_speed_mps: ?float,
     *         pr_activity_id: ?int,
     *         pr_date: ?string,
     *         median_duration_seconds: ?float,
     *         median_speed_mps: ?float,
     *         avg_hr: ?float,
     *         avg_power: ?float,
     *         latest_duration_seconds: ?float,
     *         latest_date: ?string,
     *         trend_delta_seconds: ?float
     *     },
     *     progression: list<RouteAttemptItem>,
     *     attempts: list<RouteAttemptItem>,
     *     weather_impact: ?WeatherImpactBreakdown
     * }
     */
    private function analyzeAttempts(array $activities, ?int $currentActivityId = null): array
    {
        if ($activities === []) {
            return [
                'summary' => [
                    'attempt_count' => 0,
                    'pr_duration_seconds' => null,
                    'pr_speed_mps' => null,
                    'pr_activity_id' => null,
                    'pr_date' => null,
                    'median_duration_seconds' => null,
                    'median_speed_mps' => null,
                    'avg_hr' => null,
                    'avg_power' => null,
                    'latest_duration_seconds' => null,
                    'latest_date' => null,
                    'trend_delta_seconds' => null,
                ],
                'progression' => [],
                'attempts' => [],
                'weather_impact' => null,
            ];
        }

        $referenceDistance = $this->referenceDistanceMeters($activities);

        /** @var array<int, float|null> $normalizedById */
        $normalizedById = [];

        foreach ($activities as $act) {
            $normalizedById[$act->id] = $this->normalizedDurationSeconds($act, $referenceDistance);
        }

        $rankedActivities = $this->rankByPace($activities, $normalizedById);

        /** @var array<int, int> $ranksByActivityId */
        $ranksByActivityId = [];

        foreach ($rankedActivities as $idx => $ranked) {
            $ranksByActivityId[$ranked->id] = $idx + 1;
        }

        $prActivity = $rankedActivities[0];
        $prDuration = round($prActivity->duration_seconds, 1);
        $prNormalized = round($normalizedById[$prActivity->id] ?? $prActivity->duration_seconds, 1);
        $prSpeed = $this->effectiveSpeedMps($prActivity);

        /** @var list<float> $normalizedDurations */
        $normalizedDurations = [];
        /** @var list<float> $speeds */
        $speeds = [];
        /** @var list<float> $hrs */
        $hrs = [];
        /** @var list<float> $powers */
        $powers = [];

        foreach ($activities as $act) {
            if ($normalizedById[$act->id] !== null) {
                $normalizedDurations[] = $normalizedById[$act->id];
            }

            $speed = $this->effectiveSpeedMps($act);

            if ($speed !== null) {
                $speeds[] = $speed;
            }

            if ($act->avg_hr !== null) {
                $hrs[] = (float) $act->avg_hr;
            }

            if ($act->avg_power !== null) {
                $powers[] = $act->avg_power;
            }
        }

        $medianDuration = $normalizedDurations !== [] ? round(RouteMatchingService::median($normalizedDurations), 1) : null;
        $medianSpeed = $speeds !== [] ? round(RouteMatchingService::median($speeds), 3) : null;
        $avgHr = $hrs !== [] ? round(array_sum($hrs) / count($hrs), 1) : null;
        $avgPower = $powers !== [] ? round(array_sum($powers) / count($powers), 1) : null;

        $registry = $this->sportRegistry();
        /** @var list<RouteAttemptItem> $progression */
        $progression = [];

        foreach ($activities as $act) {
            $normalized = round($normalizedById[$act->id] ?? $act->duration_seconds, 1);
            $rank = $ranksByActivityId[$act->id] ?? count($activities);
            $weatherSummary = $act->weather instanceof ActivityWeather
                ? $this->mapWeatherSummary($act->weather)
                : null;

            $progression[] = [
                'activity_id' => $act->id,
                'name' => $act->name,
                'sport_key' => $registry->resolveActivitySportKey($act),
                'date' => $act->started_at_local->format('Y-m-d'),
                'started_at_local' => $act->started_at_local->format('Y-m-d H:i:s'),
                'distance_meters' => round($act->distance_meters, 1),
                'duration_seconds' => round($act->duration_seconds, 1),
                'normalized_duration_seconds' => $normalized,
                'moving_duration_seconds' => $act->moving_duration_seconds !== null ? round($act->moving_duration_seconds, 1) : null,
                'elevation_gain' => $act->elevation_gain !== null ? round($act->elevation_gain, 1) : null,
                'avg_speed' => $this->effectiveSpeedMps($act),
                'avg_hr' => $act->avg_hr,
                'max_hr' => $act->max_hr,
                'avg_power' => $act->avg_power !== null ? round($act->avg_power, 1) : null,
                'normalized_power' => $act->normalized_power !== null ? round($act->normalized_power, 1) : null,
                'delta_seconds' => round($normalized - $prNormalized, 1),
                'delta_median_seconds' => $medianDuration !== null ? round($normalized - $medianDuration, 1) : 0.0,
                'rank' => $rank,
                'is_pr' => $act->id === $prActivity->id,
                'is_current' => $currentActivityId !== null && $act->id === $currentActivityId,
                'weather' => $weatherSummary,
            ];
        }

        $latest = $progression[count($progression) - 1];
        $trendDeltaSeconds = $medianDuration !== null
            ? round($latest['normalized_duration_seconds'] - $medianDuration, 1)
            : null;

        $attemptsNewestFirst = array_reverse($progression);

        return [
            'summary' => [
                'attempt_count' => count($progression),
                'pr_duration_seconds' => $prDuration,
                'pr_speed_mps' => $prSpeed,
                'pr_activity_id' => $prActivity->id,
                'pr_date' => $prActivity->started_at_local->format('Y-m-d'),
                'median_duration_seconds' => $medianDuration,
                'median_speed_mps' => $medianSpeed,
                'avg_hr' => $avgHr,
                'avg_power' => $avgPower,
                'latest_duration_seconds' => $latest['duration_seconds'],
                'latest_date' => $latest['date'],
                'trend_delta_seconds' => $trendDeltaSeconds,
            ],
            'progression' => $progression,
            'attempts' => $attemptsNewestFirst,
            'weather_impact' => $this->buildWeatherImpact($progression),
        ];
    }

    /**
     * @param  list<Activity>  $activities
     * @return array{
     *     total_distance_meters: float,
     *     pr_duration_seconds: ?float,
     *     pr_speed_mps: ?float,
     *     pr_activity_id: ?int,
     *     pr_date: ?string,
     *     median_duration_seconds: ?float,
     *     median_speed_mps: ?float,
     *     latest_duration_seconds: ?float,
     *     latest_speed_mps: ?float,
     *     latest_activity_id: ?int,
     *     latest_date: ?string,
     *     trend_delta_seconds: ?float
     * }
     */
    private function summarizeAttemptStats(array $activities): array
    {
        if ($activities === []) {
            return [
                'total_distance_meters' => 0.0,
                'pr_duration_seconds' => null,
                'pr_speed_mps' => null,
                'pr_activity_id' => null,
                'pr_date' => null,
                'median_duration_seconds' => null,
                'median_speed_mps' => null,
                'latest_duration_seconds' => null,
                'latest_speed_mps' => null,
                'latest_activity_id' => null,
                'latest_date' => null,
                'trend_delta_seconds' => null,
            ];
        }

        usort($activities, static function (Activity $a, Activity $b): int {
            $cmp = $a->started_at_local <=> $b->started_at_local;

            if ($cmp !== 0) {
                return $cmp;
            }

            return $a->id <=> $b->id;
        });

        $referenceDistance = $this->referenceDistanceMeters($activities);
        $totalDistance = 0.0;
        /** @var array<int, float|null> $normalizedById */
        $normalizedById = [];
        /** @var list<float> $normalizedDurations */
        $normalizedDurations = [];
        /** @var list<float> $speeds */
        $speeds = [];

        foreach ($activities as $act) {
            $totalDistance += $act->distance_meters;
            $normalized = $this->normalizedDurationSeconds($act, $referenceDistance);
            $normalizedById[$act->id] = $normalized;

            if ($normalized !== null) {
                $normalizedDurations[] = $normalized;
            }

            $speed = $this->effectiveSpeedMps($act);

            if ($speed !== null) {
                $speeds[] = $speed;
            }
        }

        $ranked = $this->rankByPace($activities, $normalizedById);
        $prActivity = $normalizedById[$ranked[0]->id] !== null ? $ranked[0] : null;
        $latest = $activities[count($activities) - 1];
        $medianDuration = $normalizedDurations !== [] ? round(RouteMatchingService::median($normalizedDurations), 1) : null;
        $medianSpeed = $speeds !== [] ? round(RouteMatchingService::median($speeds), 3) : null;
        $latestNormalized = round($normalizedById[$latest->id] ?? $latest->duration_seconds, 1);

        return [
            'total_distance_meters' => round($totalDistance, 1),
            'pr_duration_seconds' => $prActivity !== null ? round($prActivity->duration_seconds, 1) : null,
            'pr_speed_mps' => $prActivity !== null ? $this->effectiveSpeedMps($prActivity) : null,
            'pr_activity_id' => $prActivity?->id,
            'pr_date' => $prActivity?->started_at_local->format('Y-m-d'),
            'median_duration_seconds' => $medianDuration,
            'median_speed_mps' => $medianSpeed,
            'latest_duration_seconds' => round($latest->duration_seconds, 1),
            'latest_speed_mps' => $this->effectiveSpeedMps($latest),
            'latest_activity_id' => $latest->id,
            'latest_date' => $latest->started_at_local->format('Y-m-d'),
            'trend_delta_seconds' => $medianDuration !== null ? round($latestNormalized - $medianDuration, 1) : null,
        ];
    }

    /**
     * @param  list<RouteAttemptItem>  $attempts
     * @return WeatherImpactBreakdown|null
     */
    private function buildWeatherImpact(array $attempts): ?array
    {
        $withWeather = array_values(array_filter(
            $attempts,
            static fn (array $attempt): bool => $attempt['weather'] !== null,
        ));

        if (count($withWeather) < 2) {
            return null;
        }

        /** @var list<RouteAttemptItem> $headwind */
        $headwind = [];
        /** @var list<RouteAttemptItem> $neutral */
        $neutral = [];
        /** @var list<RouteAttemptItem> $tailwind */
        $tailwind = [];

        foreach ($withWeather as $attempt) {
            $weather = $attempt['weather'];
            $effectiveWind = $weather['avg_effective_wind_kmh'];

            if ($effectiveWind !== null) {
                if ($effectiveWind <= -1.5) {
                    $headwind[] = $attempt;
                } elseif ($effectiveWind >= 1.5) {
                    $tailwind[] = $attempt;
                } else {
                    $neutral[] = $attempt;
                }

                continue;
            }

            if ($weather['wind_label'] === 'Headwind') {
                $headwind[] = $attempt;
            } elseif ($weather['wind_label'] === 'Tailwind') {
                $tailwind[] = $attempt;
            } else {
                $neutral[] = $attempt;
            }
        }

        $headwindBucket = $this->aggregateWindBucket($headwind);
        $neutralBucket = $this->aggregateWindBucket($neutral);
        $tailwindBucket = $this->aggregateWindBucket($tailwind);

        $windSection = null;
        $populatedWindBuckets = ($headwindBucket !== null ? 1 : 0)
            + ($neutralBucket !== null ? 1 : 0)
            + ($tailwindBucket !== null ? 1 : 0);

        if ($populatedWindBuckets >= 1) {
            $windDelta = ($headwindBucket !== null && $tailwindBucket !== null)
                ? round($headwindBucket['avg_duration_seconds'] - $tailwindBucket['avg_duration_seconds'], 1)
                : null;

            $windSection = [
                'headwind' => $headwindBucket,
                'neutral' => $neutralBucket,
                'tailwind' => $tailwindBucket,
                'headwind_vs_tailwind_delta_seconds' => $windDelta,
            ];
        }

        $withTemp = array_values(array_filter(
            $withWeather,
            static fn (array $attempt): bool => $attempt['weather']['temperature_c'] !== null,
        ));

        $tempSection = null;

        if (count($withTemp) >= 2) {
            $temps = array_map(
                static fn (array $attempt): float => $attempt['weather']['temperature_c'],
                $withTemp,
            );
            $minTemp = min($temps);
            $maxTemp = max($temps);

            if (($maxTemp - $minTemp) >= 2.0) {
                $threshold = 15.0;
                $cool = array_values(array_filter(
                    $withTemp,
                    static fn (array $attempt): bool => $attempt['weather']['temperature_c'] < $threshold,
                ));
                $warm = array_values(array_filter(
                    $withTemp,
                    static fn (array $attempt): bool => $attempt['weather']['temperature_c'] >= $threshold,
                ));

                if ($cool === [] || $warm === []) {
                    $threshold = round(RouteMatchingService::median($temps), 1);
                    $cool = array_values(array_filter(
                        $withTemp,
                        static fn (array $attempt): bool => $attempt['weather']['temperature_c'] < $threshold,
                    ));
                    $warm = array_values(array_filter(
                        $withTemp,
                        static fn (array $attempt): bool => $attempt['weather']['temperature_c'] >= $threshold,
                    ));
                }

                if ($cool !== [] && $warm !== []) {
                    $coolBucket = $this->aggregateTempBucket('Cool', $cool);
                    $warmBucket = $this->aggregateTempBucket('Warm', $warm);

                    $tempSection = [
                        'threshold_c' => $threshold,
                        'cool' => $coolBucket,
                        'warm' => $warmBucket,
                        'warm_vs_cool_delta_seconds' => round(
                            $warmBucket['avg_duration_seconds'] - $coolBucket['avg_duration_seconds'],
                            1,
                        ),
                    ];
                }
            }
        }

        return [
            'sample_count' => count($withWeather),
            'wind' => $windSection,
            'temperature' => $tempSection,
        ];
    }

    /**
     * @param  list<RouteAttemptItem>  $items
     * @return WindImpactBucket|null
     */
    private function aggregateWindBucket(array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $count = count($items);
        $avgDuration = array_sum(array_column($items, 'normalized_duration_seconds')) / $count;

        $speeds = array_values(array_filter(
            array_map(static fn (array $item): ?float => $item['avg_speed'], $items),
            static fn (?float $v): bool => $v !== null,
        ));
        $hrs = array_values(array_filter(
            array_map(static fn (array $item): ?int => $item['avg_hr'], $items),
            static fn (?int $v): bool => $v !== null,
        ));

        return [
            'count' => $count,
            'avg_duration_seconds' => round($avgDuration, 1),
            'avg_speed' => $speeds !== [] ? round(array_sum($speeds) / count($speeds), 3) : null,
            'avg_hr' => $hrs !== [] ? round(array_sum($hrs) / count($hrs), 1) : null,
        ];
    }

    /**
     * @param  list<RouteAttemptItem>  $items
     * @return TempImpactBucket
     */
    private function aggregateTempBucket(string $label, array $items): array
    {
        $count = count($items);
        $avgDuration = array_sum(array_column($items, 'normalized_duration_seconds')) / $count;
        $temps = array_map(
            static fn (array $item): float => (float) ($item['weather']['temperature_c'] ?? 0.0),
            $items,
        );
        $speeds = array_values(array_filter(
            array_map(static fn (array $item): ?float => $item['avg_speed'], $items),
            static fn (?float $v): bool => $v !== null,
        ));
        $hrs = array_values(array_filter(
            array_map(static fn (array $item): ?int => $item['avg_hr'], $items),
            static fn (?int $v): bool => $v !== null,
        ));

        return [
            'label' => $label,
            'count' => $count,
            'avg_temp_c' => round(array_sum($temps) / $count, 1),
            'avg_duration_seconds' => round($avgDuration, 1),
            'avg_speed' => $speeds !== [] ? round(array_sum($speeds) / count($speeds), 3) : null,
            'avg_hr' => $hrs !== [] ? round(array_sum($hrs) / count($hrs), 1) : null,
        ];
    }

    /**
     * @return AttemptWeatherSummary
     */
    private function mapWeatherSummary(ActivityWeather $weather): array
    {
        $windLabel = $this->classifyWindLabel($weather);

        return [
            'condition' => $weather->condition,
            'icon' => $weather->icon,
            'temperature_c' => $weather->temperature_c !== null ? round($weather->temperature_c, 1) : null,
            'apparent_temperature_c' => $weather->apparent_temperature_c !== null ? round($weather->apparent_temperature_c, 1) : null,
            'wind_speed_kmh' => $weather->wind_speed_kmh !== null ? round($weather->wind_speed_kmh, 1) : null,
            'wind_direction_compass' => $weather->wind_direction_compass,
            'headwind_pct' => $weather->headwind_pct !== null ? round($weather->headwind_pct, 1) : null,
            'crosswind_pct' => $weather->crosswind_pct !== null ? round($weather->crosswind_pct, 1) : null,
            'tailwind_pct' => $weather->tailwind_pct !== null ? round($weather->tailwind_pct, 1) : null,
            'avg_effective_wind_kmh' => $weather->avg_effective_wind_kmh !== null ? round($weather->avg_effective_wind_kmh, 1) : null,
            'wind_label' => $windLabel,
        ];
    }

    /**
     * @return 'Headwind'|'Tailwind'|'Crosswind'|'Calm'
     */
    private function classifyWindLabel(ActivityWeather $weather): string
    {
        if ($weather->wind_speed_kmh !== null && $weather->wind_speed_kmh < 5.0) {
            return 'Calm';
        }

        if ($weather->avg_effective_wind_kmh !== null) {
            if ($weather->avg_effective_wind_kmh <= -1.5) {
                return 'Headwind';
            }

            if ($weather->avg_effective_wind_kmh >= 1.5) {
                return 'Tailwind';
            }

            if ($weather->crosswind_pct !== null && $weather->crosswind_pct >= 40.0) {
                return 'Crosswind';
            }

            return 'Calm';
        }

        if ($weather->headwind_pct !== null && $weather->tailwind_pct !== null) {
            if ($weather->headwind_pct > $weather->tailwind_pct + 10.0) {
                return 'Headwind';
            }

            if ($weather->tailwind_pct > $weather->headwind_pct + 10.0) {
                return 'Tailwind';
            }

            if ($weather->crosswind_pct !== null && $weather->crosswind_pct >= 40.0) {
                return 'Crosswind';
            }
        }

        return 'Calm';
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function resolveCanonicalPolyline(RouteCluster $cluster): array
    {
        $rawStreamPolyline = $cluster->representativeActivity?->stream?->polyline;

        if (is_array($rawStreamPolyline) && count($rawStreamPolyline) >= 2) {
            $points = [];

            foreach ($rawStreamPolyline as $point) {
                if (
                    is_array($point)
                    && array_key_exists(0, $point)
                    && array_key_exists(1, $point)
                    && is_numeric($point[0])
                    && is_numeric($point[1])
                ) {
                    $points[] = [round((float) $point[0], 5), round((float) $point[1], 5)];
                }
            }

            if (count($points) >= 2) {
                return RouteMatchingService::downsamplePolyline($points, self::CANONICAL_POLYLINE_MAX_POINTS);
            }
        }

        if (is_string($cluster->encoded_polyline) && $cluster->encoded_polyline !== '') {
            $decoded = HeatmapService::decodePolyline($cluster->encoded_polyline);

            if (count($decoded) >= 2) {
                return $decoded;
            }
        }

        return $cluster->waypoints;
    }

    /**
     * Aggregate resolved sport-key counts and latest timestamps across a route cluster's activities.
     *
     * @param  list<Activity>  $activities
     * @return array<string, array{count: int, latest_at: string}>
     */
    private function buildActivitySportCounts(array $activities): array
    {
        $registry = $this->sportRegistry();
        /** @var array<string, array{count: int, latest_at: string}> $counts */
        $counts = [];

        foreach ($activities as $activity) {
            $registry->addSportKeyCount(
                $counts,
                $registry->resolveActivitySportKey($activity),
                $activity->started_at_local->format('Y-m-d H:i:s'),
            );
        }

        return $counts;
    }

    /**
     * Resolve cluster sport metadata from the majority specific discipline across matched attempts,
     * ignoring generic fallbacks (`cycling`, `other`) whenever specific disciplines exist, and falling
     * back to `representativeActivity` / `sport_category` when no activities are loaded.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $activitySportCounts
     * @return array{sport_key: string, accent_hex: string, metric_mode: string, effective_discipline: ?string}
     */
    private function resolveClusterSportMeta(RouteCluster $cluster, array $activitySportCounts = []): array
    {
        $registry = $this->sportRegistry();
        $rep = $cluster->representativeActivity;
        $fallbackSportKey = $rep instanceof Activity
            ? $registry->resolveActivitySportKey($rep)
            : null;
        $sportKey = $registry->resolveDominantSportKey($activitySportCounts, $fallbackSportKey, $cluster->sport_category);
        $entry = $registry->get($sportKey) ?? $registry->get($cluster->sport_category);

        return [
            'sport_key' => $entry['key'] ?? $sportKey,
            'accent_hex' => $entry['accent_hex'] ?? '#10b981',
            'metric_mode' => $entry['metric_mode'] ?? 'pace',
            'effective_discipline' => $entry['discipline'] ?? null,
        ];
    }

    /**
     * @param  list<Activity>  $activities
     */
    private function resolveClusterLocation(RouteCluster $cluster, array $activities): ?string
    {
        $repLocation = $cluster->representativeActivity?->location_name;

        if (is_string($repLocation) && trim($repLocation) !== '') {
            return trim($repLocation);
        }

        foreach ($activities as $activity) {
            if (is_string($activity->location_name) && trim($activity->location_name) !== '') {
                return trim($activity->location_name);
            }
        }

        return null;
    }

    private function effectiveSpeedMps(Activity $activity): ?float
    {
        if ($activity->avg_speed !== null && $activity->avg_speed > 0.0) {
            return round($activity->avg_speed, 3);
        }

        if ($activity->duration_seconds > 0.0 && $activity->distance_meters > 0.0) {
            return round($activity->distance_meters / $activity->duration_seconds, 3);
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string, short_label: string, accent_hex: string}>
     */
    private function outdoorFilterableSports(): array
    {
        $registry = $this->sportRegistry();
        $catalog = $registry->catalog();
        $filtered = [];

        foreach ($registry->filterableSports() as $sport) {
            $entry = $catalog[$sport['key']] ?? null;

            if ($entry !== null && $entry['indoor_only']) {
                continue;
            }

            $filtered[] = $sport;
        }

        return $filtered;
    }

    private function normalizeSport(mixed $value): string
    {
        if (! is_string($value)) {
            return 'all';
        }

        $trimmed = strtolower(trim($value));

        if ($trimmed === '' || $trimmed === 'all') {
            return 'all';
        }

        $registry = $this->sportRegistry();
        $normalized = $registry->normalizeKey($trimmed);

        return $registry->get($normalized) !== null ? $trimmed : 'all';
    }

    private function normalizeSearch(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeSort(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::VALID_SORTS, true)) {
            return $value;
        }

        return 'count';
    }

    /**
     * Median attempt distance used as the common reference for distance-normalised durations.
     *
     * @param  list<Activity>  $activities
     */
    private function referenceDistanceMeters(array $activities): float
    {
        $distances = [];

        foreach ($activities as $activity) {
            if ($activity->distance_meters > 0.0) {
                $distances[] = $activity->distance_meters;
            }
        }

        return $distances !== [] ? RouteMatchingService::median($distances) : 0.0;
    }

    /**
     * Elapsed time scaled to the reference distance, so attempts of slightly different lengths
     * (e.g. a shortcut) are compared by pace rather than raw time.
     */
    private function normalizedDurationSeconds(Activity $activity, float $referenceDistanceMeters): ?float
    {
        if ($activity->duration_seconds <= 0.0) {
            return null;
        }

        if ($activity->distance_meters <= 0.0 || $referenceDistanceMeters <= 0.0) {
            return $activity->duration_seconds;
        }

        return $activity->duration_seconds * ($referenceDistanceMeters / $activity->distance_meters);
    }

    /**
     * Order attempts fastest pace first (attempts without a usable duration last).
     *
     * @param  list<Activity>  $activities
     * @param  array<int, float|null>  $normalizedById
     * @return list<Activity>
     */
    private function rankByPace(array $activities, array $normalizedById): array
    {
        usort($activities, static function (Activity $a, Activity $b) use ($normalizedById): int {
            $cmp = ($normalizedById[$a->id] ?? INF) <=> ($normalizedById[$b->id] ?? INF);

            return $cmp !== 0 ? $cmp : $a->id <=> $b->id;
        });

        return $activities;
    }
}
