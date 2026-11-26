<?php

namespace App\Services\Fitness;

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HeartRateZone;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Services\Activities\ActivityEffortService;
use App\Support\DateWindowResolver;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes unified cross-sport Performance Management Chart (PMC) metrics — CTL, ATL, TSB,
 * ACWR, 7-day ramp rate — with transparent load source priority (TSS > Garmin Load > TRIMP),
 * 84-day EWMA warmup, health overlays, 12-week cross-sport load breakdown, and 7–21 day
 * taper / race-form projections.
 *
 * @phpstan-type DateWindow array{
 *     range: string,
 *     start: ?string,
 *     end: string,
 *     from: ?string,
 *     to: ?string,
 *     prevEnd: ?string,
 *     nextEnd: ?string,
 *     isLatest: bool,
 *     label: string
 * }
 * @phpstan-type SportLoadBreakdown array{
 *     running: float,
 *     cycling: float,
 *     other: float
 * }
 * @phpstan-type DailyTrainingLoadPoint array{
 *     date: string,
 *     load: float,
 *     by_sport: SportLoadBreakdown,
 *     activity_count: int,
 *     ctl: float,
 *     atl: float,
 *     tsb: float,
 *     acwr: ?float,
 *     ramp_rate: float,
 *     hrv: ?int,
 *     resting_hr: ?int,
 *     sleep_score: ?int
 * }
 * @phpstan-type ProjectionPoint array{
 *     date: string,
 *     load: float,
 *     ctl: float,
 *     atl: float,
 *     tsb: float,
 *     acwr: ?float
 * }
 * @phpstan-type StatusBadge array{
 *     key: string,
 *     label: string,
 *     tone: string,
 *     description: string
 * }
 * @phpstan-type LoadSourceMix array{
 *     total: int,
 *     tss_count: int,
 *     garmin_load_count: int,
 *     trimp_count: int,
 *     tss_pct: float,
 *     garmin_load_pct: float,
 *     trimp_pct: float
 * }
 * @phpstan-type WeeklySportLoadBucket array{
 *     week_start: string,
 *     label: string,
 *     running: float,
 *     cycling: float,
 *     other: float,
 *     total: float,
 *     activity_count: int
 * }
 * @phpstan-type RecentLoadSession array{
 *     id: int,
 *     date: string,
 *     started_at_local: string,
 *     name: string,
 *     sport_category: string,
 *     sport_key: string,
 *     sport_label: string,
 *     sport_bucket: 'running'|'cycling'|'other',
 *     duration_seconds: float,
 *     distance_meters: float,
 *     avg_hr: ?int,
 *     load: float,
 *     load_source: 'tss'|'garmin_load'|'trimp'
 * }
 * @phpstan-type TrainingLoadSummary array{
 *     ctl: float,
 *     atl: float,
 *     tsb: float,
 *     acwr: ?float,
 *     ramp_rate_7d: float,
 *     load_7d: float,
 *     load_28d: float,
 *     form_zone: StatusBadge,
 *     acwr_status: StatusBadge,
 *     load_source_mix: LoadSourceMix,
 *     maintenance_daily_load: float,
 *     projected_peak_date: ?string,
 *     projected_peak_dates: array{rest: ?string, maintenance: ?string}
 * }
 * @phpstan-type TrainingLoadPayload array{
 *     filters: array{sport: string, range: string, projection_days: int},
 *     dateWindow: DateWindow,
 *     today: string,
 *     available_sports: list<array{key: string, label: string, short_label: string, accent_hex: string}>,
 *     has_data: bool,
 *     summary: TrainingLoadSummary,
 *     health_availability: array{hrv: bool, resting_hr: bool, sleep_score: bool},
 *     daily: list<DailyTrainingLoadPoint>,
 *     projections: array{rest: list<ProjectionPoint>, maintenance: list<ProjectionPoint>},
 *     weekly_sport_load: list<WeeklySportLoadBucket>,
 *     recent_sessions: list<RecentLoadSession>
 * }
 */
class TrainingLoadService
{
    private const int WARMUP_DAYS = 84;

    private const float CTL_TIME_CONSTANT = 42.0;

    private const float ATL_TIME_CONSTANT = 7.0;

    private const float ACWR_MIN_CTL = 5.0;

    private const float TRIMP_TO_TSS_SCALE = 0.55;

