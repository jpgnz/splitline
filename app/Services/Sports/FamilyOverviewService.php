<?php

namespace App\Services\Sports;

use App\Models\Activity;
use App\Services\Activities\AnnualActivitySummaryService;
use App\Services\Goals\GoalProgressService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * @phpstan-type FamilyDisciplineSummary array{
 *     key: string,
 *     label: string,
 *     short_label: string,
 *     href: string,
 *     color_token: string,
 *     accent_hex: string,
 *     metric_mode: 'pace'|'speed'|'pace_100m'|'duration',
 *     ytd_sessions: int,
 *     ytd_distance_meters: float,
 *     ytd_elevation_gain: float,
 *     ytd_duration_seconds: float,
 *     ytd_load: float,
 *     total_sessions: int,
 *     total_distance_meters: float,
 *     total_elevation_gain: float,
 *     total_duration_seconds: float,
 *     latest_ftp: ?float,
 *     avg_grit: ?float,
 *     avg_flow: ?float,
 *     avg_speed: ?float
 * }
 * @phpstan-type FamilyWeeklyDisciplineBucket array{
 *     distance_meters: float,
 *     elevation_gain: float,
 *     load: float
 * }
 * @phpstan-type FamilyWeeklyVolumePoint array{
 *     week_start: string,
 *     label: string,
 *     total_distance_meters: float,
 *     total_elevation_gain: float,
 *     total_load: float,
 *     by_discipline: array<string, FamilyWeeklyDisciplineBucket>
 * }
 * @phpstan-type ReclassifyActivityItem array{
 *     id: int,
 *     name: string,
 *     started_at_local: string,
 *     activity_type_key: string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: ?float,
 *     avg_speed: ?float,
 *     avg_hr: ?int,
 *     avg_power: ?float,
 *     grit: ?float,
 *     unpaved_pct: ?float,
 *     location_name: ?string
 * }
 */
class FamilyOverviewService
{
    private const int WEEKLY_WINDOW_WEEKS = 24;

    private const int RECLASSIFY_LIMIT = 100;

    public function __construct(
        private readonly SportRegistry $sports,
        private readonly GoalProgressService $goalProgressService,
        private readonly AnnualActivitySummaryService $annualActivitySummaryService = new AnnualActivitySummaryService,
    ) {}

    /**
     * Build the Family Overview Hub payload for a sport family (`running` or `cycling`).
     *
     * @return array{
     *     family: array{
     *         key: string,
     *         label: string,
     *         short_label: string,
     *         href: string,
     *         sport_category: string,
     *         tabs: list<array{key: string, label: string, short_label: string, href: string, slug: string}>,
     *         discipline_options: list<array{value: string, label: string}>
     *     },
     *     disciplineSummaries: list<FamilyDisciplineSummary>,
     *     weeklyVolume: list<FamilyWeeklyVolumePoint>,
     *     annualSummary: array{
     *         today: string,
     *         years: list<int>,
     *         daily: list<array{
     *             date: string,
     *             activities: int,
     *             distance_meters: float,
     *             elevation_gain: float,
     *             duration_seconds: float,
     *             calories: int,
     *             training_load: float
     *         }>
     *     },
     *     goals: list<array<string, mixed>>,
     *     reclassifyInbox: list<ReclassifyActivityItem>,
     *     reclassifyInboxTotal: int
     * }
     */
    public function overview(string $familyKey): array
    {
        $normalizedFamily = $this->sports->normalizeKey($familyKey);

        if (! in_array($normalizedFamily, ['running', 'cycling'], true)) {
            throw new InvalidArgumentException("Unsupported sport family: {$familyKey}");
        }

        $catalog = $this->sports->catalog();
        $familyEntry = $catalog[$normalizedFamily] ?? null;

        if ($familyEntry === null) {
            throw new InvalidArgumentException("Sport family not found in catalog: {$normalizedFamily}");
        }

        $config = $this->sports->resolvedConfig();
        $configuredDisciplines = $config['families'][$normalizedFamily]['disciplines'];
        $enabledDisciplines = array_values(array_filter(
            $configuredDisciplines,
            static fn (string $key): bool => isset($catalog[$key]),
        ));

        if ($enabledDisciplines === []) {
            $fallback = $normalizedFamily === 'running' ? 'road_running' : 'road_cycling';
            $enabledDisciplines = isset($catalog[$fallback]) ? [$fallback] : [];
        }

        $today = CarbonImmutable::now($this->timezone())->startOfDay();
        $sportCategory = $familyEntry['sport_category'];

        $disciplineSummaries = [];

        foreach ($enabledDisciplines as $disciplineKey) {
            $disciplineEntry = $catalog[$disciplineKey];
            $disciplineSummaries[] = $this->buildDisciplineSummary($disciplineKey, $disciplineEntry, $today);
        }

        $reclassifyQuery = $this->reclassifyInboxQuery($sportCategory);
        $reclassifyInbox = $this->buildReclassifyInbox($reclassifyQuery);
        $reclassifyInboxTotal = count($reclassifyInbox) < self::RECLASSIFY_LIMIT
            ? count($reclassifyInbox)
            : (clone $reclassifyQuery)->count();

        return [
            'family' => [
                'key' => $familyEntry['key'],
                'label' => $familyEntry['label'],
                'short_label' => $familyEntry['short_label'],
                'href' => $familyEntry['href'],
                'sport_category' => $sportCategory,
                'tabs' => $this->sports->familyTabs($normalizedFamily),
                'discipline_options' => $this->sports->disciplinesForCategory($sportCategory),
            ],
            'disciplineSummaries' => $disciplineSummaries,
            'weeklyVolume' => $this->buildWeeklyVolume($normalizedFamily, $enabledDisciplines, $today),
            'annualSummary' => $this->annualActivitySummaryService->forQuery(
                $this->sports->applyScope(Activity::query(), $normalizedFamily),
                $today,
            ),
            'goals' => $this->collectFamilyGoals($normalizedFamily, $enabledDisciplines),
            'reclassifyInbox' => $reclassifyInbox,
            'reclassifyInboxTotal' => $reclassifyInboxTotal,
        ];
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
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
     * @param  array{
     *     key: string,
     *     label: string,
     *     short_label: string,
     *     href: string,
     *     color_token: string,
     *     accent_hex: string,
     *     metric_mode: 'pace'|'speed'|'pace_100m'|'duration'
     * }  $entry
     * @return FamilyDisciplineSummary
     */
    private function buildDisciplineSummary(string $disciplineKey, array $entry, CarbonImmutable $today): array
    {
        $baseQuery = $this->sports->applyScope(Activity::query(), $disciplineKey);
        $ytdBounds = $this->yearBounds($today);

        $ytdRow = (clone $baseQuery)
            ->whereBetween('started_at_local', $ytdBounds)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(tss, training_load, 0)), 0) as load')
            ->first();

