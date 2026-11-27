<?php

namespace App\Services\Recap;

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\RouteCluster;
use App\Models\SegmentEffort;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Services\Activities\AnnualActivitySummaryService;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates a comprehensive "Year in Sport" annual review across activities,
 * personal records, matched routes, segments, weather extremes, and health telemetry.
 *
 * @phpstan-import-type AnnualActivitySummaryPayload from AnnualActivitySummaryService
 *
 * @phpstan-type RecapTotals array{
 *     activities: int,
 *     active_days: int,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float,
 *     calories: int,
 *     training_load: float
 * }
 * @phpstan-type RecapYoy array{
 *     previous_year: int,
 *     is_ytd_comparison: bool,
 *     ytd_cutoff_date: ?string,
 *     has_previous_data: bool,
 *     distance_pct: ?float,
 *     duration_pct: ?float,
 *     elevation_pct: ?float,
 *     activities_pct: ?float
 * }
 * @phpstan-type RecapSportBreakdownItem array{
 *     key: string,
 *     label: string,
 *     accent_hex: string,
 *     count: int,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float,
 *     share_pct: float
 * }
 * @phpstan-type RecapActivityHighlight array{
 *     id: int,
 *     name: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     sport_label: string,
 *     accent_hex: string,
 *     started_at_local: string,
 *     date: string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float
 * }
 * @phpstan-type RecapBiggestWeek array{
 *     week_start: string,
 *     week_end: string,
 *     label: string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float,
 *     count: int
 * }
 * @phpstan-type RecapBiggestMonth array{
 *     month: int,
 *     label: string,
 *     short_label: string,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float,
 *     count: int
 * }
 * @phpstan-type RecapBestEffortItem array{
 *     id: int,
 *     activity_id: int,
 *     activity_name: string,
 *     sport: string,
 *     sport_label: string,
 *     accent_hex: string,
 *     distance_key: string,
 *     distance_label: string,
 *     distance_meters: float,
 *     elapsed_seconds: float,
 *     achieved_at: string,
 *     is_all_time_pr: bool
 * }
 * @phpstan-type RecapRepeatedRoute array{
 *     id: int,
 *     name: string,
 *     place_label: ?string,
 *     sport_category: string,
 *     sport_label: string,
 *     accent_hex: string,
 *     distance_meters: float,
 *     elevation_gain: ?float,
 *     visits_in_year: int,
 *     total_visits: int,
 *     is_loop: bool,
 *     encoded_polyline: ?string
 * }
 * @phpstan-type RecapSegmentPrItem array{
 *     id: int,
 *     segment_id: int,
 *     segment_name: string,
 *     climb_category: ?string,
 *     activity_id: int,
 *     activity_name: string,
 *     elapsed_seconds: float,
 *     distance_meters: float,
 *     elevation_gain: ?float,
 *     date: string
 * }
 * @phpstan-type RecapWeatherExtreme array{
 *     activity_id: int,
 *     activity_name: string,
 *     date: string,
 *     sport_category: string,
 *     accent_hex: string,
 *     condition: string,
 *     temperature_c: ?float,
 *     wind_speed_kmh: ?float,
 *     precipitation_mm: ?float
 * }
 * @phpstan-type RecapMonthlyBucket array{
 *     month: int,
 *     label: string,
 *     full_label: string,
 *     activities: int,
 *     distance_meters: float,
 *     duration_seconds: float,
 *     elevation_gain: float,
 *     training_load: float,
 *     calories: int
 * }
 * @phpstan-type AnnualRecapPayload array{
 *     selected_year: int,
 *     available_years: list<int>,
 *     today: string,
 *     is_current_year: bool,
 *     has_data: bool,
 *     totals: RecapTotals,
 *     yoy: RecapYoy,
 *     sport_breakdown: list<RecapSportBreakdownItem>,
 *     peaks: array{
 *         biggest_week: ?RecapBiggestWeek,
 *         biggest_month: ?RecapBiggestMonth,
 *         longest_activity: ?RecapActivityHighlight,
 *         longest_duration_activity: ?RecapActivityHighlight,
 *         biggest_climb_activity: ?RecapActivityHighlight
 *     },
 *     consistency: array{
 *         longest_daily_streak: array{days: int, start_date: ?string, end_date: ?string},
 *         longest_weekly_streak: array{weeks: int, start_week: ?string, end_week: ?string},
 *         busiest_weekday: ?array{day: string, count: int, share_pct: float},
 *         favorite_time_of_day: ?array{bucket: string, label: string, count: int, share_pct: float}
 *     },
 *     milestones: array{
 *         all_time_prs_count: int,
 *         best_efforts: list<RecapBestEffortItem>,
 *         matched_routes_completed: int,
 *         most_repeated_route: ?RecapRepeatedRoute,
 *         segment_prs_count: int,
 *         segment_efforts_count: int,
 *         segment_prs: list<RecapSegmentPrItem>
 *     },
 *     weather: array{
 *         sample_count: int,
 *         rainy_sessions_count: int,
 *         coldest_activity: ?RecapWeatherExtreme,
 *         hottest_activity: ?RecapWeatherExtreme,
 *         windiest_activity: ?RecapWeatherExtreme,
 *         wettest_activity: ?RecapWeatherExtreme
 *     },
 *     health: array{
 *         has_data: bool,
 *         avg_sleep_hours: ?float,
 *         avg_sleep_score: ?float,
 *         sleep_nights: int,
 *         avg_resting_hr: ?float,
 *         avg_hrv: ?float,
 *         total_steps: int,
 *         avg_daily_steps: ?int,
 *         peak_vo2_max: ?float,
 *         peak_vo2_max_sport: ?string,
 *         peak_vo2_max_date: ?string
 *     },
 *     monthly: list<RecapMonthlyBucket>,
 *     annual_summary: AnnualActivitySummaryPayload
 * }
 */
class AnnualRecapService
{
    /**
     * @var list<string>
     */
    private const array TOP_SPORT_CATEGORIES = [
        'running',
        'biking',
        'hiking',
        'walking',
        'swimming',
        'cardio',
        'other',
    ];

    /**
     * @var array<int, array{short: string, full: string}>
     */
    private const array MONTHS = [
        1 => ['short' => 'Jan', 'full' => 'January'],
        2 => ['short' => 'Feb', 'full' => 'February'],
        3 => ['short' => 'Mar', 'full' => 'March'],
        4 => ['short' => 'Apr', 'full' => 'April'],
        5 => ['short' => 'May', 'full' => 'May'],
        6 => ['short' => 'Jun', 'full' => 'June'],
        7 => ['short' => 'Jul', 'full' => 'July'],
        8 => ['short' => 'Aug', 'full' => 'August'],
        9 => ['short' => 'Sep', 'full' => 'September'],
        10 => ['short' => 'Oct', 'full' => 'October'],
        11 => ['short' => 'Nov', 'full' => 'November'],
        12 => ['short' => 'Dec', 'full' => 'December'],
    ];