    private const int WEEKLY_BREAKDOWN_WEEKS = 12;

    private const int MAX_ALL_RANGE_DAYS = 1825;

    private const int DEFAULT_PROJECTION_DAYS = 14;

    private const int MIN_PROJECTION_DAYS = 7;

    private const int MAX_PROJECTION_DAYS = 21;

    private const int RECENT_SESSIONS_LIMIT = 10;

    /** @var list<string> */
    private const array VALID_RANGES = ['90d', '180d', '1y', 'all', 'custom'];

    public function __construct(
        private readonly SportRegistry $sports,
        private readonly DateWindowResolver $dateWindows,
        private readonly HealthSourceRegistry $healthSources,
        private readonly ActivityEffortService $effortService,
    ) {}

    /**
     * Build the unified Cross-Sport Fitness & Fatigue (PMC / ACWR) payload.
     *
     * @return TrainingLoadPayload
     */
    public function build(
        ?string $sport = null,
        ?string $range = null,
        ?string $end = null,
        ?string $from = null,
        ?string $to = null,
        int $projectionDays = self::DEFAULT_PROJECTION_DAYS,
    ): array {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $normalizedSport = $this->normalizeSport($sport);
        $clampedProjectionDays = max(self::MIN_PROJECTION_DAYS, min(self::MAX_PROJECTION_DAYS, $projectionDays));

        $window = $this->resolveWindow($range, $end, $from, $to, $today);
        $baseQuery = $this->sports->applyScope(Activity::query(), $normalizedSport);

        [$outputStart, $outputEnd] = $this->resolveOutputBounds($window, $baseQuery, $today, $timezone);
        $availableSports = $this->sports->filterableSports();

        if ($outputStart === null) {
            return $this->emptyPayload(
                sport: $normalizedSport,
                window: $window,
                today: $today,
                projectionDays: $clampedProjectionDays,
                availableSports: $availableSports,
            );
        }

        $warmupStart = $outputStart->subDays(self::WARMUP_DAYS);
        $twelveWeeksStart = $outputEnd->startOfWeek(CarbonInterface::MONDAY)->subWeeks(self::WEEKLY_BREAKDOWN_WEEKS - 1)->startOfDay();
        $queryStart = $warmupStart->lessThan($twelveWeeksStart) ? $warmupStart : $twelveWeeksStart;

        $activities = (clone $baseQuery)
            ->select([
                'id',
                'name',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'is_indoor',
                'started_at_local',
                'duration_seconds',
                'distance_meters',
                'avg_hr',
                'tss',
                'training_load',
                'hr_zone_1_seconds',
                'hr_zone_2_seconds',
                'hr_zone_3_seconds',
                'hr_zone_4_seconds',
                'hr_zone_5_seconds',
            ])
            ->whereBetween('started_at_local', [
                $queryStart->format('Y-m-d').' 00:00:00',
                $outputEnd->format('Y-m-d').' 23:59:59',
            ])
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        [$hrvByDate, $rhrByDate, $sleepScoreByDate] = $this->loadHealthOverlays(
            $outputStart->format('Y-m-d'),
            $outputEnd->format('Y-m-d'),
        );

        if ($activities->isEmpty() && $hrvByDate === [] && $rhrByDate === [] && $sleepScoreByDate === []) {
            return $this->emptyPayload(
                sport: $normalizedSport,
                window: $window,
                today: $today,
                projectionDays: $clampedProjectionDays,
                availableSports: $availableSports,
            );
        }

        [$defaultMaxHr, $defaultRestingHr] = $this->resolveDefaultHeartRateBounds();

        $outputStartStr = $outputStart->format('Y-m-d');
        $outputEndStr = $outputEnd->format('Y-m-d');

        /** @var array<string, float> $dailyLoad */
        $dailyLoad = [];
        /** @var array<string, array{running: float, cycling: float, other: float}> $dailyBySport */
        $dailyBySport = [];
        /** @var array<string, int> $dailyActivityCount */
        $dailyActivityCount = [];

        $sourceCounts = [
            'tss' => 0,
            'garmin_load' => 0,
            'trimp' => 0,
        ];

        /** @var list<RecentLoadSession> $windowSessions */
        $windowSessions = [];
        $hasLoadedActivity = false;
        $catalog = $this->sports->catalog();

        foreach ($activities as $activity) {
            $resolved = $this->resolveActivityLoad($activity, $defaultMaxHr, $defaultRestingHr);

            if ($resolved === null || $resolved['load'] <= 0.0) {
                continue;
            }

            $hasLoadedActivity = true;
            $dateKey = $activity->started_at_local->format('Y-m-d');
            $bucket = $this->sportBucket($activity->sport_category);
            $load = $resolved['load'];
            $source = $resolved['source'];

            $dailyLoad[$dateKey] = ($dailyLoad[$dateKey] ?? 0.0) + $load;
            $dailyActivityCount[$dateKey] = ($dailyActivityCount[$dateKey] ?? 0) + 1;

            if (! isset($dailyBySport[$dateKey])) {
                $dailyBySport[$dateKey] = ['running' => 0.0, 'cycling' => 0.0, 'other' => 0.0];
            }

            $dailyBySport[$dateKey][$bucket] += $load;

            if ($dateKey >= $outputStartStr && $dateKey <= $outputEndStr) {
                $sourceCounts[$source]++;
                $sportKey = $this->sports->resolveActivitySportKey($activity);
                $sportLabel = $catalog[$sportKey]['short_label']
                    ?? $catalog[$sportKey]['label']
                    ?? ucfirst($activity->sport_category);

                $windowSessions[] = [
                    'id' => $activity->id,
                    'date' => $dateKey,
                    'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
                    'name' => $activity->name,
                    'sport_category' => $activity->sport_category,
                    'sport_key' => $sportKey,
                    'sport_label' => $sportLabel,
                    'sport_bucket' => $bucket,
                    'duration_seconds' => round($activity->duration_seconds, 1),
                    'distance_meters' => round($activity->distance_meters, 1),
                    'avg_hr' => $activity->avg_hr,
                    'load' => $load,
                    'load_source' => $source,
                ];
            }
        }

        // Run EWMA from $warmupStart through $outputEnd so CTL, ATL, TSB, and 7d ramp rate are accurate from day 1.
        $ctl = 0.0;
        $atl = 0.0;
        /** @var array<string, float> $ctlByDate */
        $ctlByDate = [];
        /** @var list<DailyTrainingLoadPoint> $daily */
        $daily = [];
        $cursor = $warmupStart;

        while ($cursor->lessThanOrEqualTo($outputEnd)) {
            $dateStr = $cursor->format('Y-m-d');
            $load = $dailyLoad[$dateStr] ?? 0.0;

            // Form (TSB) entering today = yesterday's CTL minus yesterday's ATL
            $tsb = $ctl - $atl;

            $ctl = $ctl + (($load - $ctl) / self::CTL_TIME_CONSTANT);
            $atl = $atl + (($load - $atl) / self::ATL_TIME_CONSTANT);
            $ctlByDate[$dateStr] = $ctl;

            if ($cursor->greaterThanOrEqualTo($outputStart)) {
                $priorDateStr = $cursor->subDays(7)->format('Y-m-d');
                $priorCtl = $ctlByDate[$priorDateStr] ?? 0.0;
                $rampRate = $ctl - $priorCtl;
                $acwr = $ctl >= self::ACWR_MIN_CTL ? round($atl / $ctl, 2) : null;
                $sportBreakdown = $dailyBySport[$dateStr] ?? ['running' => 0.0, 'cycling' => 0.0, 'other' => 0.0];

                $daily[] = [
                    'date' => $dateStr,
                    'load' => round($load, 1),
                    'by_sport' => [
                        'running' => round($sportBreakdown['running'], 1),
                        'cycling' => round($sportBreakdown['cycling'], 1),
                        'other' => round($sportBreakdown['other'], 1),
                    ],
                    'activity_count' => $dailyActivityCount[$dateStr] ?? 0,
                    'ctl' => round($ctl, 1),
                    'atl' => round($atl, 1),
                    'tsb' => round($tsb, 1),
                    'acwr' => $acwr,
                    'ramp_rate' => round($rampRate, 1),
                    'hrv' => $hrvByDate[$dateStr] ?? null,
                    'resting_hr' => $rhrByDate[$dateStr] ?? null,
                    'sleep_score' => $sleepScoreByDate[$dateStr] ?? null,
                ];
            }

            $cursor = $cursor->addDay();
        }

        $load7d = $this->sumDailyLoad($dailyLoad, $outputEnd->subDays(6), $outputEnd);
        $load28d = $this->sumDailyLoad($dailyLoad, $outputEnd->subDays(27), $outputEnd);
        $maintenanceDailyLoad = ($load28d / 28.0) * 0.5;

        $latestDay = $daily !== [] ? $daily[count($daily) - 1] : null;
        $currentCtl = $latestDay['ctl'] ?? 0.0;
        $currentAtl = $latestDay['atl'] ?? 0.0;
        $currentTsb = $latestDay['tsb'] ?? 0.0;
        $currentAcwr = $latestDay['acwr'] ?? null;
        $currentRampRate = $latestDay['ramp_rate'] ?? 0.0;

        $restProjection = $hasLoadedActivity
            ? $this->buildProjection($outputEnd, $ctl, $atl, 0.0, $clampedProjectionDays)
            : [];
        $maintenanceProjection = $hasLoadedActivity
            ? $this->buildProjection($outputEnd, $ctl, $atl, $maintenanceDailyLoad, $clampedProjectionDays)
            : [];

        $restPeakDate = $this->findProjectedPeakDate($restProjection);
        $maintenancePeakDate = $this->findProjectedPeakDate($maintenanceProjection);
        $projectedPeakDate = $restPeakDate ?? $maintenancePeakDate;

        // Sort recent sessions descending by started_at_local (tie-break by id DESC) and cap to 10
        usort($windowSessions, static function (array $a, array $b): int {
            $dateCmp = strcmp($b['started_at_local'], $a['started_at_local']);

            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            return $b['id'] <=> $a['id'];
        });
        $recentSessions = array_slice($windowSessions, 0, self::RECENT_SESSIONS_LIMIT);

        $weeklySportLoad = $hasLoadedActivity
            ? $this->buildWeeklySportLoad($dailyBySport, $dailyActivityCount, $outputEnd)
            : [];

        return [
            'filters' => [
                'sport' => $normalizedSport,
                'range' => $window['range'],
                'projection_days' => $clampedProjectionDays,
            ],
            'dateWindow' => $window,
            'today' => $today->format('Y-m-d'),
            'available_sports' => $availableSports,
            'has_data' => $hasLoadedActivity,
            'summary' => [
                'ctl' => $currentCtl,
                'atl' => $currentAtl,
                'tsb' => $currentTsb,
                'acwr' => $currentAcwr,
                'ramp_rate_7d' => $currentRampRate,
                'load_7d' => round($load7d, 1),
                'load_28d' => round($load28d, 1),
                'form_zone' => $this->classifyFormZone($currentTsb),
                'acwr_status' => $this->classifyAcwrStatus($currentAcwr),
                'load_source_mix' => $this->buildLoadSourceMix($sourceCounts),
                'maintenance_daily_load' => round($maintenanceDailyLoad, 1),
                'projected_peak_date' => $projectedPeakDate,
                'projected_peak_dates' => [
                    'rest' => $restPeakDate,
                    'maintenance' => $maintenancePeakDate,
                ],
            ],
            'health_availability' => [
                'hrv' => $hrvByDate !== [],
                'resting_hr' => $rhrByDate !== [],
                'sleep_score' => $sleepScoreByDate !== [],
            ],
            'daily' => $daily,
            'projections' => [
                'rest' => $restProjection,
                'maintenance' => $maintenanceProjection,
            ],
            'weekly_sport_load' => $weeklySportLoad,
            'recent_sessions' => $recentSessions,
        ];
    }