        $totalRow = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) as sessions')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('AVG(CASE WHEN grit > 0 THEN grit END) as avg_grit')
            ->selectRaw('AVG(CASE WHEN avg_flow > 0 THEN avg_flow END) as avg_flow')
            ->selectRaw('AVG(CASE WHEN avg_speed > 0 THEN avg_speed END) as avg_speed')
            ->first();

        $totalDistance = $this->rowFloat($totalRow, 'distance_meters');
        $totalDuration = $this->rowFloat($totalRow, 'duration_seconds');
        $avgSpeed = ($totalDistance > 0.0 && $totalDuration > 0.0)
            ? round($totalDistance / $totalDuration, 3)
            : $this->rowNullableFloat($totalRow, 'avg_speed', 3);

        return [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'short_label' => $entry['short_label'],
            'href' => $entry['href'],
            'color_token' => $entry['color_token'],
            'accent_hex' => $entry['accent_hex'],
            'metric_mode' => $entry['metric_mode'],
            'ytd_sessions' => $this->rowInt($ytdRow, 'sessions'),
            'ytd_distance_meters' => round($this->rowFloat($ytdRow, 'distance_meters'), 1),
            'ytd_elevation_gain' => round($this->rowFloat($ytdRow, 'elevation_gain'), 1),
            'ytd_duration_seconds' => round($this->rowFloat($ytdRow, 'duration_seconds'), 1),
            'ytd_load' => round($this->rowFloat($ytdRow, 'load'), 1),
            'total_sessions' => $this->rowInt($totalRow, 'sessions'),
            'total_distance_meters' => round($totalDistance, 1),
            'total_elevation_gain' => round($this->rowFloat($totalRow, 'elevation_gain'), 1),
            'total_duration_seconds' => round($totalDuration, 1),
            'latest_ftp' => $this->resolveLatestFtp($baseQuery),
            'avg_grit' => $this->rowNullableFloat($totalRow, 'avg_grit', 1),
            'avg_flow' => $this->rowNullableFloat($totalRow, 'avg_flow', 1),
            'avg_speed' => $avgSpeed,
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
     * @param  list<string>  $enabledDisciplines
     * @return list<FamilyWeeklyVolumePoint>
     */
    private function buildWeeklyVolume(string $familyKey, array $enabledDisciplines, CarbonImmutable $today): array
    {
        $currentWeekStart = $today->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $currentWeekStart->subWeeks(self::WEEKLY_WINDOW_WEEKS - 1);
        $lastWeekEnd = $currentWeekStart->endOfWeek(CarbonInterface::SUNDAY);

        $windowBounds = [
            $firstWeekStart->format('Y-m-d').' 00:00:00',
            $lastWeekEnd->format('Y-m-d').' 23:59:59',
        ];

        /** @var array<string, FamilyWeeklyVolumePoint> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_WINDOW_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $weekKey = $weekStart->format('Y-m-d');

            $byDiscipline = [];

            foreach ($enabledDisciplines as $disciplineKey) {
                $byDiscipline[$disciplineKey] = [
                    'distance_meters' => 0.0,
                    'elevation_gain' => 0.0,
                    'load' => 0.0,
                ];
            }

            $buckets[$weekKey] = [
                'week_start' => $weekKey,
                'label' => $weekStart->format('j M'),
                'total_distance_meters' => 0.0,
                'total_elevation_gain' => 0.0,
                'total_load' => 0.0,
                'by_discipline' => $byDiscipline,
            ];
        }

        $familyActivities = $this->sports->applyScope(Activity::query(), $familyKey)
            ->select(['started_at_local', 'distance_meters', 'elevation_gain', 'tss', 'training_load'])
            ->whereBetween('started_at_local', $windowBounds)
            ->get();

        foreach ($familyActivities as $activity) {
            $weekKey = $activity->started_at_local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

            if (! isset($buckets[$weekKey])) {
                continue;
            }

            $load = max(0.0, $activity->tss ?? $activity->training_load ?? 0.0);
            $buckets[$weekKey]['total_distance_meters'] += $activity->distance_meters;
            $buckets[$weekKey]['total_elevation_gain'] += $activity->elevation_gain ?? 0.0;
            $buckets[$weekKey]['total_load'] += $load;
        }

        foreach ($enabledDisciplines as $disciplineKey) {
            $disciplineActivities = $this->sports->applyScope(Activity::query(), $disciplineKey)
                ->select(['started_at_local', 'distance_meters', 'elevation_gain', 'tss', 'training_load'])
                ->whereBetween('started_at_local', $windowBounds)
                ->get();

            foreach ($disciplineActivities as $activity) {
                $weekKey = $activity->started_at_local->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

                if (! isset($buckets[$weekKey], $buckets[$weekKey]['by_discipline'][$disciplineKey])) {
                    continue;
                }

                $load = max(0.0, $activity->tss ?? $activity->training_load ?? 0.0);
                $buckets[$weekKey]['by_discipline'][$disciplineKey]['distance_meters'] += $activity->distance_meters;
                $buckets[$weekKey]['by_discipline'][$disciplineKey]['elevation_gain'] += $activity->elevation_gain ?? 0.0;
                $buckets[$weekKey]['by_discipline'][$disciplineKey]['load'] += $load;
            }
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $roundedByDiscipline = [];

            foreach ($bucket['by_discipline'] as $disciplineKey => $stats) {
                $roundedByDiscipline[$disciplineKey] = [
                    'distance_meters' => round($stats['distance_meters'], 1),
                    'elevation_gain' => round($stats['elevation_gain'], 1),
                    'load' => round($stats['load'], 1),
                ];
            }

            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'total_distance_meters' => round($bucket['total_distance_meters'], 1),
                'total_elevation_gain' => round($bucket['total_elevation_gain'], 1),
                'total_load' => round($bucket['total_load'], 1),
                'by_discipline' => $roundedByDiscipline,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $enabledDisciplines
     * @return list<array<string, mixed>>
     */
    private function collectFamilyGoals(string $familyKey, array $enabledDisciplines): array
    {
        $sportKeys = [$familyKey, ...$enabledDisciplines];

        if ($familyKey === 'cycling') {
            $sportKeys[] = 'biking';

            if (in_array('road_cycling', $enabledDisciplines, true)) {
                $sportKeys[] = 'road_biking';
            }

            if (in_array('mtb_cycling', $enabledDisciplines, true)) {
                $sportKeys[] = 'mtb_biking';
            }
        }

        $sportKeys = array_values(array_unique($sportKeys));
        $goals = [];
        $seenIds = [];

        foreach ($sportKeys as $sportKey) {
            foreach ($this->goalProgressService->activeGoals(sport: $sportKey) as $goal) {
                $id = $goal['id'];

                if (isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $goals[] = $goal;
            }
        }

        return $goals;
    }

    /**
     * @return Builder<Activity>
     */
    private function reclassifyInboxQuery(string $sportCategory): Builder
    {
        return Activity::query()
            ->where('sport_category', $sportCategory)
            ->whereNull('discipline_override')
            ->where(static function (Builder $query): void {
                $query->whereNull('sport_discipline')
                    ->orWhere('sport_discipline', 'other');
            });
    }

    /**
     * @param  Builder<Activity>  $baseQuery
     * @return list<ReclassifyActivityItem>
     */
    private function buildReclassifyInbox(Builder $baseQuery): array
    {
        $activities = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'started_at_local',
                'activity_type_key',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'avg_speed',
                'avg_hr',
                'avg_power',
                'grit',
                'unpaved_pct',
                'location_name',
            ])
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->limit(self::RECLASSIFY_LIMIT)
            ->get();

        $result = [];

        foreach ($activities as $activity) {
            $result[] = [
                'id' => $activity->id,
                'name' => $activity->name,
                'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
                'activity_type_key' => $activity->activity_type_key,
                'distance_meters' => $activity->distance_meters,
                'duration_seconds' => $activity->duration_seconds,
                'elevation_gain' => $activity->elevation_gain,
                'avg_speed' => $activity->avg_speed,
                'avg_hr' => $activity->avg_hr,
                'avg_power' => $activity->avg_power,
                'grit' => $activity->grit,
                'unpaved_pct' => $activity->unpaved_pct,
                'location_name' => $activity->location_name,
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