    /**
     * @var array<int, string>
     */
    private const array WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
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
     * Preference ordering for selecting the cleanest benchmark distances in the recap highlight grid.
     *
     * @var array<string, int>
     */
    private const array DISTANCE_PRIORITY = [
        'marathon' => 1,
        'half_marathon' => 2,
        '10k' => 3,
        '5k' => 4,
        '100k' => 5,
        '80k' => 6,
        '50k' => 7,
        '40k' => 8,
        '1mi' => 9,
        '1k' => 10,
        '20k' => 11,
        '15k' => 12,
        '10mi' => 13,
        '30k' => 14,
        '2mi' => 15,
        'half_mile' => 16,
        '400m' => 17,
    ];

    public function __construct(
        private readonly AnnualActivitySummaryService $annualActivitySummaryService,
        private readonly SportRegistry $sportRegistry,
        private readonly HealthSourceRegistry $healthSourceRegistry,
    ) {}

    /**
     * Build the full Year in Sport recap payload for the requested (or latest active) year.
     *
     * @return AnnualRecapPayload
     */
    public function build(?int $year = null, ?CarbonImmutable $today = null): array
    {
        $timezone = (string) config('app.display_timezone', 'UTC');
        $resolvedToday = ($today ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $currentYear = $resolvedToday->year;

        $annualSummary = $this->annualActivitySummaryService->forQuery(Activity::query(), $resolvedToday);
        $availableYears = $annualSummary['years'];

        $selectedYear = $this->resolveYear($year, $availableYears, $currentYear);
        $isCurrentYear = $selectedYear === $currentYear;

        $yearStartDate = sprintf('%04d-01-01', $selectedYear);
        $yearEndDate = $isCurrentYear
            ? $resolvedToday->format('Y-m-d')
            : sprintf('%04d-12-31', $selectedYear);

        // Local wall-clock bounds: inclusive start, exclusive end (start of the following day).
        $yearStart = $yearStartDate.' 00:00:00';
        $yearEnd = $this->nextDayStart($yearEndDate);

        $previousYear = $selectedYear - 1;
        $prevStart = sprintf('%04d-01-01 00:00:00', $previousYear);

        if ($isCurrentYear) {
            $daysInPrevMonth = CarbonImmutable::create($previousYear, $resolvedToday->month, 1)->daysInMonth;
            $prevCutoffDay = min($resolvedToday->day, $daysInPrevMonth);
            $prevEndDate = sprintf('%04d-%02d-%02d', $previousYear, $resolvedToday->month, $prevCutoffDay);
            $prevEnd = $this->nextDayStart($prevEndDate);
            $ytdCutoffDate = $resolvedToday->format('Y-m-d');
        } else {
            $prevEnd = sprintf('%04d-01-01 00:00:00', $selectedYear);
            $ytdCutoffDate = null;
        }

        $activities = Activity::query()
            ->select([
                'id',
                'name',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'is_indoor',
                'started_at_local',
                'distance_meters',
                'duration_seconds',
                'elevation_gain',
                'calories',
                'tss',
                'training_load',
            ])
            ->where('started_at_local', '>=', $yearStart)
            ->where('started_at_local', '<', $yearEnd)
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        $previousStats = $this->aggregatePeriodTotals($prevStart, $prevEnd);

        $monthly = $this->initMonthlyBuckets();
        $sportBuckets = [];
        /** @var array<string, array{week_start: string, week_end: string, distance_meters: float, duration_seconds: float, elevation_gain: float, count: int}> $weekBuckets */
        $weekBuckets = [];
        /** @var array<string, true> $activeDatesMap */
        $activeDatesMap = [];
        /** @var array<int, array{count: int, duration: float}> $weekdayBuckets */
        $weekdayBuckets = [];
        /** @var array<string, array{label: string, count: int, duration: float}> $timeOfDayBuckets */
        $timeOfDayBuckets = [];

        $totalActivities = 0;
        $totalDistance = 0.0;
        $totalDuration = 0.0;
        $totalElevation = 0.0;
        $totalCalories = 0;
        $totalLoad = 0.0;

        $longestActivity = null;
        $longestDurationActivity = null;
        $biggestClimbActivity = null;

        foreach ($activities as $activity) {
            $startedAt = $activity->started_at_local;
            $dateStr = $startedAt->format('Y-m-d');
            $monthIndex = $startedAt->month;
            $isoDay = $startedAt->dayOfWeekIso;
            $hour = $startedAt->hour;

            $distance = max(0.0, (float) $activity->distance_meters);
            $duration = max(0.0, (float) $activity->duration_seconds);
            $elevation = max(0.0, (float) ($activity->elevation_gain ?? 0.0));
            $calories = max(0, (int) ($activity->calories ?? 0));
            $load = max(0.0, $activity->tss ?? $activity->training_load ?? 0.0);

            $totalActivities++;
            $totalDistance += $distance;
            $totalDuration += $duration;
            $totalElevation += $elevation;
            $totalCalories += $calories;
            $totalLoad += $load;

            $activeDatesMap[$dateStr] = true;

            // Monthly breakdown
            if (isset($monthly[$monthIndex])) {
                $monthly[$monthIndex]['activities']++;
                $monthly[$monthIndex]['distance_meters'] += $distance;
                $monthly[$monthIndex]['duration_seconds'] += $duration;
                $monthly[$monthIndex]['elevation_gain'] += $elevation;
                $monthly[$monthIndex]['training_load'] += $load;
                $monthly[$monthIndex]['calories'] += $calories;
            }

            // Sport breakdown
            $category = $this->normalizeTopSportCategory($activity->sport_category);

            if (! isset($sportBuckets[$category])) {
                $sportBuckets[$category] = [
                    'count' => 0,
                    'distance_meters' => 0.0,
                    'duration_seconds' => 0.0,
                    'elevation_gain' => 0.0,
                ];
            }

            $sportBuckets[$category]['count']++;
            $sportBuckets[$category]['distance_meters'] += $distance;
            $sportBuckets[$category]['duration_seconds'] += $duration;
            $sportBuckets[$category]['elevation_gain'] += $elevation;

            // Weekly aggregation (Monday–Sunday ISO weeks)
            $weekStartDate = $startedAt->startOfWeek(CarbonInterface::MONDAY);
            $weekKey = $weekStartDate->format('Y-m-d');

            if (! isset($weekBuckets[$weekKey])) {
                $weekBuckets[$weekKey] = [
                    'week_start' => $weekKey,
                    'week_end' => $weekStartDate->addDays(6)->format('Y-m-d'),
                    'distance_meters' => 0.0,
                    'duration_seconds' => 0.0,
                    'elevation_gain' => 0.0,
                    'count' => 0,
                ];
            }

            $weekBuckets[$weekKey]['distance_meters'] += $distance;
            $weekBuckets[$weekKey]['duration_seconds'] += $duration;
            $weekBuckets[$weekKey]['elevation_gain'] += $elevation;
            $weekBuckets[$weekKey]['count']++;

            // Weekday & time of day
            if (! isset($weekdayBuckets[$isoDay])) {
                $weekdayBuckets[$isoDay] = ['count' => 0, 'duration' => 0.0];
            }
            $weekdayBuckets[$isoDay]['count']++;
            $weekdayBuckets[$isoDay]['duration'] += $duration;

            [$todKey, $todLabel] = $this->classifyTimeOfDay($hour);
            if (! isset($timeOfDayBuckets[$todKey])) {
                $timeOfDayBuckets[$todKey] = ['label' => $todLabel, 'count' => 0, 'duration' => 0.0];
            }
            $timeOfDayBuckets[$todKey]['count']++;
            $timeOfDayBuckets[$todKey]['duration'] += $duration;

            // Peak individual activities
            if ($distance > 0.0 && ($longestActivity === null || $distance > $longestActivity['distance_meters'])) {
                $longestActivity = $this->formatActivityHighlight($activity);
            }

            if ($duration > 0.0 && ($longestDurationActivity === null || $duration > $longestDurationActivity['duration_seconds'])) {
                $longestDurationActivity = $this->formatActivityHighlight($activity);
            }

            if ($elevation > 0.0 && ($biggestClimbActivity === null || $elevation > $biggestClimbActivity['elevation_gain'])) {
                $biggestClimbActivity = $this->formatActivityHighlight($activity);
            }
        }

        $totals = [
            'activities' => $totalActivities,
            'active_days' => count($activeDatesMap),
            'distance_meters' => round($totalDistance, 1),
            'duration_seconds' => round($totalDuration, 1),
            'elevation_gain' => round($totalElevation, 1),
            'calories' => $totalCalories,
            'training_load' => round($totalLoad, 1),
        ];

        $yoy = [
            'previous_year' => $previousYear,
            'is_ytd_comparison' => $isCurrentYear,
            'ytd_cutoff_date' => $ytdCutoffDate,
            'has_previous_data' => $previousStats['activities'] > 0,
            'distance_pct' => $this->percentDelta($totals['distance_meters'], $previousStats['distance_meters']),
            'duration_pct' => $this->percentDelta($totals['duration_seconds'], $previousStats['duration_seconds']),
            'elevation_pct' => $this->percentDelta($totals['elevation_gain'], $previousStats['elevation_gain']),
            'activities_pct' => $this->percentDelta((float) $totals['activities'], (float) $previousStats['activities']),
        ];

        $sports = $this->finalizeSportBreakdown($sportBuckets, $totalDuration, $totalActivities);
        $monthlyList = $this->finalizeMonthlyBuckets($monthly);

        $activeDates = array_keys($activeDatesMap);
        sort($activeDates);

        $peaks = [
            'biggest_week' => $this->pickBiggestWeek($weekBuckets, $selectedYear),
            'biggest_month' => $this->pickBiggestMonth($monthlyList),
            'longest_activity' => $longestActivity,
            'longest_duration_activity' => $longestDurationActivity,
            'biggest_climb_activity' => $biggestClimbActivity,
        ];

        $consistency = [
            'longest_daily_streak' => $this->computeLongestDailyStreak($activeDates),
            'longest_weekly_streak' => $this->computeLongestWeeklyStreak(array_keys($weekBuckets)),
            'busiest_weekday' => $this->pickBusiestWeekday($weekdayBuckets, $totalActivities),
            'favorite_time_of_day' => $this->pickFavoriteTimeOfDay($timeOfDayBuckets, $totalActivities),
        ];

        return [
            'selected_year' => $selectedYear,
            'available_years' => $availableYears,
            'today' => $resolvedToday->format('Y-m-d'),
            'is_current_year' => $isCurrentYear,
            'has_data' => $totalActivities > 0,
            'totals' => $totals,
            'yoy' => $yoy,
            'sport_breakdown' => $sports,
            'peaks' => $peaks,
            'consistency' => $consistency,
            'milestones' => $this->buildMilestones($yearStart, $yearEnd),
            'weather' => $this->buildWeatherExtremes($yearStart, $yearEnd),
            'health' => $this->buildHealthHighlights($yearStartDate, $yearEndDate),
            'monthly' => $monthlyList,
            'annual_summary' => $this->scopeAnnualSummaryToYear($annualSummary, $selectedYear),
        ];
    }

    /**
     * Keep only the selected year's daily entries; the recap heatmap never renders other years.
     *
     * @param  AnnualActivitySummaryPayload  $annualSummary
     * @return AnnualActivitySummaryPayload
     */
    private function scopeAnnualSummaryToYear(array $annualSummary, int $selectedYear): array
    {
        $yearPrefix = sprintf('%04d-', $selectedYear);

        $annualSummary['daily'] = array_values(array_filter(
            $annualSummary['daily'],
            static fn (array $entry): bool => str_starts_with($entry['date'], $yearPrefix),
        ));

        return $annualSummary;
    }

    private function nextDayStart(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addDay()->format('Y-m-d').' 00:00:00';
    }

    /**
     * Use the requested year when it lies within [1970, current year]; otherwise fall back to the
     * most recent year with activities (available years are ordered newest first).
     *
     * @param  list<int>  $availableYears
     */
    private function resolveYear(?int $requestedYear, array $availableYears, int $currentYear): int
    {
        if ($requestedYear !== null && $requestedYear >= 1970 && $requestedYear <= $currentYear) {
            return $requestedYear;
        }

        if ($availableYears !== []) {
            return $availableYears[0];
        }

        return $currentYear;
    }

    /**
     * @return array{activities: int, distance_meters: float, duration_seconds: float, elevation_gain: float}
     */
    private function aggregatePeriodTotals(string $start, string $endExclusive): array
    {
        $row = Activity::query()
            ->toBase()
            ->selectRaw('COUNT(*) as activities')
            ->selectRaw('COALESCE(SUM(distance_meters), 0) as distance_meters')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds')
            ->selectRaw('COALESCE(SUM(COALESCE(elevation_gain, 0)), 0) as elevation_gain')
            ->where('started_at_local', '>=', $start)
            ->where('started_at_local', '<', $endExclusive)
            ->first();

        return [
            'activities' => (int) ($row->activities ?? 0),
            'distance_meters' => round(max(0.0, (float) ($row->distance_meters ?? 0.0)), 1),
            'duration_seconds' => round(max(0.0, (float) ($row->duration_seconds ?? 0.0)), 1),
            'elevation_gain' => round(max(0.0, (float) ($row->elevation_gain ?? 0.0)), 1),
        ];
    }

    private function percentDelta(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100.0, 1);
    }