    /**
     * Resolve normalized load for a single activity using strict priority:
     * 1. TSS (> 0) -> 'tss'
     * 2. Garmin Exercise Load (> 0) -> 'garmin_load'
     * 3. HR-zone / Banister TRIMP scaled to TSS-equivalent points -> 'trimp'
     *
     * @return array{load: float, source: 'tss'|'garmin_load'|'trimp'}|null
     */
    public function resolveActivityLoad(Activity $activity, int $defaultMaxHr = 190, int $defaultRestingHr = 50): ?array
    {
        if ($activity->tss !== null && $activity->tss > 0.0) {
            return [
                'load' => round($activity->tss, 1),
                'source' => 'tss',
            ];
        }

        if ($activity->training_load !== null && $activity->training_load > 0.0) {
            return [
                'load' => round($activity->training_load, 1),
                'source' => 'garmin_load',
            ];
        }

        $rawTrimp = $this->effortService->calculateTrimp($activity, $defaultMaxHr, $defaultRestingHr);

        if ($rawTrimp !== null && $rawTrimp > 0.0) {
            return [
                'load' => round($rawTrimp * self::TRIMP_TO_TSS_SCALE, 1),
                'source' => 'trimp',
            ];
        }

        return null;
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    private function normalizeSport(?string $sport): string
    {
        if ($sport === null || trim($sport) === '') {
            return 'all';
        }

        $normalized = $this->sports->normalizeKey($sport);

        if ($normalized === 'all') {
            return 'all';
        }

        return $this->sports->get($normalized) !== null ? $normalized : 'all';
    }

    /**
     * Resolve date window supporting '90d', '180d', '1y', 'all', and 'custom' (default '180d').
     *
     * @return DateWindow
     */
    private function resolveWindow(
        ?string $range,
        ?string $end,
        ?string $from,
        ?string $to,
        CarbonImmutable $today,
    ): array {
        $timezone = $today->getTimezone();
        $parsedFrom = $this->dateWindows->parseDate($from, $timezone);
        $parsedTo = $this->dateWindows->parseDate($to, $timezone);

        $normalizedRange = $range !== null && in_array($range, self::VALID_RANGES, true)
            ? $range
            : (($parsedFrom !== null || $parsedTo !== null) ? 'custom' : '180d');

        if ($normalizedRange === '180d') {
            return $this->resolve180DayWindow($end, $today);
        }

        return $this->dateWindows->resolveDayWindow(
            $normalizedRange,
            $end,
            $from,
            $to,
            $today,
            ['90d', '1y', 'all', 'custom'],
            '90d',
        );
    }

    /**
     * @return DateWindow
     */
    private function resolve180DayWindow(?string $end, CarbonImmutable $today): array
    {
        $days = 180;
        $todayDay = $today->startOfDay();
        $parsedEnd = $this->dateWindows->parseDate($end, $todayDay->getTimezone());
        $anchorEnd = $parsedEnd !== null && $parsedEnd->lessThan($todayDay) ? $parsedEnd : $todayDay;
        $windowStart = $anchorEnd->subDays($days - 1);
        $isLatest = $anchorEnd->greaterThanOrEqualTo($todayDay);

        $prevEnd = $windowStart->subDay()->format('Y-m-d');
        $nextCandidate = $anchorEnd->addDays($days);
        $nextEnd = $isLatest
            ? null
            : ($nextCandidate->greaterThan($todayDay) ? $todayDay : $nextCandidate)->format('Y-m-d');

        $label = $windowStart->year === $anchorEnd->year
            ? $windowStart->format('M j').' – '.$anchorEnd->format('M j, Y')
            : $windowStart->format('M j, Y').' – '.$anchorEnd->format('M j, Y');

        return [
            'range' => '180d',
            'start' => $windowStart->format('Y-m-d'),
            'end' => $anchorEnd->format('Y-m-d'),
            'from' => null,
            'to' => null,
            'prevEnd' => $prevEnd,
            'nextEnd' => $nextEnd,
            'isLatest' => $isLatest,
            'label' => $label,
        ];
    }

    /**
     * @param  DateWindow  $window
     * @param  Builder<Activity>  $baseQuery
     * @return array{0: ?CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveOutputBounds(
        array $window,
        Builder $baseQuery,
        CarbonImmutable $today,
        string $timezone,
    ): array {
        $outputEnd = $this->dateWindows->parseDate($window['end'], $today->getTimezone()) ?? $today;

        if ($window['start'] !== null) {
            $parsedStart = $this->dateWindows->parseDate($window['start'], $today->getTimezone());

            if ($parsedStart !== null) {
                $minAllowed = $outputEnd->subDays(self::MAX_ALL_RANGE_DAYS);

                if ($parsedStart->lessThan($minAllowed)) {
                    $parsedStart = $minAllowed;
                }

                if ($parsedStart->greaterThan($outputEnd)) {
                    $parsedStart = $outputEnd;
                }

                return [$parsedStart, $outputEnd];
            }
        }

        $earliest = (clone $baseQuery)
            ->where('started_at_local', '<=', $outputEnd->format('Y-m-d').' 23:59:59')
            ->min('started_at_local');

        if (! is_string($earliest) || trim($earliest) === '') {
            return [null, $outputEnd];
        }

        $earliestDay = CarbonImmutable::parse($earliest, $timezone)->startOfDay();
        $minAllowed = $outputEnd->subDays(self::MAX_ALL_RANGE_DAYS);

        if ($earliestDay->lessThan($minAllowed)) {
            $earliestDay = $minAllowed;
        }

        if ($earliestDay->greaterThan($outputEnd)) {
            $earliestDay = $outputEnd;
        }

        return [$earliestDay, $outputEnd];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveDefaultHeartRateBounds(): array
    {
        $defaultZone = $this->healthSources
            ->applyScope(HeartRateZone::query(), 'heart_rate_zones')
            ->where('sport', 'DEFAULT')
            ->first(['max_hr', 'resting_hr']);

        $maxHr = ($defaultZone !== null && $defaultZone->max_hr !== null && $defaultZone->max_hr > 0)
            ? $defaultZone->max_hr
            : 190;
        $restingHr = ($defaultZone !== null && $defaultZone->resting_hr !== null && $defaultZone->resting_hr > 0)
            ? $defaultZone->resting_hr
            : 50;

        return [$maxHr, $restingHr];
    }

    /**
     * Load health overlay signals scoped strictly via HealthSourceRegistry.
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: array<string, int>}
     */
    private function loadHealthOverlays(string $startDate, string $endDate): array
    {
        $hrvRows = $this->healthSources
            ->applyScope(HrvRecord::query(), 'hrv')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get(['calendar_date', 'last_night_avg', 'weekly_avg']);

        /** @var array<string, int> $hrvByDate */
        $hrvByDate = [];

        foreach ($hrvRows as $row) {
            $value = $row->last_night_avg ?? $row->weekly_avg;

            if ($value !== null && $value > 0) {
                $hrvByDate[$row->calendar_date] = $value;
            }
        }

        $rhrRows = $this->healthSources
            ->applyScope(DailyMetric::query(), 'daily_metrics')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->whereNotNull('resting_hr')
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get(['calendar_date', 'resting_hr']);

        /** @var array<string, int> $rhrByDate */
        $rhrByDate = [];

        foreach ($rhrRows as $row) {
            if ($row->resting_hr !== null && $row->resting_hr > 0) {
                $rhrByDate[$row->calendar_date] = $row->resting_hr;
            }
        }

        $sleepRows = $this->healthSources
            ->applyScope(SleepRecord::query(), 'sleep')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->whereNotNull('sleep_score')
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get(['calendar_date', 'sleep_score']);

        /** @var array<string, int> $sleepScoreByDate */
        $sleepScoreByDate = [];

        foreach ($sleepRows as $row) {
            if ($row->sleep_score !== null && $row->sleep_score > 0) {
                $sleepScoreByDate[$row->calendar_date] = $row->sleep_score;
            }
        }

        return [$hrvByDate, $rhrByDate, $sleepScoreByDate];
    }

    /**
     * @return 'running'|'cycling'|'other'
     */
    private function sportBucket(string $sportCategory): string
    {
        return match ($sportCategory) {
            'running' => 'running',
            'biking' => 'cycling',
            default => 'other',
        };
    }

    /**
     * @param  array<string, float>  $dailyLoad
     */
    private function sumDailyLoad(array $dailyLoad, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $total = 0.0;
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $total += $dailyLoad[$cursor->format('Y-m-d')] ?? 0.0;
            $cursor = $cursor->addDay();
        }

        return $total;
    }

