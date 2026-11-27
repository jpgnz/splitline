<?php

namespace App\Services\Segments;

use App\Models\Activity;
use App\Models\ActivityWeather;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Services\Heatmap\HeatmapService;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query and presentation service for Personal Segments & Auto-Detected Climbs index, detail, and Activity integration.
 *
 * @phpstan-type EffortWeatherSummary array{
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
 * @phpstan-type SegmentElevationPoint array{
 *     distance_meters: float,
 *     altitude: float,
 *     grade_pct: ?float
 * }
 * @phpstan-type SegmentEffortLeaderboardItem array{
 *     id: int,
 *     activity_id: int,
 *     activity_name: string,
 *     sport_key: string,
 *     date: string,
 *     started_at_local: string,
 *     year: int,
 *     start_index: int,
 *     end_index: int,
 *     start_distance_meters: float,
 *     end_distance_meters: float,
 *     distance_meters: float,
 *     elapsed_seconds: float,
 *     moving_seconds: ?float,
 *     avg_speed: ?float,
 *     elevation_gain: ?float,
 *     avg_grade_pct: ?float,
 *     vam: ?float,
 *     avg_hr: ?int,
 *     max_hr: ?int,
 *     avg_power: ?float,
 *     w_per_kg: ?float,
 *     avg_cadence: ?float,
 *     rank: int,
 *     display_rank: int,
 *     is_pr: bool,
 *     is_display_pr: bool,
 *     delta_pr_seconds: float,
 *     weather: ?EffortWeatherSummary
 * }
 */
class SegmentQueryService
{
    /**
     * @var list<string>
     */
    private const array VALID_KINDS = ['all', 'climb', 'custom', 'starred'];

    /**
     * @var list<string>
     */
    private const array VALID_CATEGORIES = ['all', 'hc', 'cat1', 'cat2', 'cat3', 'cat4', 'uncategorized'];

    /**
     * @var list<string>
     */
    private const array VALID_SORTS = ['efforts', 'recent', 'climb_score', 'elevation', 'distance'];

    private const int ELEVATION_PROFILE_MAX_POINTS = 120;

    public function __construct(
        private readonly ?SportRegistry $sports = null,
        private readonly SegmentMatchingService $matcher = new SegmentMatchingService,
        private readonly ?AppSettings $appSettings = null,
    ) {}

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    private function appSettings(): AppSettings
    {
        return $this->appSettings ?? app(AppSettings::class);
    }

    /**
     * Build the Segments & Climbs directory payload with filters, KPI summary, and segment cards.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters = []): array
    {
        $kind = $this->normalizeChoice($filters['kind'] ?? null, self::VALID_KINDS, 'all');
        $sport = $this->normalizeSport($filters['sport'] ?? null);
        $category = $this->normalizeChoice($filters['category'] ?? null, self::VALID_CATEGORIES, 'all');
        $search = $this->normalizeSearch($filters['search'] ?? null);
        $sort = $this->normalizeChoice($filters['sort'] ?? null, self::VALID_SORTS, 'efforts');

        $minEfforts = $this->appSettings()->segmentsMinEfforts();

        $query = Segment::query()
            ->visible($minEfforts)
            ->with(['sourceActivity', 'bestEffort']);

        if ($kind === 'climb') {
            $query->climbs();
        } elseif ($kind === 'custom') {
            $query->custom();
        } elseif ($kind === 'starred') {
            $query->starred();
        }

        $registry = $this->sportRegistry();

        if ($sport !== 'all') {
            $entry = $registry->get($sport);

            if ($entry !== null) {
                $query->where('sport_category', $entry['sport_category']);
            }
        }

        if ($category !== 'all' && $kind !== 'custom') {
            $query->where('climb_category', $category);
        }

        if ($search !== null) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search));
            $pattern = '%'.$escaped.'%';

            $query->where(function (Builder $sub) use ($pattern): void {
                $sub
                    ->whereRaw("LOWER(COALESCE(name_override, name)) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(place_label, '')) LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        match ($sort) {
            'recent' => $query
                ->orderByRaw('CASE WHEN last_effort_at IS NULL THEN 1 ELSE 0 END ASC')
                ->orderByDesc('last_effort_at')
                ->orderByDesc('effort_count')
                ->orderByDesc('id'),
            'climb_score' => $query
                ->orderByRaw('CASE WHEN fiets_score IS NULL THEN 1 ELSE 0 END ASC')
                ->orderByDesc('fiets_score')
                ->orderByDesc('elevation_gain')
                ->orderByDesc('id'),
            'elevation' => $query
                ->orderByRaw('CASE WHEN elevation_gain IS NULL THEN 1 ELSE 0 END ASC')
                ->orderByDesc('elevation_gain')
                ->orderByDesc('effort_count')
                ->orderByDesc('id'),
            'distance' => $query
                ->orderByDesc('distance_meters')
                ->orderByDesc('effort_count')
                ->orderByDesc('id'),
            default => $query
                ->orderByDesc('effort_count')
                ->orderByDesc('last_effort_at')
                ->orderByDesc('id'),
        };

        $models = $query->get();
        /** @var list<int> $segmentIds */
        $segmentIds = $models->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $effortSportCountsBySegment = $this->loadSegmentEffortSportCounts($segmentIds);
        $segments = [];

        foreach ($models as $segment) {
            $sportMeta = $this->resolveSegmentSportMeta(
                $segment,
                $effortSportCountsBySegment[$segment->id] ?? [],
            );

            if ($sport !== 'all' && ! $registry->sportKeyMatchesScope($sportMeta['sport_key'], $sport)) {
                continue;
            }

            $best = $segment->bestEffort;

            $segments[] = [
                'id' => $segment->id,
                'name' => $segment->displayName(),
                'auto_name' => $segment->name,
                'name_override' => $segment->name_override,
                'place_label' => $segment->place_label,
                'kind' => $segment->kind,
                'sport_category' => $segment->sport_category,
                'sport_key' => $sportMeta['sport_key'],
                'accent_hex' => $sportMeta['accent_hex'],
                'metric_mode' => $sportMeta['metric_mode'],
                'climb_category' => $segment->climb_category,
                'distance_meters' => round($segment->distance_meters, 1),
                'elevation_gain' => $segment->elevation_gain !== null ? round($segment->elevation_gain, 1) : null,
                'elevation_loss' => $segment->elevation_loss !== null ? round($segment->elevation_loss, 1) : null,
                'avg_grade_pct' => $segment->avg_grade_pct !== null ? round($segment->avg_grade_pct, 2) : null,
                'max_grade_pct' => $segment->max_grade_pct !== null ? round($segment->max_grade_pct, 1) : null,
                'fiets_score' => $segment->fiets_score !== null ? round($segment->fiets_score, 2) : null,
                'encoded_polyline' => $segment->encoded_polyline,
                'is_starred' => $segment->is_starred,
                'effort_count' => $segment->effort_count,
                'best_elapsed_seconds' => $segment->best_elapsed_seconds !== null ? round($segment->best_elapsed_seconds, 1) : null,
                'best_speed_mps' => $best?->avg_speed !== null ? round($best->avg_speed, 3) : null,
                'best_vam' => $best?->vam !== null ? round($best->vam, 1) : null,
                'best_avg_power' => $best?->avg_power !== null ? round($best->avg_power, 1) : null,
                'best_w_per_kg' => $best?->w_per_kg !== null ? round($best->w_per_kg, 2) : null,
                'best_effort_date' => $best?->started_at_local->format('Y-m-d'),
                'last_effort_at' => $segment->last_effort_at?->format('Y-m-d'),
            ];
        }

        $totalSegments = Segment::query()->visible($minEfforts)->count();
        $climbsCount = Segment::query()->visible($minEfforts)->climbs()->count();
        $customCount = Segment::query()->visible($minEfforts)->custom()->count();
        $starredCount = Segment::query()->visible($minEfforts)->starred()->count();
        $totalEfforts = SegmentEffort::query()
            ->whereHas('segment', static function (Builder $segQuery) use ($minEfforts): void {
                $segQuery->visible($minEfforts);
            })
            ->count();

        return [
            'filters' => [
                'kind' => $kind,
                'sport' => $sport,
                'category' => $category,
                'search' => $search ?? '',
                'sort' => $sort,
            ],
            'available_sports' => $this->outdoorFilterableSports(),
            'summary' => [
                'total_segments' => $totalSegments,
                'climbs_count' => $climbsCount,
                'custom_count' => $customCount,
                'starred_count' => $starredCount,
                'total_efforts' => $totalEfforts,
                'unscanned_count' => $this->matcher->unscannedCount(),
                'min_efforts' => $minEfforts,
            ],
            'segments' => $segments,
        ];
    }

    /**
     * Build the full detail payload for a Segment including polyline, elevation profile, KPI summary,
     * chronological progression, available years, available sports/disciplines, and personal leaderboard.
     *
     * Year and sport filters scope `display_rank` / `is_display_pr` and the summary, while each effort's
     * stored `rank` / `is_pr` stay ranked across every discipline and year.
     *
     * @return array<string, mixed>
     */
    public function show(Segment $segment, ?int $year = null, ?string $sport = null): array
    {
        $registry = $this->sportRegistry();
        $segment->load(['sourceActivity', 'bestEffort.activity.stream']);

        $allEfforts = SegmentEffort::query()
            ->with(['activity.weather'])
            ->whereHas('activity')
            ->where('segment_id', $segment->id)
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        /** @var array<int, string> $effortSportKeysById */
        $effortSportKeysById = [];
        /** @var array<string, array{count: int, latest_at: string}> $effortSportCounts */
        $effortSportCounts = [];

        foreach ($allEfforts as $effort) {
            $effortSportKey = $registry->resolveActivitySportKey($effort->activity);
            $effortSportKeysById[$effort->id] = $effortSportKey;
            $registry->addSportKeyCount(
                $effortSportCounts,
                $effortSportKey,
                $effort->started_at_local->format('Y-m-d H:i:s'),
            );
        }

        $availableSports = $registry->buildSportFilterOptions($effortSportCounts);
        $selectedSport = $registry->resolveSportFilterSelection($availableSports, $sport);

        $sportFilteredEfforts = $selectedSport !== null
            ? $allEfforts->filter(static fn (SegmentEffort $e): bool => $registry->sportKeysShareBucket($effortSportKeysById[$e->id], $selectedSport))->values()
            : $allEfforts->values();

        /** @var array<int, true> $yearSet */
        $yearSet = [];

        foreach ($sportFilteredEfforts as $effort) {
            $yearSet[(int) $effort->started_at_local->format('Y')] = true;
        }

        $availableYears = array_keys($yearSet);
        rsort($availableYears);

        $selectedYear = ($year !== null && isset($yearSet[$year])) ? $year : null;

        $filteredEfforts = $selectedYear !== null
            ? $sportFilteredEfforts->filter(static fn (SegmentEffort $e): bool => (int) $e->started_at_local->format('Y') === $selectedYear)->values()
            : $sportFilteredEfforts->values();

        // Compute leaderboard ordering (fastest elapsed_seconds first) for filtered set
        /** @var list<SegmentEffort> $rankedList */
        $rankedList = $filteredEfforts->all();
        usort($rankedList, static function (SegmentEffort $a, SegmentEffort $b): int {
            $cmp = $a->elapsed_seconds <=> $b->elapsed_seconds;

            if ($cmp !== 0) {
                return $cmp;
            }

            $dateCmp = $a->started_at_local <=> $b->started_at_local;

            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return $a->id <=> $b->id;
        });

        /** @var array<int, int> $displayRanksById */
        $displayRanksById = [];

        foreach ($rankedList as $idx => $effort) {
            $displayRanksById[$effort->id] = $idx + 1;
        }

        $prEffort = $rankedList[0] ?? null;
        $prElapsed = $prEffort !== null ? round($prEffort->elapsed_seconds, 1) : null;

        /** @var list<float> $elapsedValues */
        $elapsedValues = [];
        $bestVam = null;
        $bestPower = null;
        $bestWPerKg = null;

        foreach ($filteredEfforts as $effort) {
            $elapsedValues[] = $effort->elapsed_seconds;

            if ($effort->vam !== null && ($bestVam === null || $effort->vam > $bestVam)) {
                $bestVam = round($effort->vam, 1);
            }

            if ($effort->avg_power !== null && ($bestPower === null || $effort->avg_power > $bestPower)) {
                $bestPower = round($effort->avg_power, 1);
            }

            if ($effort->w_per_kg !== null && ($bestWPerKg === null || $effort->w_per_kg > $bestWPerKg)) {
                $bestWPerKg = round($effort->w_per_kg, 2);
            }
        }

        /** @var list<SegmentEffortLeaderboardItem> $progression */
        $progression = [];

        foreach ($filteredEfforts as $effort) {
            $activity = $effort->activity;
            $displayRank = $displayRanksById[$effort->id] ?? 1;
            $deltaPr = $prElapsed !== null ? round($effort->elapsed_seconds - $prElapsed, 1) : 0.0;
            $weatherSummary = $activity->weather instanceof ActivityWeather
                ? $this->mapWeatherSummary($activity->weather)
                : null;

            $progression[] = [
                'id' => $effort->id,
                'activity_id' => $effort->activity_id,
                'activity_name' => $activity->name,
                'sport_key' => $effortSportKeysById[$effort->id],
                'date' => $effort->started_at_local->format('Y-m-d'),
                'started_at_local' => $effort->started_at_local->format('Y-m-d H:i:s'),
                'year' => (int) $effort->started_at_local->format('Y'),
                'start_index' => $effort->start_index,
                'end_index' => $effort->end_index,
                'start_distance_meters' => round($effort->start_distance_meters, 1),
                'end_distance_meters' => round($effort->end_distance_meters, 1),
                'distance_meters' => round($effort->distance_meters, 1),
                'elapsed_seconds' => round($effort->elapsed_seconds, 1),
                'moving_seconds' => $effort->moving_seconds !== null ? round($effort->moving_seconds, 1) : null,
                'avg_speed' => $effort->avg_speed !== null ? round($effort->avg_speed, 3) : null,
                'elevation_gain' => $effort->elevation_gain !== null ? round($effort->elevation_gain, 1) : null,
                'avg_grade_pct' => $effort->avg_grade_pct !== null ? round($effort->avg_grade_pct, 2) : null,
                'vam' => $effort->vam !== null ? round($effort->vam, 1) : null,
                'avg_hr' => $effort->avg_hr,
                'max_hr' => $effort->max_hr,
                'avg_power' => $effort->avg_power !== null ? round($effort->avg_power, 1) : null,
                'w_per_kg' => $effort->w_per_kg !== null ? round($effort->w_per_kg, 2) : null,
                'avg_cadence' => $effort->avg_cadence !== null ? round($effort->avg_cadence, 1) : null,
                'rank' => $effort->rank ?? $displayRank,
                'display_rank' => $displayRank,
                'is_pr' => $effort->is_pr,
                'is_display_pr' => $displayRank === 1,
                'delta_pr_seconds' => $deltaPr,
                'weather' => $weatherSummary,
            ];
        }

        $leaderboard = $progression;
        usort($leaderboard, static fn (array $a, array $b): int => $a['display_rank'] <=> $b['display_rank']);

        $latest = $progression !== [] ? $progression[count($progression) - 1] : null;
        $sportMeta = $this->resolveSegmentSportMeta($segment, $effortSportCounts);
        [$polyline, $elevationProfile] = $this->resolveCanonicalGeometryAndProfile($segment, $prEffort);

        return [
            'segment' => [
                'id' => $segment->id,
                'name' => $segment->displayName(),
                'auto_name' => $segment->name,
                'name_override' => $segment->name_override,
                'place_label' => $segment->place_label,
                'kind' => $segment->kind,
                'sport_category' => $segment->sport_category,
                'sport_key' => $sportMeta['sport_key'],
                'accent_hex' => $sportMeta['accent_hex'],
                'metric_mode' => $sportMeta['metric_mode'],
                'climb_category' => $segment->climb_category,
                'distance_meters' => round($segment->distance_meters, 1),
                'elevation_gain' => $segment->elevation_gain !== null ? round($segment->elevation_gain, 1) : null,
                'elevation_loss' => $segment->elevation_loss !== null ? round($segment->elevation_loss, 1) : null,
                'avg_grade_pct' => $segment->avg_grade_pct !== null ? round($segment->avg_grade_pct, 2) : null,
                'max_grade_pct' => $segment->max_grade_pct !== null ? round($segment->max_grade_pct, 1) : null,
                'fiets_score' => $segment->fiets_score !== null ? round($segment->fiets_score, 2) : null,
                'is_starred' => $segment->is_starred,
                'effort_count' => $segment->effort_count,
                'last_effort_at' => $segment->last_effort_at?->format('Y-m-d'),
                'source_activity_id' => $segment->source_activity_id,
                'source_activity_name' => $segment->sourceActivity?->name,
            ],
            'polyline' => $polyline,
            'elevation_profile' => $elevationProfile,
            'summary' => [
                'effort_count' => count($progression),
                'all_time_effort_count' => $sportFilteredEfforts->count(),
                'pr_elapsed_seconds' => $prElapsed,
                'pr_speed_mps' => $prEffort?->avg_speed !== null ? round($prEffort->avg_speed, 3) : null,
                'pr_vam' => $prEffort?->vam !== null ? round($prEffort->vam, 1) : null,
                'pr_avg_power' => $prEffort?->avg_power !== null ? round($prEffort->avg_power, 1) : null,
                'pr_w_per_kg' => $prEffort?->w_per_kg !== null ? round($prEffort->w_per_kg, 2) : null,
                'pr_activity_id' => $prEffort?->activity_id,
                'pr_date' => $prEffort?->started_at_local->format('Y-m-d'),
                'best_vam' => $bestVam,
                'best_avg_power' => $bestPower,
                'best_w_per_kg' => $bestWPerKg,
                'median_elapsed_seconds' => $elapsedValues !== [] ? round($this->median($elapsedValues), 1) : null,
                'latest_elapsed_seconds' => $latest['elapsed_seconds'] ?? null,
                'latest_date' => $latest['date'] ?? null,
            ],
            'available_years' => $availableYears,
            'selected_year' => $selectedYear,
            'available_sports' => $availableSports,
            'selected_sport' => $selectedSport,
            'progression' => $progression,
            'efforts' => $leaderboard,
        ];
    }

    /**
     * Build the list of matched segment & climb efforts for an Activity detail page.
     *
     * @return list<array<string, mixed>>
     */
    public function forActivity(Activity $activity): array
    {
        $minEfforts = $this->appSettings()->segmentsMinEfforts();

        $efforts = SegmentEffort::query()
            ->with('segment')
            ->where('activity_id', $activity->id)
            ->whereHas('segment', static function (Builder $segQuery) use ($minEfforts): void {
                $segQuery->visible($minEfforts);
            })
            ->orderBy('start_distance_meters')
            ->orderBy('start_index')
            ->get();

        $items = [];

        foreach ($efforts as $effort) {
            $segment = $effort->getRelation('segment');

            if (! $segment instanceof Segment) {
                continue;
            }

            $deltaPr = $segment->best_elapsed_seconds !== null
                ? round($effort->elapsed_seconds - $segment->best_elapsed_seconds, 1)
                : 0.0;

            $items[] = [
                'id' => $effort->id,
                'segment_id' => $segment->id,
                'segment_name' => $segment->displayName(),
                'place_label' => $segment->place_label,
                'kind' => $segment->kind,
                'climb_category' => $segment->climb_category,
                'is_starred' => $segment->is_starred,
                'effort_count' => $segment->effort_count,
                'rank' => $effort->rank,
                'is_pr' => $effort->is_pr,
                'start_index' => $effort->start_index,
                'end_index' => $effort->end_index,
                'start_distance_meters' => round($effort->start_distance_meters, 1),
                'end_distance_meters' => round($effort->end_distance_meters, 1),
                'distance_meters' => round($effort->distance_meters, 1),
                'elapsed_seconds' => round($effort->elapsed_seconds, 1),
                'moving_seconds' => $effort->moving_seconds !== null ? round($effort->moving_seconds, 1) : null,
                'avg_speed' => $effort->avg_speed !== null ? round($effort->avg_speed, 3) : null,
                'elevation_gain' => $effort->elevation_gain !== null ? round($effort->elevation_gain, 1) : null,
                'avg_grade_pct' => $effort->avg_grade_pct !== null ? round($effort->avg_grade_pct, 2) : null,
                'vam' => $effort->vam !== null ? round($effort->vam, 1) : null,
                'avg_hr' => $effort->avg_hr,
                'max_hr' => $effort->max_hr,
                'avg_power' => $effort->avg_power !== null ? round($effort->avg_power, 1) : null,
                'w_per_kg' => $effort->w_per_kg !== null ? round($effort->w_per_kg, 2) : null,
                'avg_cadence' => $effort->avg_cadence !== null ? round($effort->avg_cadence, 1) : null,
                'delta_pr_seconds' => $deltaPr,
            ];
        }

        return $items;
    }

    /**
     * Resolve canonical polyline coordinates and sampled elevation profile for a Segment.
     *
     * @return array{0: list<array{0: float, 1: float}>, 1: list<SegmentElevationPoint>}
     */
    private function resolveCanonicalGeometryAndProfile(Segment $segment, ?SegmentEffort $referenceEffort): array
    {
        $effortForStream = $segment->bestEffort ?? $referenceEffort;
        $polyline = [];
        $profile = [];

        if ($effortForStream !== null) {
            $stream = $effortForStream->activity->stream;

            if ($stream !== null && is_array($stream->series) && $stream->series !== []) {
                $points = $this->matcher->extractGnssPoints($stream->series);
                $slice = [];

                foreach ($points as $pt) {
                    if ($pt['orig_index'] >= $effortForStream->start_index && $pt['orig_index'] <= $effortForStream->end_index) {
                        $slice[] = $pt;
                    }
                }

                if (count($slice) >= 2) {
                    foreach ($slice as $pt) {
                        $polyline[] = [$pt['lat'], $pt['lng']];
                    }

                    $profile = $this->buildElevationProfile($slice);
                }
            }
        }

        if (count($polyline) < 2 && is_string($segment->encoded_polyline) && $segment->encoded_polyline !== '') {
            $decoded = HeatmapService::decodePolyline($segment->encoded_polyline);

            if (count($decoded) >= 2) {
                $polyline = $decoded;
            }
        }

        if (count($polyline) < 2) {
            $polyline = $segment->waypoints;
        }

        return [$polyline, $profile];
    }

    /**
     * Sample up to 120 elevation profile points relative to segment start distance.
     *
     * @param  list<array{
     *     orig_index: int,
     *     time: float,
     *     distance: float,
     *     altitude: ?float,
     *     speed: ?float,
     *     hr: ?int,
     *     power: ?float,
     *     cadence: ?float,
     *     lat: float,
     *     lng: float
     * }>  $slice
     * @return list<SegmentElevationPoint>
     */
    private function buildElevationProfile(array $slice): array
    {
        $withAlt = [];

        foreach ($slice as $pt) {
            if ($pt['altitude'] !== null) {
                $withAlt[] = $pt;
            }
        }

        $count = count($withAlt);

        if ($count < 2) {
            return [];
        }

        $baseDist = $withAlt[0]['distance'];
        $sampled = [];

        if ($count <= self::ELEVATION_PROFILE_MAX_POINTS) {
            $sampled = $withAlt;
        } else {
            $step = ($count - 1) / (self::ELEVATION_PROFILE_MAX_POINTS - 1);

            for ($i = 0; $i < self::ELEVATION_PROFILE_MAX_POINTS; $i++) {
                $idx = min($count - 1, max(0, (int) round($i * $step)));
                $sampled[] = $withAlt[$idx];
            }
        }

        $profile = [];
        $sampledCount = count($sampled);

        for ($i = 0; $i < $sampledCount; $i++) {
            $dist = round(max(0.0, $sampled[$i]['distance'] - $baseDist), 1);
            $alt = round((float) $sampled[$i]['altitude'], 1);
            $grade = null;

            if ($i > 0) {
                $dDist = $sampled[$i]['distance'] - $sampled[$i - 1]['distance'];
                $dAlt = (float) $sampled[$i]['altitude'] - (float) $sampled[$i - 1]['altitude'];

                if ($dDist >= 5.0) {
                    $grade = round(max(-35.0, min(35.0, ($dAlt / $dDist) * 100.0)), 1);
                }
            }

            $profile[] = [
                'distance_meters' => $dist,
                'altitude' => $alt,
                'grade_pct' => $grade,
            ];
        }

        return $profile;
    }

    /**
     * Load effort sport-key counts per segment in chunked grouped queries (avoiding N+1).
     *
     * @param  list<int>  $segmentIds
     * @return array<int, array<string, array{count: int, latest_at: string}>>
     */
    private function loadSegmentEffortSportCounts(array $segmentIds): array
    {
        if ($segmentIds === []) {
            return [];
        }

        $registry = $this->sportRegistry();
        /** @var array<int, array<string, array{count: int, latest_at: string}>> $countsBySegment */
        $countsBySegment = [];

        foreach (array_chunk($segmentIds, 500) as $idChunk) {
            $rows = DB::table('segment_efforts')
                ->join('activities', 'activities.id', '=', 'segment_efforts.activity_id')
                ->whereIn('segment_efforts.segment_id', $idChunk)
                ->whereNull('activities.excluded_at')
                ->selectRaw(
                    'segment_efforts.segment_id, activities.sport_category, COALESCE(activities.discipline_override, activities.sport_discipline) as effective_discipline, activities.is_indoor, COUNT(*) as effort_count, MAX(segment_efforts.started_at_local) as latest_at',
                )
                ->groupByRaw(
                    'segment_efforts.segment_id, activities.sport_category, COALESCE(activities.discipline_override, activities.sport_discipline), activities.is_indoor',
                )
                ->get();

            foreach ($rows as $row) {
                $segmentId = (int) $row->segment_id;
                $sportCategory = is_string($row->sport_category) ? $row->sport_category : 'other';
                $effectiveDiscipline = is_string($row->effective_discipline) ? $row->effective_discipline : null;
                $isIndoor = (bool) $row->is_indoor;
                $count = (int) $row->effort_count;
                $latestAt = is_string($row->latest_at) ? $row->latest_at : '';

                $sportKey = $registry->resolveSportKey($sportCategory, $effectiveDiscipline, $isIndoor);
                $countsBySegment[$segmentId] ??= [];
                $registry->addSportKeyCount($countsBySegment[$segmentId], $sportKey, $latestAt, $count);
            }
        }

        return $countsBySegment;
    }

    /**
     * Resolve segment sport metadata from the majority specific effort discipline,
     * ignoring generic fallbacks (`cycling`, `other`) whenever specific disciplines exist,
     * and falling back to `sourceActivity` / `sport_category` when a segment has no efforts.
     *
     * @param  array<string, array{count: int, latest_at: string}>  $effortSportCounts
     * @return array{sport_key: string, accent_hex: string, metric_mode: string}
     */
    private function resolveSegmentSportMeta(Segment $segment, array $effortSportCounts = []): array
    {
        $registry = $this->sportRegistry();
        $source = $segment->sourceActivity;
        $fallbackSportKey = $source instanceof Activity
            ? $registry->resolveActivitySportKey($source)
            : null;
        $sportKey = $registry->resolveDominantSportKey($effortSportCounts, $fallbackSportKey, $segment->sport_category);
        $entry = $registry->get($sportKey) ?? $registry->get($segment->sport_category);

        return [
            'sport_key' => $entry['key'] ?? $sportKey,
            'accent_hex' => $entry['accent_hex'] ?? '#f59e0b',
            'metric_mode' => $entry['metric_mode'] ?? 'pace',
        ];
    }

    /**
     * @return EffortWeatherSummary
     */
    private function mapWeatherSummary(ActivityWeather $weather): array
    {
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
            'wind_label' => $this->classifyWindLabel($weather),
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

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeChoice(mixed $value, array $allowed, string $default): string
    {
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            if (in_array($trimmed, $allowed, true)) {
                return $trimmed;
            }
        }

        return $default;
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
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }
}