    private function normalizeTopSportCategory(?string $sportCategory): string
    {
        $normalized = strtolower(trim((string) $sportCategory));

        if (in_array($normalized, self::TOP_SPORT_CATEGORIES, true)) {
            return $normalized;
        }

        return 'other';
    }

    /**
     * @return array{label: string, accent_hex: string}
     */
    private function resolveTopSportMeta(string $sportCategory): array
    {
        $entry = $this->sportRegistry->get($sportCategory);

        if ($entry !== null) {
            return [
                'label' => $entry['label'],
                'accent_hex' => $entry['accent_hex'],
            ];
        }

        return [
            'label' => 'Other',
            'accent_hex' => '#71717a',
        ];
    }

    /**
     * @param  array<string, array{count: int, distance_meters: float, duration_seconds: float, elevation_gain: float}>  $buckets
     * @return list<RecapSportBreakdownItem>
     */
    private function finalizeSportBreakdown(array $buckets, float $totalDuration, int $totalActivities): array
    {
        $items = [];

        foreach ($buckets as $key => $bucket) {
            $meta = $this->resolveTopSportMeta($key);

            $sharePct = $totalDuration > 0.0
                ? round(($bucket['duration_seconds'] / $totalDuration) * 100.0, 1)
                : ($totalActivities > 0 ? round(($bucket['count'] / $totalActivities) * 100.0, 1) : 0.0);

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'accent_hex' => $meta['accent_hex'],
                'count' => $bucket['count'],
                'distance_meters' => round($bucket['distance_meters'], 1),
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'share_pct' => $sharePct,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $durationCmp = $b['duration_seconds'] <=> $a['duration_seconds'];

            if ($durationCmp !== 0) {
                return $durationCmp;
            }

            $distanceCmp = $b['distance_meters'] <=> $a['distance_meters'];

            if ($distanceCmp !== 0) {
                return $distanceCmp;
            }

            return $b['count'] <=> $a['count'];
        });