    /**
     * Project forward CTL/ATL/TSB/ACWR from the window end date under a constant daily load scenario.
     *
     * @return list<ProjectionPoint>
     */
    private function buildProjection(
        CarbonImmutable $fromDate,
        float $startingCtl,
        float $startingAtl,
        float $dailyScenarioLoad,
        int $days,
    ): array {
        $ctl = $startingCtl;
        $atl = $startingAtl;
        $points = [];

        for ($offset = 1; $offset <= $days; $offset++) {
            $dateStr = $fromDate->addDays($offset)->format('Y-m-d');
            $tsb = $ctl - $atl;

            $ctl = $ctl + (($dailyScenarioLoad - $ctl) / self::CTL_TIME_CONSTANT);
            $atl = $atl + (($dailyScenarioLoad - $atl) / self::ATL_TIME_CONSTANT);
            $acwr = $ctl >= self::ACWR_MIN_CTL ? round($atl / $ctl, 2) : null;

            $points[] = [
                'date' => $dateStr,
                'load' => round($dailyScenarioLoad, 1),
                'ctl' => round($ctl, 1),
                'atl' => round($atl, 1),
                'tsb' => round($tsb, 1),
                'acwr' => $acwr,
            ];
        }

        return $points;
    }

    /**
     * @param  list<ProjectionPoint>  $projection
     */
    private function findProjectedPeakDate(array $projection): ?string
    {
        foreach ($projection as $point) {
            if ($point['tsb'] >= 5.0) {
                return $point['date'];
            }
        }

        return null;
    }