        return $items;
    }

    /**
     * @return RecapActivityHighlight
     */
    private function formatActivityHighlight(Activity $activity): array
    {
        $sportKey = $this->sportRegistry->resolveActivitySportKey($activity);
        $entry = $this->sportRegistry->get($sportKey) ?? $this->sportRegistry->get($activity->sport_category);

        return [
            'id' => $activity->id,
            'name' => $activity->name !== '' ? $activity->name : 'Activity',
            'sport_category' => $activity->sport_category,
            'effective_discipline' => $activity->effectiveDiscipline(),
            'sport_label' => $entry['label'] ?? 'Other',
            'accent_hex' => $entry['accent_hex'] ?? '#71717a',
            'started_at_local' => $activity->started_at_local->toIso8601String(),
            'date' => $activity->started_at_local->format('Y-m-d'),
            'distance_meters' => round(max(0.0, (float) $activity->distance_meters), 1),
            'duration_seconds' => round(max(0.0, (float) $activity->duration_seconds), 1),
            'elevation_gain' => round(max(0.0, (float) ($activity->elevation_gain ?? 0.0)), 1),
        ];
    }

    /**
     * @return array<int, RecapMonthlyBucket>
     */
    private function initMonthlyBuckets(): array
    {
        $buckets = [];

        foreach (self::MONTHS as $month => $labels) {
            $buckets[$month] = [
                'month' => $month,
                'label' => $labels['short'],
                'full_label' => $labels['full'],
                'activities' => 0,
                'distance_meters' => 0.0,
                'duration_seconds' => 0.0,
                'elevation_gain' => 0.0,
                'training_load' => 0.0,
                'calories' => 0,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<int, RecapMonthlyBucket>  $monthly
     * @return list<RecapMonthlyBucket>
     */
    private function finalizeMonthlyBuckets(array $monthly): array
    {
        $result = [];

        foreach ($monthly as $bucket) {
            $result[] = [
                'month' => $bucket['month'],
                'label' => $bucket['label'],
                'full_label' => $bucket['full_label'],
                'activities' => $bucket['activities'],
                'distance_meters' => round($bucket['distance_meters'], 1),
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'training_load' => round($bucket['training_load'], 1),
                'calories' => $bucket['calories'],
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array{week_start: string, week_end: string, distance_meters: float, duration_seconds: float, elevation_gain: float, count: int}>  $weekBuckets
     * @return ?RecapBiggestWeek
     */
    private function pickBiggestWeek(array $weekBuckets, int $selectedYear): ?array
    {
        if ($weekBuckets === []) {
            return null;
        }

        $weeks = array_values($weekBuckets);

        usort($weeks, static function (array $a, array $b): int {
            $distanceCmp = $b['distance_meters'] <=> $a['distance_meters'];

            if ($distanceCmp !== 0) {
                return $distanceCmp;
            }

            $durationCmp = $b['duration_seconds'] <=> $a['duration_seconds'];

            if ($durationCmp !== 0) {
                return $durationCmp;
            }

            $countCmp = $b['count'] <=> $a['count'];

            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp($a['week_start'], $b['week_start']);
        });

        $best = $weeks[0];

        // ISO weeks can straddle New Year; clamp to the selected year since only its activities are counted.
        $weekStart = max($best['week_start'], sprintf('%04d-01-01', $selectedYear));
        $weekEnd = min($best['week_end'], sprintf('%04d-12-31', $selectedYear));
        $startCarbon = CarbonImmutable::parse($weekStart, 'UTC');
        $endCarbon = CarbonImmutable::parse($weekEnd, 'UTC');

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'label' => $startCarbon->format('j M').' – '.$endCarbon->format('j M'),
            'distance_meters' => round($best['distance_meters'], 1),
            'duration_seconds' => round($best['duration_seconds'], 1),
            'elevation_gain' => round($best['elevation_gain'], 1),
            'count' => $best['count'],
        ];
    }

    /**
     * @param  list<RecapMonthlyBucket>  $monthly
     * @return ?RecapBiggestMonth
     */
    private function pickBiggestMonth(array $monthly): ?array
    {
        $activeMonths = array_values(array_filter(
            $monthly,
            static fn (array $bucket): bool => $bucket['activities'] > 0,
        ));

        if ($activeMonths === []) {
            return null;
        }

        usort($activeMonths, static function (array $a, array $b): int {
            $distanceCmp = $b['distance_meters'] <=> $a['distance_meters'];

            if ($distanceCmp !== 0) {
                return $distanceCmp;
            }

            $durationCmp = $b['duration_seconds'] <=> $a['duration_seconds'];

            if ($durationCmp !== 0) {
                return $durationCmp;
            }

            return $b['activities'] <=> $a['activities'];
        });

        $best = $activeMonths[0];

        return [
            'month' => $best['month'],
            'label' => $best['full_label'],
            'short_label' => $best['label'],
            'distance_meters' => $best['distance_meters'],
            'duration_seconds' => $best['duration_seconds'],
            'elevation_gain' => $best['elevation_gain'],
            'count' => $best['activities'],
        ];
    }

    /**
     * @param  list<string>  $sortedDates
     * @return array{days: int, start_date: ?string, end_date: ?string}
     */
    private function computeLongestDailyStreak(array $sortedDates): array
    {
        if ($sortedDates === []) {
            return [
                'days' => 0,
                'start_date' => null,
                'end_date' => null,
            ];
        }

        $bestLen = 1;
        $bestStart = $sortedDates[0];
        $bestEnd = $sortedDates[0];

        $currentLen = 1;
        $currentStart = $sortedDates[0];
        $prevDate = CarbonImmutable::createFromFormat('!Y-m-d', $sortedDates[0], 'UTC');

        $count = count($sortedDates);

        for ($i = 1; $i < $count; $i++) {
            $dateStr = $sortedDates[$i];
            $currDate = CarbonImmutable::createFromFormat('!Y-m-d', $dateStr, 'UTC');

            if ($prevDate !== null && $currDate !== null && $prevDate->addDay()->format('Y-m-d') === $dateStr) {
                $currentLen++;
            } else {
                $currentLen = 1;
                $currentStart = $dateStr;
            }

            if ($currentLen > $bestLen) {
                $bestLen = $currentLen;
                $bestStart = $currentStart;
                $bestEnd = $dateStr;
            }

            $prevDate = $currDate;
        }

        return [
            'days' => $bestLen,
            'start_date' => $bestStart,
            'end_date' => $bestEnd,
        ];
    }

    /**
     * @param  list<string>  $weekStarts
     * @return array{weeks: int, start_week: ?string, end_week: ?string}
     */
    private function computeLongestWeeklyStreak(array $weekStarts): array
    {
        if ($weekStarts === []) {
            return [
                'weeks' => 0,
                'start_week' => null,
                'end_week' => null,
            ];
        }

        sort($weekStarts);

        $bestLen = 1;
        $bestStart = $weekStarts[0];
        $bestEnd = $weekStarts[0];

        $currentLen = 1;
        $currentStart = $weekStarts[0];
        $prevWeek = CarbonImmutable::createFromFormat('!Y-m-d', $weekStarts[0], 'UTC');

        $count = count($weekStarts);

        for ($i = 1; $i < $count; $i++) {
            $weekStr = $weekStarts[$i];
            $currWeek = CarbonImmutable::createFromFormat('!Y-m-d', $weekStr, 'UTC');

            if ($prevWeek !== null && $currWeek !== null && $prevWeek->addDays(7)->format('Y-m-d') === $weekStr) {
                $currentLen++;
            } else {
                $currentLen = 1;
                $currentStart = $weekStr;
            }

            if ($currentLen > $bestLen) {
                $bestLen = $currentLen;
                $bestStart = $currentStart;
                $bestEnd = $weekStr;
            }

            $prevWeek = $currWeek;
        }

        return [
            'weeks' => $bestLen,
            'start_week' => $bestStart,
            'end_week' => $bestEnd,
        ];
    }

    /**
     * @param  array<int, array{count: int, duration: float}>  $weekdayBuckets
     * @return ?array{day: string, count: int, share_pct: float}
     */
    private function pickBusiestWeekday(array $weekdayBuckets, int $totalActivities): ?array
    {
        if ($weekdayBuckets === [] || $totalActivities <= 0) {
            return null;
        }

        $bestIso = null;
        $bestCount = -1;
        $bestDuration = -1.0;

        foreach ($weekdayBuckets as $isoDay => $bucket) {
            if (
                $bucket['count'] > $bestCount
                || ($bucket['count'] === $bestCount && $bucket['duration'] > $bestDuration)
            ) {
                $bestIso = $isoDay;
                $bestCount = $bucket['count'];
                $bestDuration = $bucket['duration'];
            }
        }

        if ($bestIso === null) {
            return null;
        }

        return [
            'day' => self::WEEKDAYS[$bestIso] ?? 'Monday',
            'count' => $bestCount,
            'share_pct' => round(($bestCount / $totalActivities) * 100.0, 1),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function classifyTimeOfDay(int $hour): array
    {
        return match (true) {
            $hour >= 5 && $hour <= 7 => ['early_morning', 'Early Morning (05:00–08:00)'],
            $hour >= 8 && $hour <= 11 => ['morning', 'Morning (08:00–12:00)'],
            $hour >= 12 && $hour <= 16 => ['afternoon', 'Afternoon (12:00–17:00)'],
            $hour >= 17 && $hour <= 20 => ['evening', 'Evening (17:00–21:00)'],
            default => ['night', 'Night (21:00–05:00)'],
        };
    }

    /**
     * @param  array<string, array{label: string, count: int, duration: float}>  $buckets
     * @return ?array{bucket: string, label: string, count: int, share_pct: float}
     */
    private function pickFavoriteTimeOfDay(array $buckets, int $totalActivities): ?array
    {
        if ($buckets === [] || $totalActivities <= 0) {
            return null;
        }

        $bestKey = null;
        $bestLabel = '';
        $bestCount = -1;
        $bestDuration = -1.0;

        foreach ($buckets as $key => $bucket) {
            if (
                $bucket['count'] > $bestCount
                || ($bucket['count'] === $bestCount && $bucket['duration'] > $bestDuration)
            ) {
                $bestKey = $key;
                $bestLabel = $bucket['label'];
                $bestCount = $bucket['count'];
                $bestDuration = $bucket['duration'];
            }
        }

        if ($bestKey === null) {
            return null;
        }

        return [
            'bucket' => $bestKey,
            'label' => $bestLabel,
            'count' => $bestCount,
            'share_pct' => round(($bestCount / $totalActivities) * 100.0, 1),
        ];
    }

    /**
     * Build Best Efforts, Matched Routes, and Segment PR milestones for the selected year.
     *
     * @return array{
     *     all_time_prs_count: int,
     *     best_efforts: list<RecapBestEffortItem>,
     *     matched_routes_completed: int,
     *     most_repeated_route: ?RecapRepeatedRoute,
     *     segment_prs_count: int,
     *     segment_efforts_count: int,
     *     segment_prs: list<RecapSegmentPrItem>
     * }
     */
    private function buildMilestones(string $yearStart, string $yearEnd): array
    {
        [$bestEfforts, $allTimePrsCount] = $this->buildBestEfforts($yearStart, $yearEnd);
        [$matchedRoutesCompleted, $mostRepeatedRoute] = $this->buildRouteMilestones($yearStart, $yearEnd);
        [$segmentPrsCount, $segmentEffortsCount, $segmentPrs] = $this->buildSegmentMilestones($yearStart, $yearEnd);

        return [
            'all_time_prs_count' => $allTimePrsCount,
            'best_efforts' => $bestEfforts,
            'matched_routes_completed' => $matchedRoutesCompleted,
            'most_repeated_route' => $mostRepeatedRoute,
            'segment_prs_count' => $segmentPrsCount,
            'segment_efforts_count' => $segmentEffortsCount,
            'segment_prs' => $segmentPrs,
        ];
    }

    /**
     * Pick the fastest effort per (sport, distance) within the year and flag it as an all-time PR when it
     * beat every effort achieved before it ("PR at the time"), so later improvements never rewrite history.
     *
     * @return array{0: list<RecapBestEffortItem>, 1: int}
     */
    private function buildBestEfforts(string $yearStart, string $yearEnd): array
    {
        $yearEfforts = BestEffort::query()
            ->with(['activity:id,name,sport_category,sport_discipline,discipline_override,started_at_local'])
            ->where('is_excluded', false)
            ->where('achieved_at', '>=', $yearStart)
            ->where('achieved_at', '<', $yearEnd)
            ->orderBy('achieved_at')
            ->orderBy('id')
            ->get();

        if ($yearEfforts->isEmpty()) {
            return [[], 0];
        }

        // Fastest effective_seconds per (sport, distance_key) achieved before the selected year.
        $priorRows = BestEffort::query()
            ->toBase()
            ->selectRaw('sport, distance_key, MIN(COALESCE(override_seconds, elapsed_seconds)) as min_seconds')
            ->where('is_excluded', false)
            ->where('achieved_at', '<', $yearStart)
            ->whereRaw('COALESCE(override_seconds, elapsed_seconds) > 0')
            ->groupBy('sport', 'distance_key')
            ->get();

        /** @var array<string, float> $priorMinByPair */
        $priorMinByPair = [];

        foreach ($priorRows as $row) {
            if (! is_string($row->sport) || ! is_string($row->distance_key) || ! is_numeric($row->min_seconds)) {
                continue;
            }

            $priorMinByPair[$row->sport.'|'.$row->distance_key] = (float) $row->min_seconds;
        }

        // Efforts are chronological and ties keep the earliest, so every earlier in-year effort of the
        // same pair is strictly slower than the year's fastest one.
        /** @var array<string, BestEffort> $fastestInYearByPair */
        $fastestInYearByPair = [];

        foreach ($yearEfforts as $effort) {
            $pairKey = $effort->sport.'|'.$effort->distance_key;
            $seconds = $effort->effectiveSeconds();

            if ($seconds <= 0.0) {
                continue;
            }

            if (
                ! isset($fastestInYearByPair[$pairKey])
                || $seconds < $fastestInYearByPair[$pairKey]->effectiveSeconds()
            ) {
                $fastestInYearByPair[$pairKey] = $effort;
            }
        }

        $items = [];
        $allTimePrsCount = 0;

        foreach ($fastestInYearByPair as $pairKey => $effort) {
            $seconds = $effort->effectiveSeconds();
            $priorMin = $priorMinByPair[$pairKey] ?? null;
            $isAllTimePr = $priorMin === null || $seconds < $priorMin;

            if ($isAllTimePr) {
                $allTimePrsCount++;
            }

            $sportEntry = $this->sportRegistry->get($effort->sport)
                ?? $this->sportRegistry->get($effort->activity->sport_category);
            $distanceLabel = self::DISTANCE_LABELS[$effort->distance_key]
                ?? strtoupper(str_replace('_', ' ', $effort->distance_key));

            $items[] = [
                'id' => $effort->id,
                'activity_id' => $effort->activity_id,
                'activity_name' => $effort->activity->name !== '' ? $effort->activity->name : 'Activity',
                'sport' => $effort->sport,
                'sport_label' => $sportEntry['label'] ?? ucfirst(str_replace('_', ' ', $effort->sport)),
                'accent_hex' => $sportEntry['accent_hex'] ?? '#10b981',
                'distance_key' => $effort->distance_key,
                'distance_label' => $distanceLabel,
                'distance_meters' => round((float) $effort->distance_meters, 1),
                'elapsed_seconds' => round($seconds, 1),
                'achieved_at' => $effort->achieved_at->format('Y-m-d'),
                'is_all_time_pr' => $isAllTimePr,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['is_all_time_pr'] !== $b['is_all_time_pr']) {
                return $a['is_all_time_pr'] ? -1 : 1;
            }

            $prioA = self::DISTANCE_PRIORITY[$a['distance_key']] ?? 99;
            $prioB = self::DISTANCE_PRIORITY[$b['distance_key']] ?? 99;

            if ($prioA !== $prioB) {
                return $prioA <=> $prioB;
            }

            return $b['distance_meters'] <=> $a['distance_meters'];
        });

        return [array_slice($items, 0, 10), $allTimePrsCount];
    }

    /**
     * @return array{0: int, 1: ?RecapRepeatedRoute}
     */
    private function buildRouteMilestones(string $yearStart, string $yearEnd): array
    {
        $rows = ActivityRouteFingerprint::query()
            ->toBase()
            ->join('activities', 'activities.id', '=', 'activity_route_fingerprints.activity_id')
            ->join('route_clusters', 'route_clusters.id', '=', 'activity_route_fingerprints.route_cluster_id')
            ->selectRaw('activity_route_fingerprints.route_cluster_id as cluster_id, COUNT(*) as visits_in_year')
            ->where('activity_route_fingerprints.is_unlinked', false)
            ->whereNotNull('activity_route_fingerprints.route_cluster_id')
            ->where('route_clusters.activity_count', '>=', 2)
            ->where('activities.started_at_local', '>=', $yearStart)
            ->where('activities.started_at_local', '<', $yearEnd)
            ->groupBy('activity_route_fingerprints.route_cluster_id')
            ->orderByDesc('visits_in_year')
            ->get();

        if ($rows->isEmpty()) {
            return [0, null];
        }

        $matchedRoutesCompleted = $rows->count();

        $clusterIds = $rows->map(static fn (object $r): int => (int) $r->cluster_id)->all();
        /** @var Collection<int, RouteCluster> $clusters */
        $clusters = RouteCluster::query()
            ->whereIn('id', $clusterIds)
            ->get()
            ->keyBy('id');

        $candidates = [];

        foreach ($rows as $row) {
            $clusterId = (int) $row->cluster_id;
            $cluster = $clusters->get($clusterId);

            if ($cluster === null) {
                continue;
            }

            $candidates[] = [
                'cluster' => $cluster,
                'visits_in_year' => (int) $row->visits_in_year,
            ];
        }

        if ($candidates === []) {
            return [$matchedRoutesCompleted, null];
        }

        usort($candidates, static function (array $a, array $b): int {
            $visitCmp = $b['visits_in_year'] <=> $a['visits_in_year'];

            if ($visitCmp !== 0) {
                return $visitCmp;
            }

            $distCmp = $b['cluster']->distance_meters <=> $a['cluster']->distance_meters;

            if ($distCmp !== 0) {
                return $distCmp;
            }

            return $a['cluster']->id <=> $b['cluster']->id;
        });

        /** @var RouteCluster $topCluster */
        $topCluster = $candidates[0]['cluster'];
        $topMeta = $this->resolveTopSportMeta($this->normalizeTopSportCategory($topCluster->sport_category));

        $mostRepeatedRoute = [
            'id' => $topCluster->id,
            'name' => $topCluster->displayName(),
            'place_label' => $topCluster->place_label,
            'sport_category' => $topCluster->sport_category,
            'sport_label' => $topMeta['label'],
            'accent_hex' => $topMeta['accent_hex'],
            'distance_meters' => round((float) $topCluster->distance_meters, 1),
            'elevation_gain' => $topCluster->elevation_gain !== null ? round((float) $topCluster->elevation_gain, 1) : null,
            'visits_in_year' => $candidates[0]['visits_in_year'],
            'total_visits' => $topCluster->activity_count,
            'is_loop' => $topCluster->is_loop,
            'encoded_polyline' => $topCluster->encoded_polyline,
        ];

        return [$matchedRoutesCompleted, $mostRepeatedRoute];
    }

    /**
     * Count segments PR'd during the year using "PR at the time" semantics: an effort is a PR when it beat
     * every earlier effort on that segment. The stored `is_pr` flag only marks the current all-time best,
     * so it would erase PRs from past years once they were beaten.
     *
     * @return array{0: int, 1: int, 2: list<RecapSegmentPrItem>}
     */
    private function buildSegmentMilestones(string $yearStart, string $yearEnd): array
    {
        $yearEffortsQuery = SegmentEffort::query()
            ->where('started_at_local', '>=', $yearStart)
            ->where('started_at_local', '<', $yearEnd);

        $yearEfforts = (clone $yearEffortsQuery)
            ->select(['id', 'segment_id', 'activity_id', 'started_at_local', 'elapsed_seconds', 'distance_meters', 'elevation_gain'])
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        $totalEfforts = $yearEfforts->count();

        if ($totalEfforts === 0) {
            return [0, 0, []];
        }

        /** @var array<int, float> $bestSecondsBySegment */
        $bestSecondsBySegment = SegmentEffort::query()
            ->toBase()
            ->selectRaw('segment_id, MIN(elapsed_seconds) as best_seconds')
            ->where('started_at_local', '<', $yearStart)
            ->where('elapsed_seconds', '>', 0)
            ->whereIn('segment_id', (clone $yearEffortsQuery)->select('segment_id'))
            ->groupBy('segment_id')
            ->pluck('best_seconds', 'segment_id')
            ->map(static fn (mixed $seconds): float => (float) $seconds)
            ->all();

        /** @var array<int, SegmentEffort> $latestPrBySegment */
        $latestPrBySegment = [];

        foreach ($yearEfforts as $effort) {
            $seconds = (float) $effort->elapsed_seconds;

            if ($seconds <= 0.0) {
                continue;
            }

            $priorBest = $bestSecondsBySegment[$effort->segment_id] ?? null;

            if ($priorBest === null || $seconds < $priorBest) {
                $latestPrBySegment[$effort->segment_id] = $effort;
                $bestSecondsBySegment[$effort->segment_id] = $seconds;
            }
        }

        $prCount = count($latestPrBySegment);

        if ($prCount === 0) {
            return [0, $totalEfforts, []];
        }

        $prEfforts = array_values($latestPrBySegment);

        usort($prEfforts, static function (SegmentEffort $a, SegmentEffort $b): int {
            $gainCmp = ($b->elevation_gain ?? -INF) <=> ($a->elevation_gain ?? -INF);

            if ($gainCmp !== 0) {
                return $gainCmp;
            }

            return $b->distance_meters <=> $a->distance_meters;
        });

        $topPrEfforts = (new EloquentCollection(array_slice($prEfforts, 0, 5)))
            ->load(['segment:id,name,name_override,climb_category', 'activity:id,name']);

        $items = [];

        foreach ($topPrEfforts as $effort) {
            $items[] = [
                'id' => $effort->id,
                'segment_id' => $effort->segment_id,
                'segment_name' => $effort->segment->displayName(),
                'climb_category' => $effort->segment->climb_category,
                'activity_id' => $effort->activity_id,
                'activity_name' => $effort->activity->name !== '' ? $effort->activity->name : 'Activity',
                'elapsed_seconds' => round((float) $effort->elapsed_seconds, 1),
                'distance_meters' => round((float) $effort->distance_meters, 1),
                'elevation_gain' => $effort->elevation_gain !== null ? round((float) $effort->elevation_gain, 1) : null,
                'date' => $effort->started_at_local->format('Y-m-d'),
            ];
        }

        return [$prCount, $totalEfforts, $items];
    }

    /**
     * Compute weather extremes and rainy session count for activities in the selected year.
     *
     * @return array{
     *     sample_count: int,
     *     rainy_sessions_count: int,
     *     coldest_activity: ?RecapWeatherExtreme,
     *     hottest_activity: ?RecapWeatherExtreme,
     *     windiest_activity: ?RecapWeatherExtreme,
     *     wettest_activity: ?RecapWeatherExtreme
     * }
     */
    private function buildWeatherExtremes(string $yearStart, string $yearEnd): array
    {
        $rows = ActivityWeather::query()
            ->toBase()
            ->join('activities', 'activities.id', '=', 'activity_weathers.activity_id')
            ->select([
                'activity_weathers.activity_id',
                'activity_weathers.condition',
                'activity_weathers.temperature_c',
                'activity_weathers.wind_speed_kmh',
                'activity_weathers.precipitation_mm',
                'activities.name as activity_name',
                'activities.sport_category',
                'activities.started_at_local',
            ])
            ->where('activities.started_at_local', '>=', $yearStart)
            ->where('activities.started_at_local', '<', $yearEnd)
            ->orderBy('activities.started_at_local')
            ->get();

        $sampleCount = $rows->count();

        if ($sampleCount === 0) {
            return [
                'sample_count' => 0,
                'rainy_sessions_count' => 0,
                'coldest_activity' => null,
                'hottest_activity' => null,
                'windiest_activity' => null,
                'wettest_activity' => null,
            ];
        }

        $rainySessions = 0;
        $coldest = null;
        $hottest = null;
        $windiest = null;
        $wettest = null;

        foreach ($rows as $row) {
            $activityId = is_numeric($row->activity_id ?? null) ? (int) $row->activity_id : 0;
            $activityName = is_string($row->activity_name ?? null) ? $row->activity_name : '';
            $sportCategory = is_string($row->sport_category ?? null) ? $row->sport_category : 'other';
            $startedAtLocal = is_string($row->started_at_local ?? null) ? $row->started_at_local : '';
            $condition = is_string($row->condition ?? null) ? $row->condition : '';

            $temp = is_numeric($row->temperature_c ?? null) ? (float) $row->temperature_c : null;
            $wind = is_numeric($row->wind_speed_kmh ?? null) ? (float) $row->wind_speed_kmh : null;
            $precip = is_numeric($row->precipitation_mm ?? null) ? (float) $row->precipitation_mm : null;

            if ($precip !== null && $precip > 0.0) {
                $rainySessions++;
            }

            if ($temp !== null && ($coldest === null || $temp < $coldest['temperature_c'])) {
                $coldest = $this->formatWeatherExtreme($activityId, $activityName, $startedAtLocal, $sportCategory, $condition, $temp, $wind, $precip);
            }

            if ($temp !== null && ($hottest === null || $temp > $hottest['temperature_c'])) {
                $hottest = $this->formatWeatherExtreme($activityId, $activityName, $startedAtLocal, $sportCategory, $condition, $temp, $wind, $precip);
            }

            if ($wind !== null && $wind > 0.0 && ($windiest === null || $wind > $windiest['wind_speed_kmh'])) {
                $windiest = $this->formatWeatherExtreme($activityId, $activityName, $startedAtLocal, $sportCategory, $condition, $temp, $wind, $precip);
            }

            if ($precip !== null && $precip > 0.0 && ($wettest === null || $precip > $wettest['precipitation_mm'])) {
                $wettest = $this->formatWeatherExtreme($activityId, $activityName, $startedAtLocal, $sportCategory, $condition, $temp, $wind, $precip);
            }
        }

        return [
            'sample_count' => $sampleCount,
            'rainy_sessions_count' => $rainySessions,
            'coldest_activity' => $coldest,
            'hottest_activity' => $hottest,
            'windiest_activity' => $windiest,
            'wettest_activity' => $wettest,
        ];
    }

    /**
     * @return RecapWeatherExtreme
     */
    private function formatWeatherExtreme(
        int $activityId,
        string $activityName,
        string $startedAtLocal,
        string $sportCategory,
        string $condition,
        ?float $temp,
        ?float $wind,
        ?float $precip,
    ): array {
        $category = $this->normalizeTopSportCategory($sportCategory);
        $meta = $this->resolveTopSportMeta($category);
        $startedAt = substr($startedAtLocal, 0, 10);

        return [
            'activity_id' => $activityId,
            'activity_name' => $activityName !== '' ? $activityName : 'Activity',
            'date' => $startedAt,
            'sport_category' => $category,
            'accent_hex' => $meta['accent_hex'],
            'condition' => $condition !== '' ? $condition : 'Unknown',
            'temperature_c' => $temp !== null ? round($temp, 1) : null,
            'wind_speed_kmh' => $wind !== null ? round($wind, 1) : null,
            'precipitation_mm' => $precip !== null ? round($precip, 1) : null,
        ];
    }

    /**
     * Compute annual health averages and peak VO2 Max, strictly scoped via HealthSourceRegistry.
     *
     * @return array{
     *     has_data: bool,
     *     avg_sleep_hours: ?float,
     *     avg_sleep_score: ?float,
     *     sleep_nights: int,
     *     avg_resting_hr: ?float,
     *     avg_hrv: ?float,
     *     total_steps: int,
     *     avg_daily_steps: ?int,
     *     peak_vo2_max: ?float,
     *     peak_vo2_max_sport: ?string,
     *     peak_vo2_max_date: ?string
     * }
     */
    private function buildHealthHighlights(string $startDate, string $endDate): array
    {
        // Sleep
        $sleepAgg = $this->healthSourceRegistry
            ->applyScope(SleepRecord::query(), 'sleep')
            ->toBase()
            ->selectRaw('AVG(CASE WHEN sleep_seconds > 0 THEN sleep_seconds END) as avg_sleep_seconds')
            ->selectRaw('COUNT(CASE WHEN sleep_seconds > 0 THEN 1 END) as sleep_nights')
            ->selectRaw('AVG(CASE WHEN sleep_score > 0 THEN sleep_score END) as avg_sleep_score')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->first();

        $avgSleepHours = is_numeric($sleepAgg->avg_sleep_seconds ?? null)
            ? round(((float) $sleepAgg->avg_sleep_seconds) / 3600.0, 2)
            : null;
        $avgSleepScore = is_numeric($sleepAgg->avg_sleep_score ?? null)
            ? round((float) $sleepAgg->avg_sleep_score, 1)
            : null;
        $sleepNights = (int) ($sleepAgg->sleep_nights ?? 0);

        // Daily metrics (Resting HR & Steps)
        $dailyAgg = $this->healthSourceRegistry
            ->applyScope(DailyMetric::query(), 'daily_metrics')
            ->toBase()
            ->selectRaw('AVG(CASE WHEN resting_hr > 0 THEN resting_hr END) as avg_resting_hr')
            ->selectRaw('COALESCE(SUM(CASE WHEN steps > 0 THEN steps ELSE 0 END), 0) as total_steps')
            ->selectRaw('AVG(CASE WHEN steps > 0 THEN steps END) as avg_daily_steps')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->first();

        $avgRestingHr = is_numeric($dailyAgg->avg_resting_hr ?? null)
            ? round((float) $dailyAgg->avg_resting_hr, 1)
            : null;
        $totalSteps = (int) ($dailyAgg->total_steps ?? 0);
        $avgDailySteps = is_numeric($dailyAgg->avg_daily_steps ?? null)
            ? (int) round((float) $dailyAgg->avg_daily_steps)
            : null;

        // HRV
        $hrvAgg = $this->healthSourceRegistry
            ->applyScope(HrvRecord::query(), 'hrv')
            ->toBase()
            ->selectRaw('AVG(CASE WHEN last_night_avg > 0 THEN last_night_avg END) as avg_hrv')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->first();

        $avgHrv = is_numeric($hrvAgg->avg_hrv ?? null)
            ? round((float) $hrvAgg->avg_hrv, 1)
            : null;

        // Peak VO2 Max
        $vo2Peak = $this->healthSourceRegistry
            ->applyScope(Vo2MaxRecord::query(), 'vo2_max')
            ->toBase()
            ->select(['calendar_date', 'sport', DB::raw('COALESCE(vo2_max_precise, vo2_max) as effective_vo2')])
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->whereRaw('COALESCE(vo2_max_precise, vo2_max) > 0')
            ->orderByDesc('effective_vo2')
            ->orderByDesc('calendar_date')
            ->first();

        $peakVo2Max = $vo2Peak !== null && is_numeric($vo2Peak->effective_vo2 ?? null)
            ? round((float) $vo2Peak->effective_vo2, 1)
            : null;
        $peakVo2Sport = $vo2Peak !== null && is_string($vo2Peak->sport ?? null) && $vo2Peak->sport !== ''
            ? $vo2Peak->sport
            : null;
        $peakVo2Date = $vo2Peak !== null && is_string($vo2Peak->calendar_date ?? null) && $vo2Peak->calendar_date !== ''
            ? $vo2Peak->calendar_date
            : null;

        $hasData = $avgSleepHours !== null
            || $avgSleepScore !== null
            || $avgRestingHr !== null
            || $avgHrv !== null
            || $totalSteps > 0
            || $peakVo2Max !== null;

        return [
            'has_data' => $hasData,
            'avg_sleep_hours' => $avgSleepHours,
            'avg_sleep_score' => $avgSleepScore,
            'sleep_nights' => $sleepNights,
            'avg_resting_hr' => $avgRestingHr,
            'avg_hrv' => $avgHrv,
            'total_steps' => $totalSteps,
            'avg_daily_steps' => $avgDailySteps,
            'peak_vo2_max' => $peakVo2Max,
            'peak_vo2_max_sport' => $peakVo2Sport,
            'peak_vo2_max_date' => $peakVo2Date,
        ];
    }
}