    /**
     * Classify TSB Form Zone.
     *
     * @return StatusBadge
     */
    private function classifyFormZone(float $tsb): array
    {
        if ($tsb < -30.0) {
            return [
                'key' => 'high_risk',
                'label' => 'High Risk',
                'tone' => 'rose',
                'description' => 'Deep fatigue overload — prioritize recovery before hard blocks.',
            ];
        }

        if ($tsb < -10.0) {
            return [
                'key' => 'optimal_training',
                'label' => 'Optimal Training',
                'tone' => 'emerald',
                'description' => 'Productive overload building aerobic fitness.',
            ];
        }

        if ($tsb < 5.0) {
            return [
                'key' => 'grey_zone',
                'label' => 'Grey Zone',
                'tone' => 'neutral',
                'description' => 'Maintenance balance between fitness and fatigue.',
            ];
        }

        if ($tsb <= 25.0) {
            return [
                'key' => 'fresh',
                'label' => 'Fresh',
                'tone' => 'sky',
                'description' => 'Rested and primed for racing or peak performances.',
            ];
        }

        return [
            'key' => 'transition',
            'label' => 'Transition',
            'tone' => 'amber',
            'description' => 'Extended rest — fitness may begin tapering off.',
        ];
    }

    /**
     * Classify Acute:Chronic Workload Ratio (ACWR).
     *
     * @return StatusBadge
     */
    private function classifyAcwrStatus(?float $acwr): array
    {
        if ($acwr === null) {
            return [
                'key' => 'insufficient',
                'label' => 'Building Baseline',
                'tone' => 'neutral',
                'description' => 'Needs chronic fitness (CTL ≥ 5) to evaluate workload ratio.',
            ];
        }

        if ($acwr < 0.8) {
            return [
                'key' => 'undertraining',
                'label' => 'Undertraining',
                'tone' => 'sky',
                'description' => 'Recent load is well below your 6-week chronic baseline.',
            ];
        }

        if ($acwr <= 1.3) {
            return [
                'key' => 'sweet_spot',
                'label' => 'Sweet Spot',
                'tone' => 'emerald',
                'description' => 'Optimal 0.8–1.3 ratio for steady adaptation and low injury risk.',
            ];
        }

        if ($acwr <= 1.5) {
            return [
                'key' => 'caution',
                'label' => 'Caution',
                'tone' => 'amber',
                'description' => 'Load is ramping rapidly above baseline — monitor recovery signals.',
            ];
        }

        return [
            'key' => 'high_risk',
            'label' => 'High Risk',
            'tone' => 'rose',
            'description' => 'Acute spike above 1.5× chronic load elevates overreaching risk.',
        ];
    }

    /**
     * @param  array{tss: int, garmin_load: int, trimp: int}  $counts
     * @return LoadSourceMix
     */
    private function buildLoadSourceMix(array $counts): array
    {
        $total = $counts['tss'] + $counts['garmin_load'] + $counts['trimp'];

        if ($total === 0) {
            return [
                'total' => 0,
                'tss_count' => 0,
                'garmin_load_count' => 0,
                'trimp_count' => 0,
                'tss_pct' => 0.0,
                'garmin_load_pct' => 0.0,
                'trimp_pct' => 0.0,
            ];
        }

        return [
            'total' => $total,
            'tss_count' => $counts['tss'],
            'garmin_load_count' => $counts['garmin_load'],
            'trimp_count' => $counts['trimp'],
            'tss_pct' => round(($counts['tss'] / $total) * 100.0, 1),
            'garmin_load_pct' => round(($counts['garmin_load'] / $total) * 100.0, 1),
            'trimp_pct' => round(($counts['trimp'] / $total) * 100.0, 1),
        ];
    }

    /**
     * Build the last 12 weeks stacked cross-sport load breakdown ending on the week containing $outputEnd.
     *
     * @param  array<string, array{running: float, cycling: float, other: float}>  $dailyBySport
     * @param  array<string, int>  $dailyActivityCount
     * @return list<WeeklySportLoadBucket>
     */
    private function buildWeeklySportLoad(
        array $dailyBySport,
        array $dailyActivityCount,
        CarbonImmutable $outputEnd,
    ): array {
        $endWeekStart = $outputEnd->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $firstWeekStart = $endWeekStart->subWeeks(self::WEEKLY_BREAKDOWN_WEEKS - 1);

        /** @var array<string, array{week_start: string, label: string, running: float, cycling: float, other: float, activity_count: int}> $buckets */
        $buckets = [];

        for ($i = 0; $i < self::WEEKLY_BREAKDOWN_WEEKS; $i++) {
            $weekStart = $firstWeekStart->addWeeks($i);
            $weekKey = $weekStart->format('Y-m-d');
            $buckets[$weekKey] = [
                'week_start' => $weekKey,
                'label' => $weekStart->format('j M'),
                'running' => 0.0,
                'cycling' => 0.0,
                'other' => 0.0,
                'activity_count' => 0,
            ];
        }

        $cursor = $firstWeekStart;

        while ($cursor->lessThanOrEqualTo($outputEnd)) {
            $dateStr = $cursor->format('Y-m-d');
            $weekKey = $cursor->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

            if (isset($buckets[$weekKey])) {
                if (isset($dailyBySport[$dateStr])) {
                    $buckets[$weekKey]['running'] += $dailyBySport[$dateStr]['running'];
                    $buckets[$weekKey]['cycling'] += $dailyBySport[$dateStr]['cycling'];
                    $buckets[$weekKey]['other'] += $dailyBySport[$dateStr]['other'];
                }

                $buckets[$weekKey]['activity_count'] += $dailyActivityCount[$dateStr] ?? 0;
            }

            $cursor = $cursor->addDay();
        }

        $result = [];

        foreach ($buckets as $bucket) {
            $running = round($bucket['running'], 1);
            $cycling = round($bucket['cycling'], 1);
            $other = round($bucket['other'], 1);

            $result[] = [
                'week_start' => $bucket['week_start'],
                'label' => $bucket['label'],
                'running' => $running,
                'cycling' => $cycling,
                'other' => $other,
                'total' => round($running + $cycling + $other, 1),
                'activity_count' => $bucket['activity_count'],
            ];
        }

        return $result;
    }

    /**
     * @param  DateWindow  $window
     * @param  list<array{key: string, label: string, short_label: string, accent_hex: string}>  $availableSports
     * @return TrainingLoadPayload
     */
    private function emptyPayload(
        string $sport,
        array $window,
        CarbonImmutable $today,
        int $projectionDays,
        array $availableSports,
    ): array {
        return [
            'filters' => [
                'sport' => $sport,
                'range' => $window['range'],
                'projection_days' => $projectionDays,
            ],
            'dateWindow' => $window,
            'today' => $today->format('Y-m-d'),
            'available_sports' => $availableSports,
            'has_data' => false,
            'summary' => [
                'ctl' => 0.0,
                'atl' => 0.0,
                'tsb' => 0.0,
                'acwr' => null,
                'ramp_rate_7d' => 0.0,
                'load_7d' => 0.0,
                'load_28d' => 0.0,
                'form_zone' => $this->classifyFormZone(0.0),
                'acwr_status' => $this->classifyAcwrStatus(null),
                'load_source_mix' => $this->buildLoadSourceMix(['tss' => 0, 'garmin_load' => 0, 'trimp' => 0]),
                'maintenance_daily_load' => 0.0,
                'projected_peak_date' => null,
                'projected_peak_dates' => [
                    'rest' => null,
                    'maintenance' => null,
                ],
            ],
            'health_availability' => [
                'hrv' => false,
                'resting_hr' => false,
                'sleep_score' => false,
            ],
            'daily' => [],
            'projections' => [
                'rest' => [],
                'maintenance' => [],
            ],
            'weekly_sport_load' => [],
            'recent_sessions' => [],
        ];
    }
}
