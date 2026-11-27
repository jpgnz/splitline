<?php

namespace App\Services\Insights;

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Support\DateWindowResolver;
use App\Support\HealthSourceRegistry;
use App\Support\SportRegistry;
use App\Support\Statistics;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * Computes cross-domain correlations between daily health telemetry (Sleep, HRV, RHR),
 * environmental conditions (temperature, dew point, wind exposure), workout timing/intensity,
 * and aerobic performance metrics (Efficiency Factor, aerobic decoupling, HR, speed).
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
 * @phpstan-type CorrelationStat array{
 *     r: ?float,
 *     n: int,
 *     p_value: ?float,
 *     significant: bool,
 *     strength: ?string
 * }
 * @phpstan-type PerformanceBucket array{
 *     key: string,
 *     label: string,
 *     count: int,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     avg_hr: ?float,
 *     avg_speed: ?float
 * }
 * @phpstan-type HrvBucket array{
 *     key: string,
 *     label: string,
 *     count: int,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     avg_hr: ?float,
 *     avg_hrv: ?float,
 *     avg_rhr: ?float
 * }
 * @phpstan-type TempBucket array{
 *     key: string,
 *     label: string,
 *     min_c: ?int,
 *     max_c: ?int,
 *     count: int,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     avg_speed: ?float,
 *     avg_hr: ?float
 * }
 * @phpstan-type WindBucket array{
 *     key: string,
 *     label: string,
 *     count: int,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     avg_speed: ?float,
 *     avg_hr: ?float,
 *     avg_effective_wind_kmh: ?float
 * }
 * @phpstan-type RecoveryBucket array{
 *     key: string,
 *     label: string,
 *     count: int,
 *     avg_sleep_score: ?float,
 *     avg_deep_minutes: ?float,
 *     avg_hrv: ?float,
 *     avg_hrv_delta: ?float
 * }
 * @phpstan-type RecoveryMatrixCell array{
 *     time_key: string,
 *     time_label: string,
 *     intensity_key: string,
 *     intensity_label: string,
 *     count: int,
 *     avg_sleep_score: ?float,
 *     avg_deep_minutes: ?float,
 *     avg_hrv: ?float,
 *     avg_hrv_delta: ?float
 * }
 * @phpstan-type ScheduleBucket array{
 *     key: string,
 *     label: string,
 *     count: int,
 *     total_distance_meters: float,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     avg_hr: ?float,
 *     avg_speed: ?float
 * }
 * @phpstan-type SleepScatterPoint array{
 *     date: string,
 *     activity_id: int,
 *     activity_name: string,
 *     sleep_hours: ?float,
 *     sleep_score: ?int,
 *     efficiency_factor: ?float,
 *     decoupling_pct: ?float,
 *     avg_hr: ?int
 * }
 * @phpstan-type HrvScatterPoint array{
 *     date: string,
 *     activity_id: int,
 *     activity_name: string,
 *     hrv: ?int,
 *     rhr: ?int,
 *     body_battery_high: ?int,
 *     status: ?string,
 *     baseline_zone: ?string,
 *     efficiency_factor: ?float,
 *     decoupling_pct: ?float,
 *     avg_hr: ?int
 * }
 * @phpstan-type WeatherScatterPoint array{
 *     date: string,
 *     activity_id: int,
 *     activity_name: string,
 *     temperature_c: float,
 *     dew_point_c: ?float,
 *     apparent_temperature_c: ?float,
 *     efficiency_factor: ?float,
 *     decoupling_pct: ?float,
 *     avg_speed: ?float,
 *     avg_hr: ?int
 * }
 * @phpstan-type HeadlineBucket array{
 *     key: string,
 *     label: string,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     count: int
 * }
 * @phpstan-type HeadlineTempBucket array{
 *     key: string,
 *     label: string,
 *     min_c: ?int,
 *     max_c: ?int,
 *     avg_ef: ?float,
 *     avg_decoupling: ?float,
 *     count: int
 * }
 * @phpstan-type CorrelationSummary array{
 *     total_activities: int,
 *     training_days: int,
 *     with_derived_metrics: int,
 *     ef_metric: ?string,
 *     ef_excluded_sessions: int,
 *     with_sleep: int,
 *     with_hrv: int,
 *     with_weather: int,
 *     best_sleep_bucket: ?HeadlineBucket,
 *     optimal_temp_bucket: ?HeadlineTempBucket,
 *     peak_time_of_day: ?HeadlineBucket
 * }
 * @phpstan-type CorrelationPayload array{
 *     filters: array{sport: string, range: string},
 *     dateWindow: DateWindow,
 *     today: string,
 *     available_sports: list<array{key: string, label: string, short_label: string, accent_hex: string}>,
 *     has_data: bool,
 *     summary: CorrelationSummary,
 *     sleep_vs_performance: array{
 *         n: int,
 *         duration_buckets: list<PerformanceBucket>,
 *         score_buckets: list<PerformanceBucket>,
 *         scatter: list<SleepScatterPoint>,
 *         correlations: array{
 *             score_vs_ef: CorrelationStat,
 *             hours_vs_decoupling: CorrelationStat,
 *             hours_vs_ef: CorrelationStat
 *         },
 *         takeaway: ?string
 *     },
 *     hrv_vs_performance: array{
 *         n: int,
 *         status_buckets: list<HrvBucket>,
 *         baseline_buckets: list<HrvBucket>,
 *         scatter: list<HrvScatterPoint>,
 *         correlations: array{
 *             hrv_vs_ef: CorrelationStat,
 *             hrv_vs_decoupling: CorrelationStat,
 *             rhr_vs_ef: CorrelationStat
 *         },
 *         takeaway: ?string
 *     },
 *     weather_impact: array{
 *         n: int,
 *         wind_n: int,
 *         temperature_buckets: list<TempBucket>,
 *         wind_buckets: list<WindBucket>,
 *         scatter: list<WeatherScatterPoint>,
 *         correlations: array{
 *             temp_vs_ef: CorrelationStat,
 *             temp_vs_decoupling: CorrelationStat
 *         },
 *         takeaway: ?string
 *     },
 *     workout_to_recovery: array{
 *         n: int,
 *         by_time_of_day: list<RecoveryBucket>,
 *         by_intensity: list<RecoveryBucket>,
 *         matrix: list<RecoveryMatrixCell>,
 *         correlations: array{
 *             start_hour_vs_hrv_delta: CorrelationStat,
 *             start_hour_vs_sleep_score: CorrelationStat,
 *             load_vs_hrv_delta: CorrelationStat
 *         },
 *         takeaway: ?string
 *     },
 *     schedule_patterns: array{
 *         n: int,
 *         by_day_of_week: list<ScheduleBucket>,
 *         by_time_of_day: list<ScheduleBucket>,
 *         takeaway: ?string
 *     }
 * }
 */
class CorrelationService
{
    /** Minimum paired observations before a Pearson r (and its p-value) is reported. */
    private const int MIN_CORRELATION_SAMPLES = 10;

    /** Two-sided significance level required before directional or strength wording is used. */
    private const float SIGNIFICANCE_LEVEL = 0.05;

    /** Minimum observations an analysis needs before any takeaway sentence is produced. */
    private const int MIN_TAKEAWAY_SAMPLES = 5;

    /** Minimum observations per bucket before it can headline or be compared against another bucket. */
    private const int MIN_BUCKET_SAMPLES = 3;

    /** Minimum prior nights required for the 28-day rolling HRV baseline fallback. */
    private const int MIN_ROLLING_BASELINE_NIGHTS = 5;

    /** Nights before the target night used for the trailing HRV baseline. */
    private const int HRV_TRAILING_BASELINE_NIGHTS = 7;

    /** Minimum prior nights required for the trailing HRV baseline. */
    private const int MIN_HRV_TRAILING_NIGHTS = 3;

    private const int MAX_SCATTER_POINTS = 250;

    private const string DEFAULT_SPORT = 'running';

    private const string DEFAULT_RANGE = '1y';

    /** @var list<string> */
    private const array VALID_RANGES = ['90d', '180d', '1y', 'all', 'custom'];

    /** @var array<string, string> */
    private const array SLEEP_DURATION_BUCKETS = [
        'lt_6_5' => '< 6.5h',
        '6_5_to_7_5' => '6.5–7.5h',
        '7_5_to_8_5' => '7.5–8.5h',
        'gte_8_5' => '≥ 8.5h',
    ];

    /** @var array<string, string> */
    private const array SLEEP_SCORE_BUCKETS = [
        'poor' => 'Poor (<60)',
        'fair' => 'Fair (60–74)',
        'good' => 'Good (75–84)',
        'excellent' => 'Excellent (85+)',
    ];

    /** @var array<string, string> */
    private const array HRV_STATUS_BUCKETS = [
        'balanced' => 'Balanced',
        'unbalanced' => 'Unbalanced',
        'low' => 'Low',
        'poor' => 'Poor',
    ];

    /** @var array<string, string> */
    private const array HRV_BASELINE_BUCKETS = [
        'below' => 'Below Baseline',
        'in_baseline' => 'In Baseline',
        'above' => 'Above Baseline',
    ];

    /** @var array<string, array{label: string, min_c: ?int, max_c: ?int}> */
    private const array TEMP_BUCKETS = [
        'lt_10' => ['label' => '< 10°C', 'min_c' => null, 'max_c' => 10],
        '10_to_15' => ['label' => '10–15°C', 'min_c' => 10, 'max_c' => 15],
        '15_to_20' => ['label' => '15–20°C', 'min_c' => 15, 'max_c' => 20],
        '20_to_25' => ['label' => '20–25°C', 'min_c' => 20, 'max_c' => 25],
        'gte_25' => ['label' => '≥ 25°C', 'min_c' => 25, 'max_c' => null],
    ];

    /** @var array<string, string> */
    private const array WIND_BUCKETS = [
        'tailwind' => 'Tailwind Dominant',
        'balanced' => 'Balanced / Crosswind',
        'headwind' => 'Headwind Dominant',
    ];

    /** @var array<string, string> */
    private const array RECOVERY_TIME_BUCKETS = [
        'morning' => 'Morning (<12:00)',
        'afternoon' => 'Afternoon (12:00–18:00)',
        'evening' => 'Evening (18:00+)',
    ];

    /** @var array<string, int> */
    private const array INTENSITY_RANK = [
        'easy' => 0,
        'moderate' => 1,
        'hard' => 2,
    ];

    /** @var array<string, string> */
    private const array INTENSITY_BUCKETS = [
        'easy' => 'Easy',
        'moderate' => 'Moderate',
        'hard' => 'Hard',
    ];

    /** @var array<int, array{key: string, label: string}> */
    private const array WEEKDAY_BUCKETS = [
        1 => ['key' => 'mon', 'label' => 'Mon'],
        2 => ['key' => 'tue', 'label' => 'Tue'],
        3 => ['key' => 'wed', 'label' => 'Wed'],
        4 => ['key' => 'thu', 'label' => 'Thu'],
        5 => ['key' => 'fri', 'label' => 'Fri'],
        6 => ['key' => 'sat', 'label' => 'Sat'],
        7 => ['key' => 'sun', 'label' => 'Sun'],
    ];

    /** @var array<string, string> */
    private const array SCHEDULE_TIME_BUCKETS = [
        'early' => 'Early (<07:00)',
        'morning' => 'Morning (07:00–11:00)',
        'midday' => 'Midday (11:00–15:00)',
        'afternoon' => 'Afternoon (15:00–18:00)',
        'evening' => 'Evening (18:00+)',
    ];

    public function __construct(
        private readonly SportRegistry $sports,
        private readonly DateWindowResolver $dateWindows,
        private readonly HealthSourceRegistry $healthSources,
        private readonly Statistics $statistics,
    ) {}

    /**
     * Build the full Health ↔ Performance Correlation Explorer payload.
     *
     * Day-level health telemetry (sleep, HRV, RHR) is matched against one row per calendar day so
     * that multi-session days do not count the same night twice. Efficiency Factor is restricted
     * to a single output basis (power vs speed) because W/bpm and m/min/bpm are not comparable.
     *
     * @return CorrelationPayload
     */
    public function build(
        ?string $sport = null,
        ?string $range = null,
        ?string $end = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $normalizedSport = $this->normalizeSport($sport);
        $window = $this->resolveWindow($range, $end, $from, $to, $today);
        $availableSports = $this->sports->filterableSports();

        $activityQuery = $this->sports->applyScope(Activity::query(), $normalizedSport)
            ->leftJoin('activity_derived_metrics', 'activity_derived_metrics.activity_id', '=', 'activities.id')
            ->leftJoin('activity_weathers', 'activity_weathers.activity_id', '=', 'activities.id')
            ->select([
                'activities.id',
                'activities.name',
                'activities.started_at_local',
                'activities.distance_meters',
                'activities.duration_seconds',
                'activities.moving_duration_seconds',
                'activities.avg_speed',
                'activities.avg_hr',
                'activities.training_load',
                'activities.tss',
                'activity_derived_metrics.efficiency_factor as adm_efficiency_factor',
                'activity_derived_metrics.decoupling_metric as adm_decoupling_metric',
                'activity_derived_metrics.decoupling_pct as adm_decoupling_pct',
                'activity_derived_metrics.hr_high_pct as adm_hr_high_pct',
                'activity_weathers.temperature_c as aw_temperature_c',
                'activity_weathers.dew_point_c as aw_dew_point_c',
                'activity_weathers.apparent_temperature_c as aw_apparent_temperature_c',
                'activity_weathers.headwind_pct as aw_headwind_pct',
                'activity_weathers.tailwind_pct as aw_tailwind_pct',
                'activity_weathers.avg_effective_wind_kmh as aw_avg_effective_wind_kmh',
            ]);

        if ($window['start'] !== null) {
            $activityQuery->where('activities.started_at_local', '>=', $window['start'].' 00:00:00');
        }

        $activityQuery->where('activities.started_at_local', '<=', $window['end'].' 23:59:59');

        /** @var list<stdClass> $rows */
        $rows = $activityQuery
            ->orderBy('activities.started_at_local')
            ->orderBy('activities.id')
            ->toBase()
            ->get()
            ->all();

        if ($rows === []) {
            return $this->emptyPayload(
                sport: $normalizedSport,
                window: $window,
                today: $today,
                availableSports: $availableSports,
            );
        }

        $parsedRows = [];
        $minDate = null;
        $maxDate = null;

        foreach ($rows as $row) {
            $startedAtRaw = is_string($row->started_at_local ?? null) ? $row->started_at_local : null;

            if ($startedAtRaw === null || trim($startedAtRaw) === '') {
                continue;
            }

            $startedAt = CarbonImmutable::parse($startedAtRaw, $timezone);
            $localDate = $startedAt->format('Y-m-d');

            if ($minDate === null || strcmp($localDate, $minDate) < 0) {
                $minDate = $localDate;
            }

            if ($maxDate === null || strcmp($localDate, $maxDate) > 0) {
                $maxDate = $localDate;
            }

            $trainingLoad = $this->toPositiveFloat($row->training_load ?? null);
            $tss = $this->toPositiveFloat($row->tss ?? null);
            $hrHighPct = $this->toFloat($row->adm_hr_high_pct ?? null);
            $effectiveLoad = $tss ?? $trainingLoad;

            $parsedRows[] = [
                'id' => (int) $row->id,
                'name' => is_string($row->name ?? null) ? $row->name : 'Activity',
                'date' => $localDate,
                'next_date' => $startedAt->startOfDay()->addDay()->format('Y-m-d'),
                'hour' => $startedAt->hour,
                'hour_decimal' => round($startedAt->hour + ($startedAt->minute / 60.0), 2),
                'day_of_week_iso' => $startedAt->dayOfWeekIso,
                'distance_meters' => $this->toFloat($row->distance_meters ?? null) ?? 0.0,
                'duration_seconds' => $this->toPositiveFloat($row->moving_duration_seconds ?? null)
                    ?? $this->toPositiveFloat($row->duration_seconds ?? null)
                    ?? 0.0,
                'avg_speed' => $this->toPositiveFloat($row->avg_speed ?? null),
                'avg_hr' => $this->toPositiveInt($row->avg_hr ?? null),
                'effective_load' => $effectiveLoad,
                'hr_high_pct' => $hrHighPct,
                'intensity' => $this->classifyWorkoutIntensity($effectiveLoad, $hrHighPct),
                'efficiency_factor' => $this->toPositiveFloat($row->adm_efficiency_factor ?? null),
                'ef_basis' => $this->efBasis($row->adm_decoupling_metric ?? null),
                'decoupling_pct' => $this->toFloat($row->adm_decoupling_pct ?? null),
                'temperature_c' => $this->toFloat($row->aw_temperature_c ?? null),
                'dew_point_c' => $this->toFloat($row->aw_dew_point_c ?? null),
                'apparent_temperature_c' => $this->toFloat($row->aw_apparent_temperature_c ?? null),
                'headwind_pct' => $this->toFloat($row->aw_headwind_pct ?? null),
                'tailwind_pct' => $this->toFloat($row->aw_tailwind_pct ?? null),
                'avg_effective_wind_kmh' => $this->toFloat($row->aw_avg_effective_wind_kmh ?? null),
            ];
        }

        if ($parsedRows === [] || $minDate === null || $maxDate === null) {
            return $this->emptyPayload(
                sport: $normalizedSport,
                window: $window,
                today: $today,
                availableSports: $availableSports,
            );
        }

        [$parsedRows, $efMetric, $efExcludedSessions] = $this->restrictEfToDominantBasis($parsedRows);
        $primaryDailySessions = $this->collapseToPrimaryDailySessions($parsedRows);
        $trainingDays = $this->collapseToTrainingDays($parsedRows);

        $maxDatePlusOne = CarbonImmutable::parse($maxDate, $timezone)->addDay()->format('Y-m-d');
        $hrvLookbackStart = CarbonImmutable::parse($minDate, $timezone)->subDays(28)->format('Y-m-d');

        $sleepByDate = $this->loadSleepRecords($minDate, $maxDatePlusOne);
        $hrvByDate = $this->loadHrvRecords($hrvLookbackStart, $maxDatePlusOne);
        $dailyByDate = $this->loadDailyMetrics($minDate, $maxDate);

        $sleepAnalysis = $this->computeSleepAnalysis($primaryDailySessions, $sleepByDate);
        $hrvAnalysis = $this->computeHrvAnalysis($primaryDailySessions, $hrvByDate, $dailyByDate, $timezone);
        $weatherAnalysis = $this->computeWeatherAnalysis($parsedRows);
        $recoveryAnalysis = $this->computeWorkoutToRecoveryAnalysis($trainingDays, $sleepByDate, $hrvByDate, $timezone);
        $scheduleAnalysis = $this->computeSchedulePatterns($parsedRows);

        $withDerivedMetrics = 0;

        foreach ($parsedRows as $item) {
            if ($item['efficiency_factor'] !== null || $item['decoupling_pct'] !== null) {
                $withDerivedMetrics++;
            }
        }

        $bestSleepBucket = $this->pickHeadlinePerformanceBucket(
            $sleepAnalysis['duration_buckets'],
            $sleepAnalysis['n'],
        );
        $optimalTempBucket = $this->pickHeadlineTempBucket(
            $weatherAnalysis['temperature_buckets'],
            $weatherAnalysis['n'],
        );
        $peakTimeOfDay = $this->pickHeadlineScheduleBucket(
            $scheduleAnalysis['by_time_of_day'],
            $scheduleAnalysis['n'],
        );

        return [
            'filters' => [
                'sport' => $normalizedSport,
                'range' => $window['range'],
            ],
            'dateWindow' => $window,
            'today' => $today->format('Y-m-d'),
            'available_sports' => $availableSports,
            'has_data' => true,
            'summary' => [
                'total_activities' => count($parsedRows),
                'training_days' => count($primaryDailySessions),
                'with_derived_metrics' => $withDerivedMetrics,
                'ef_metric' => $efMetric,
                'ef_excluded_sessions' => $efExcludedSessions,
                'with_sleep' => $sleepAnalysis['n'],
                'with_hrv' => $hrvAnalysis['n'],
                'with_weather' => $weatherAnalysis['n'],
                'best_sleep_bucket' => $bestSleepBucket,
                'optimal_temp_bucket' => $optimalTempBucket,
                'peak_time_of_day' => $peakTimeOfDay,
            ],
            'sleep_vs_performance' => $sleepAnalysis,
            'hrv_vs_performance' => $hrvAnalysis,
            'weather_impact' => $weatherAnalysis,
            'workout_to_recovery' => $recoveryAnalysis,
            'schedule_patterns' => $scheduleAnalysis,
        ];
    }

    /**
     * Map a derived-metric output basis onto its Efficiency Factor unit family: power-based EF is
     * W/bpm while speed- and GAP-based EF are both (m/min)/bpm.
     */
    private function efBasis(mixed $decouplingMetric): ?string
    {
        return match ($decouplingMetric) {
            'power' => 'power',
            'speed', 'gap' => 'speed',
            default => null,
        };
    }

    /**
     * Keep Efficiency Factor only for sessions sharing the dominant output basis (power vs speed),
     * because pooling W/bpm with (m/min)/bpm turns every EF comparison into a sport-mix artefact.
     * Decoupling is a dimensionless drift percentage and stays pooled.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: ?string, 2: int}
     */
    private function restrictEfToDominantBasis(array $rows): array
    {
        $basisCounts = ['power' => 0, 'speed' => 0];

        foreach ($rows as $row) {
            if ($row['efficiency_factor'] !== null && is_string($row['ef_basis'])) {
                $basisCounts[$row['ef_basis']]++;
            }
        }

        if ($basisCounts['power'] === 0 && $basisCounts['speed'] === 0) {
            return [$rows, null, 0];
        }

        $dominantBasis = $basisCounts['power'] >= $basisCounts['speed'] ? 'power' : 'speed';
        $excluded = 0;

        foreach ($rows as $index => $row) {
            if ($row['efficiency_factor'] !== null && $row['ef_basis'] !== $dominantBasis) {
                $rows[$index]['efficiency_factor'] = null;
                $excluded++;
            }
        }

        return [$rows, $dominantBasis, $excluded];
    }

    /**
     * Reduce sessions to one primary session per calendar day so that day-level health readings
     * (one sleep/HRV/RHR value per night) are not replicated across multi-session days. The primary
     * session is the longest one with derived aerobic metrics, falling back to the longest overall.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function collapseToPrimaryDailySessions(array $rows): array
    {
        /** @var array<string, array<string, mixed>> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            /** @var string $date */
            $date = $row['date'];
            $current = $byDate[$date] ?? null;

            if ($current === null || $this->primarySessionScore($row) > $this->primarySessionScore($current)) {
                $byDate[$date] = $row;
            }
        }

        return array_values($byDate);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: int, 1: float, 2: float}
     */
    private function primarySessionScore(array $row): array
    {
        $hasDerived = $row['efficiency_factor'] !== null || $row['decoupling_pct'] !== null;

        return [
            $hasDerived ? 1 : 0,
            (float) $row['duration_seconds'],
            (float) $row['distance_meters'],
        ];
    }

    /**
     * Reduce sessions to one training-day row for next-night recovery matching. The day's load is the
     * sum of every session's load, intensity uses that total and the highest high-HR share, and the
     * start time is taken from the day's hardest session (ties favour the later start, which sits
     * closest to the night being measured).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{date: string, next_date: string, hour: int, hour_decimal: float, effective_load: ?float, intensity: string}>
     */
    private function collapseToTrainingDays(array $rows): array
    {
        /** @var array<string, list<array<string, mixed>>> $sessionsByDate */
        $sessionsByDate = [];

        foreach ($rows as $row) {
            /** @var string $date */
            $date = $row['date'];
            $sessionsByDate[$date][] = $row;
        }

        $days = [];

        foreach ($sessionsByDate as $date => $sessions) {
            $hardest = null;
            $totalLoad = null;
            $maxHrHighPct = null;

            foreach ($sessions as $session) {
                /** @var ?float $load */
                $load = $session['effective_load'];
                /** @var ?float $hrHighPct */
                $hrHighPct = $session['hr_high_pct'];

                if ($load !== null) {
                    $totalLoad = ($totalLoad ?? 0.0) + $load;
                }

                if ($hrHighPct !== null) {
                    $maxHrHighPct = max($maxHrHighPct ?? $hrHighPct, $hrHighPct);
                }

                if ($hardest === null || $this->sessionHardnessScore($session) >= $this->sessionHardnessScore($hardest)) {
                    $hardest = $session;
                }
            }

            /** @var array<string, mixed> $hardest */
            $days[] = [
                'date' => $date,
                'next_date' => (string) $hardest['next_date'],
                'hour' => (int) $hardest['hour'],
                'hour_decimal' => (float) $hardest['hour_decimal'],
                'effective_load' => $totalLoad,
                'intensity' => $this->classifyWorkoutIntensity($totalLoad, $maxHrHighPct),
            ];
        }

        return $days;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{0: int, 1: float}
     */
    private function sessionHardnessScore(array $session): array
    {
        /** @var string $intensity */
        $intensity = $session['intensity'];

        return [
            self::INTENSITY_RANK[$intensity] ?? 0,
            (float) ($session['effective_load'] ?? 0.0),
        ];
    }

    /**
     * Analysis 1: Sleep duration & score vs. same-day performance (one primary session per day).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{sleep_seconds: ?int, deep_seconds: ?int, sleep_score: ?int}>  $sleepByDate
     * @return array{
     *     n: int,
     *     duration_buckets: list<PerformanceBucket>,
     *     score_buckets: list<PerformanceBucket>,
     *     scatter: list<SleepScatterPoint>,
     *     correlations: array{
     *         score_vs_ef: CorrelationStat,
     *         hours_vs_decoupling: CorrelationStat,
     *         hours_vs_ef: CorrelationStat
     *     },
     *     takeaway: ?string
     * }
     */
    private function computeSleepAnalysis(array $rows, array $sleepByDate): array
    {
        $durationAcc = $this->initPerformanceAccumulators(self::SLEEP_DURATION_BUCKETS);
        $scoreAcc = $this->initPerformanceAccumulators(self::SLEEP_SCORE_BUCKETS);

        /** @var list<SleepScatterPoint> $scatter */
        $scatter = [];
        /** @var list<float> $scoreEfX */
        $scoreEfX = [];
        /** @var list<float> $scoreEfY */
        $scoreEfY = [];
        /** @var list<float> $hoursDecX */
        $hoursDecX = [];
        /** @var list<float> $hoursDecY */
        $hoursDecY = [];
        /** @var list<float> $hoursEfX */
        $hoursEfX = [];
        /** @var list<float> $hoursEfY */
        $hoursEfY = [];
        /** @var array<string, list<float>> $durationEfSamples */
        $durationEfSamples = [];

        $matchedCount = 0;

        foreach ($rows as $item) {
            /** @var string $date */
            $date = $item['date'];
            $sleep = $sleepByDate[$date] ?? null;

            if ($sleep === null) {
                continue;
            }

            $sleepHours = ($sleep['sleep_seconds'] !== null && $sleep['sleep_seconds'] > 0)
                ? round($sleep['sleep_seconds'] / 3600.0, 2)
                : null;
            $sleepScore = ($sleep['sleep_score'] !== null && $sleep['sleep_score'] > 0)
                ? $sleep['sleep_score']
                : null;

            if ($sleepHours === null && $sleepScore === null) {
                continue;
            }

            $matchedCount++;

            /** @var ?float $ef */
            $ef = $item['efficiency_factor'];
            /** @var ?float $decoupling */
            $decoupling = $item['decoupling_pct'];
            /** @var ?int $avgHr */
            $avgHr = $item['avg_hr'];
            /** @var ?float $avgSpeed */
            $avgSpeed = $item['avg_speed'];

            if ($sleepHours !== null) {
                $durationKey = $this->classifySleepDuration($sleepHours);
                $this->pushPerformanceSample($durationAcc[$durationKey], $ef, $decoupling, $avgHr, $avgSpeed);

                if ($decoupling !== null) {
                    $hoursDecX[] = $sleepHours;
                    $hoursDecY[] = $decoupling;
                }

                if ($ef !== null) {
                    $hoursEfX[] = $sleepHours;
                    $hoursEfY[] = $ef;
                    $durationEfSamples[$durationKey][] = $ef;
                }
            }

            if ($sleepScore !== null) {
                $scoreKey = $this->classifySleepScore($sleepScore);
                $this->pushPerformanceSample($scoreAcc[$scoreKey], $ef, $decoupling, $avgHr, $avgSpeed);

                if ($ef !== null) {
                    $scoreEfX[] = (float) $sleepScore;
                    $scoreEfY[] = $ef;
                }
            }

            if ($ef !== null || $decoupling !== null || $avgHr !== null) {
                $scatter[] = [
                    'date' => $date,
                    'activity_id' => (int) $item['id'],
                    'activity_name' => (string) $item['name'],
                    'sleep_hours' => $sleepHours,
                    'sleep_score' => $sleepScore,
                    'efficiency_factor' => $ef !== null ? round($ef, 3) : null,
                    'decoupling_pct' => $decoupling !== null ? round($decoupling, 2) : null,
                    'avg_hr' => $avgHr,
                ];
            }
        }

        $durationBuckets = $this->finalizePerformanceBuckets($durationAcc);
        $scoreBuckets = $this->finalizePerformanceBuckets($scoreAcc);
        $scoreVsEf = $this->buildCorrelationStat($scoreEfX, $scoreEfY);
        $hoursVsDec = $this->buildCorrelationStat($hoursDecX, $hoursDecY);
        $hoursVsEf = $this->buildCorrelationStat($hoursEfX, $hoursEfY);

        $takeaway = $this->buildSleepTakeaway($matchedCount, $durationBuckets, $durationEfSamples, $scoreVsEf, $hoursVsDec);

        return [
            'n' => $matchedCount,
            'duration_buckets' => $durationBuckets,
            'score_buckets' => $scoreBuckets,
            'scatter' => $this->capScatter($scatter),
            'correlations' => [
                'score_vs_ef' => $scoreVsEf,
                'hours_vs_decoupling' => $hoursVsDec,
                'hours_vs_ef' => $hoursVsEf,
            ],
            'takeaway' => $takeaway,
        ];
    }

    /**
     * Analysis 2: HRV status, baseline balance, and resting HR vs. performance (one primary session per day).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>  $hrvByDate
     * @param  array<string, array{resting_hr: ?int, body_battery_high: ?int}>  $dailyByDate
     * @return array{
     *     n: int,
     *     status_buckets: list<HrvBucket>,
     *     baseline_buckets: list<HrvBucket>,
     *     scatter: list<HrvScatterPoint>,
     *     correlations: array{
     *         hrv_vs_ef: CorrelationStat,
     *         hrv_vs_decoupling: CorrelationStat,
     *         rhr_vs_ef: CorrelationStat
     *     },
     *     takeaway: ?string
     * }
     */
    private function computeHrvAnalysis(
        array $rows,
        array $hrvByDate,
        array $dailyByDate,
        string $timezone,
    ): array {
        $statusAcc = $this->initHrvAccumulators(self::HRV_STATUS_BUCKETS);
        $baselineAcc = $this->initHrvAccumulators(self::HRV_BASELINE_BUCKETS);

        /** @var list<HrvScatterPoint> $scatter */
        $scatter = [];
        /** @var list<float> $hrvEfX */
        $hrvEfX = [];
        /** @var list<float> $hrvEfY */
        $hrvEfY = [];
        /** @var list<float> $hrvDecX */
        $hrvDecX = [];
        /** @var list<float> $hrvDecY */
        $hrvDecY = [];
        /** @var list<float> $rhrEfX */
        $rhrEfX = [];
        /** @var list<float> $rhrEfY */
        $rhrEfY = [];
        /** @var array<string, list<float>> $statusEfSamples */
        $statusEfSamples = [];
        /** @var array<string, list<float>> $baselineEfSamples */
        $baselineEfSamples = [];

        $matchedCount = 0;

        foreach ($rows as $item) {
            /** @var string $date */
            $date = $item['date'];
            $hrvRecord = $hrvByDate[$date] ?? null;
            $daily = $dailyByDate[$date] ?? null;

            $hrvVal = ($hrvRecord !== null && $hrvRecord['last_night_avg'] !== null && $hrvRecord['last_night_avg'] > 0)
                ? $hrvRecord['last_night_avg']
                : null;
            $rawStatus = $hrvRecord['status'] ?? null;
            $statusKey = $this->normalizeHrvStatus($rawStatus);
            $rhrVal = ($daily !== null && $daily['resting_hr'] !== null && $daily['resting_hr'] > 0)
                ? $daily['resting_hr']
                : null;
            $bodyBatteryHigh = ($daily !== null && $daily['body_battery_high'] !== null && $daily['body_battery_high'] > 0)
                ? $daily['body_battery_high']
                : null;

            /** @var ?float $ef */
            $ef = $item['efficiency_factor'];
            /** @var ?float $decoupling */
            $decoupling = $item['decoupling_pct'];
            /** @var ?int $avgHr */
            $avgHr = $item['avg_hr'];

            if ($rhrVal !== null && $ef !== null) {
                $rhrEfX[] = (float) $rhrVal;
                $rhrEfY[] = $ef;
            }

            if ($hrvVal === null && $statusKey === null) {
                continue;
            }

            $matchedCount++;

            if ($statusKey !== null) {
                $this->pushHrvSample($statusAcc[$statusKey], $ef, $decoupling, $avgHr, $hrvVal, $rhrVal);

                if ($ef !== null) {
                    $statusEfSamples[$statusKey][] = $ef;
                }
            }

            $baselineKey = $hrvRecord !== null && $hrvVal !== null
                ? $this->classifyHrvBaseline($date, $hrvVal, $hrvRecord, $hrvByDate, $timezone)
                : null;

            if ($baselineKey !== null) {
                $this->pushHrvSample($baselineAcc[$baselineKey], $ef, $decoupling, $avgHr, $hrvVal, $rhrVal);

                if ($ef !== null) {
                    $baselineEfSamples[$baselineKey][] = $ef;
                }
            }

            if ($hrvVal !== null) {
                if ($ef !== null) {
                    $hrvEfX[] = (float) $hrvVal;
                    $hrvEfY[] = $ef;
                }

                if ($decoupling !== null) {
                    $hrvDecX[] = (float) $hrvVal;
                    $hrvDecY[] = $decoupling;
                }
            }

            if ($hrvVal !== null || $rhrVal !== null) {
                $scatter[] = [
                    'date' => $date,
                    'activity_id' => (int) $item['id'],
                    'activity_name' => (string) $item['name'],
                    'hrv' => $hrvVal,
                    'rhr' => $rhrVal,
                    'body_battery_high' => $bodyBatteryHigh,
                    'status' => $statusKey !== null ? self::HRV_STATUS_BUCKETS[$statusKey] : null,
                    'baseline_zone' => $baselineKey !== null ? self::HRV_BASELINE_BUCKETS[$baselineKey] : null,
                    'efficiency_factor' => $ef !== null ? round($ef, 3) : null,
                    'decoupling_pct' => $decoupling !== null ? round($decoupling, 2) : null,
                    'avg_hr' => $avgHr,
                ];
            }
        }

        $statusBuckets = $this->finalizeHrvBuckets($statusAcc);
        $baselineBuckets = $this->finalizeHrvBuckets($baselineAcc);
        $hrvVsEf = $this->buildCorrelationStat($hrvEfX, $hrvEfY);
        $hrvVsDec = $this->buildCorrelationStat($hrvDecX, $hrvDecY);
        $rhrVsEf = $this->buildCorrelationStat($rhrEfX, $rhrEfY);

        $takeaway = $this->buildHrvTakeaway(
            $matchedCount,
            $statusBuckets,
            $statusEfSamples,
            $baselineBuckets,
            $baselineEfSamples,
            $hrvVsEf,
            $hrvVsDec,
        );

        return [
            'n' => $matchedCount,
            'status_buckets' => $statusBuckets,
            'baseline_buckets' => $baselineBuckets,
            'scatter' => $this->capScatter($scatter),
            'correlations' => [
                'hrv_vs_ef' => $hrvVsEf,
                'hrv_vs_decoupling' => $hrvVsDec,
                'rhr_vs_ef' => $rhrVsEf,
            ],
            'takeaway' => $takeaway,
        ];
    }

    /**
     * Analysis 3: Ambient temperature, dew point, and wind exposure vs. performance.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     n: int,
     *     wind_n: int,
     *     temperature_buckets: list<TempBucket>,
     *     wind_buckets: list<WindBucket>,
     *     scatter: list<WeatherScatterPoint>,
     *     correlations: array{
     *         temp_vs_ef: CorrelationStat,
     *         temp_vs_decoupling: CorrelationStat
     *     },
     *     takeaway: ?string
     * }
     */
    private function computeWeatherAnalysis(array $rows): array
    {
        $tempAcc = [];

        foreach (self::TEMP_BUCKETS as $key => $meta) {
            $tempAcc[$key] = [
                'key' => $key,
                'label' => $meta['label'],
                'min_c' => $meta['min_c'],
                'max_c' => $meta['max_c'],
                'count' => 0,
                'ef_sum' => 0.0,
                'ef_count' => 0,
                'dec_sum' => 0.0,
                'dec_count' => 0,
                'speed_sum' => 0.0,
                'speed_count' => 0,
                'hr_sum' => 0.0,
                'hr_count' => 0,
            ];
        }

        $windAcc = [];

        foreach (self::WIND_BUCKETS as $key => $label) {
            $windAcc[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'ef_sum' => 0.0,
                'ef_count' => 0,
                'dec_sum' => 0.0,
                'dec_count' => 0,
                'speed_sum' => 0.0,
                'speed_count' => 0,
                'hr_sum' => 0.0,
                'hr_count' => 0,
                'wind_sum' => 0.0,
                'wind_count' => 0,
            ];
        }

        /** @var list<WeatherScatterPoint> $scatter */
        $scatter = [];
        /** @var list<float> $tempEfX */
        $tempEfX = [];
        /** @var list<float> $tempEfY */
        $tempEfY = [];
        /** @var list<float> $tempDecX */
        $tempDecX = [];
        /** @var list<float> $tempDecY */
        $tempDecY = [];
        /** @var array<string, list<float>> $tempEfSamples */
        $tempEfSamples = [];

        $tempCount = 0;
        $windCount = 0;

        foreach ($rows as $item) {
            /** @var ?float $tempC */
            $tempC = $item['temperature_c'];
            /** @var ?float $dewPointC */
            $dewPointC = $item['dew_point_c'];
            /** @var ?float $apparentTempC */
            $apparentTempC = $item['apparent_temperature_c'];
            /** @var ?float $ef */
            $ef = $item['efficiency_factor'];
            /** @var ?float $decoupling */
            $decoupling = $item['decoupling_pct'];
            /** @var ?float $avgSpeed */
            $avgSpeed = $item['avg_speed'];
            /** @var ?int $avgHr */
            $avgHr = $item['avg_hr'];
            /** @var ?float $headwindPct */
            $headwindPct = $item['headwind_pct'];
            /** @var ?float $tailwindPct */
            $tailwindPct = $item['tailwind_pct'];
            /** @var ?float $effectiveWind */
            $effectiveWind = $item['avg_effective_wind_kmh'];

            if ($tempC !== null) {
                $tempCount++;
                $tempKey = $this->classifyTemperature($tempC);
                $tempAcc[$tempKey]['count']++;

                if ($ef !== null) {
                    $tempAcc[$tempKey]['ef_sum'] += $ef;
                    $tempAcc[$tempKey]['ef_count']++;
                    $tempEfX[] = $tempC;
                    $tempEfY[] = $ef;
                    $tempEfSamples[$tempKey][] = $ef;
                }

                if ($decoupling !== null) {
                    $tempAcc[$tempKey]['dec_sum'] += $decoupling;
                    $tempAcc[$tempKey]['dec_count']++;
                    $tempDecX[] = $tempC;
                    $tempDecY[] = $decoupling;
                }

                if ($avgSpeed !== null) {
                    $tempAcc[$tempKey]['speed_sum'] += $avgSpeed;
                    $tempAcc[$tempKey]['speed_count']++;
                }

                if ($avgHr !== null) {
                    $tempAcc[$tempKey]['hr_sum'] += (float) $avgHr;
                    $tempAcc[$tempKey]['hr_count']++;
                }

                $scatter[] = [
                    'date' => (string) $item['date'],
                    'activity_id' => (int) $item['id'],
                    'activity_name' => (string) $item['name'],
                    'temperature_c' => round($tempC, 1),
                    'dew_point_c' => $dewPointC !== null ? round($dewPointC, 1) : null,
                    'apparent_temperature_c' => $apparentTempC !== null ? round($apparentTempC, 1) : null,
                    'efficiency_factor' => $ef !== null ? round($ef, 3) : null,
                    'decoupling_pct' => $decoupling !== null ? round($decoupling, 2) : null,
                    'avg_speed' => $avgSpeed !== null ? round($avgSpeed, 3) : null,
                    'avg_hr' => $avgHr,
                ];
            }

            $windKey = $this->classifyWindExposure($effectiveWind, $headwindPct, $tailwindPct);

            if ($windKey !== null) {
                $windCount++;
                $windAcc[$windKey]['count']++;

                if ($ef !== null) {
                    $windAcc[$windKey]['ef_sum'] += $ef;
                    $windAcc[$windKey]['ef_count']++;
                }

                if ($decoupling !== null) {
                    $windAcc[$windKey]['dec_sum'] += $decoupling;
                    $windAcc[$windKey]['dec_count']++;
                }

                if ($avgSpeed !== null) {
                    $windAcc[$windKey]['speed_sum'] += $avgSpeed;
                    $windAcc[$windKey]['speed_count']++;
                }

                if ($avgHr !== null) {
                    $windAcc[$windKey]['hr_sum'] += (float) $avgHr;
                    $windAcc[$windKey]['hr_count']++;
                }

                if ($effectiveWind !== null) {
                    $windAcc[$windKey]['wind_sum'] += $effectiveWind;
                    $windAcc[$windKey]['wind_count']++;
                }
            }
        }

        /** @var list<TempBucket> $temperatureBuckets */
        $temperatureBuckets = [];

        foreach ($tempAcc as $bucket) {
            $temperatureBuckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'min_c' => $bucket['min_c'],
                'max_c' => $bucket['max_c'],
                'count' => $bucket['count'],
                'avg_ef' => $bucket['ef_count'] > 0 ? round($bucket['ef_sum'] / $bucket['ef_count'], 3) : null,
                'avg_decoupling' => $bucket['dec_count'] > 0 ? round($bucket['dec_sum'] / $bucket['dec_count'], 2) : null,
                'avg_speed' => $bucket['speed_count'] > 0 ? round($bucket['speed_sum'] / $bucket['speed_count'], 3) : null,
                'avg_hr' => $bucket['hr_count'] > 0 ? round($bucket['hr_sum'] / $bucket['hr_count'], 1) : null,
            ];
        }

        /** @var list<WindBucket> $windBuckets */
        $windBuckets = [];

        foreach ($windAcc as $bucket) {
            $windBuckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'avg_ef' => $bucket['ef_count'] > 0 ? round($bucket['ef_sum'] / $bucket['ef_count'], 3) : null,
                'avg_decoupling' => $bucket['dec_count'] > 0 ? round($bucket['dec_sum'] / $bucket['dec_count'], 2) : null,
                'avg_speed' => $bucket['speed_count'] > 0 ? round($bucket['speed_sum'] / $bucket['speed_count'], 3) : null,
                'avg_hr' => $bucket['hr_count'] > 0 ? round($bucket['hr_sum'] / $bucket['hr_count'], 1) : null,
                'avg_effective_wind_kmh' => $bucket['wind_count'] > 0 ? round($bucket['wind_sum'] / $bucket['wind_count'], 1) : null,
            ];
        }

        $tempVsEf = $this->buildCorrelationStat($tempEfX, $tempEfY);
        $tempVsDec = $this->buildCorrelationStat($tempDecX, $tempDecY);
        $takeaway = $this->buildWeatherTakeaway($tempCount, $temperatureBuckets, $tempEfSamples, $tempVsEf, $tempVsDec);

        return [
            'n' => $tempCount,
            'wind_n' => $windCount,
            'temperature_buckets' => $temperatureBuckets,
            'wind_buckets' => $windBuckets,
            'scatter' => $this->capScatter($scatter),
            'correlations' => [
                'temp_vs_ef' => $tempVsEf,
                'temp_vs_decoupling' => $tempVsDec,
            ],
            'takeaway' => $takeaway,
        ];
    }

    /**
     * Analysis 4: Workout start time & intensity vs. following night's (date + 1) Sleep & HRV recovery.
     * Each training day contributes once; HRV delta compares the night against the trailing nights before it.
     *
     * @param  list<array{date: string, next_date: string, hour: int, hour_decimal: float, effective_load: ?float, intensity: string}>  $trainingDays
     * @param  array<string, array{sleep_seconds: ?int, deep_seconds: ?int, sleep_score: ?int}>  $sleepByDate
     * @param  array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>  $hrvByDate
     * @return array{
     *     n: int,
     *     by_time_of_day: list<RecoveryBucket>,
     *     by_intensity: list<RecoveryBucket>,
     *     matrix: list<RecoveryMatrixCell>,
     *     correlations: array{
     *         start_hour_vs_hrv_delta: CorrelationStat,
     *         start_hour_vs_sleep_score: CorrelationStat,
     *         load_vs_hrv_delta: CorrelationStat
     *     },
     *     takeaway: ?string
     * }
     */
    private function computeWorkoutToRecoveryAnalysis(
        array $trainingDays,
        array $sleepByDate,
        array $hrvByDate,
        string $timezone,
    ): array {
        $timeAcc = $this->initRecoveryAccumulators(self::RECOVERY_TIME_BUCKETS);
        $intensityAcc = $this->initRecoveryAccumulators(self::INTENSITY_BUCKETS);

        $matrixAcc = [];

        foreach (self::RECOVERY_TIME_BUCKETS as $timeKey => $timeLabel) {
            foreach (self::INTENSITY_BUCKETS as $intKey => $intLabel) {
                $cellKey = "{$timeKey}:{$intKey}";
                $matrixAcc[$cellKey] = [
                    'time_key' => $timeKey,
                    'time_label' => $timeLabel,
                    'intensity_key' => $intKey,
                    'intensity_label' => $intLabel,
                    'count' => 0,
                    'sleep_score_sum' => 0.0,
                    'sleep_score_count' => 0,
                    'deep_min_sum' => 0.0,
                    'deep_min_count' => 0,
                    'hrv_sum' => 0.0,
                    'hrv_count' => 0,
                    'hrv_delta_sum' => 0.0,
                    'hrv_delta_count' => 0,
                ];
            }
        }

        /** @var list<float> $hourHrvDeltaX */
        $hourHrvDeltaX = [];
        /** @var list<float> $hourHrvDeltaY */
        $hourHrvDeltaY = [];
        /** @var list<float> $hourSleepX */
        $hourSleepX = [];
        /** @var list<float> $hourSleepY */
        $hourSleepY = [];
        /** @var list<float> $loadHrvDeltaX */
        $loadHrvDeltaX = [];
        /** @var list<float> $loadHrvDeltaY */
        $loadHrvDeltaY = [];
        /** @var array<string, list<float>> $timeHrvDeltaSamples */
        $timeHrvDeltaSamples = [];
        /** @var array<string, list<float>> $cellHrvDeltaSamples */
        $cellHrvDeltaSamples = [];

        $matchedCount = 0;

        foreach ($trainingDays as $day) {
            $nextDate = $day['next_date'];
            $nextSleep = $sleepByDate[$nextDate] ?? null;
            $nextHrv = $hrvByDate[$nextDate] ?? null;

            $sleepScore = ($nextSleep !== null && $nextSleep['sleep_score'] !== null && $nextSleep['sleep_score'] > 0)
                ? $nextSleep['sleep_score']
                : null;
            $deepMinutes = ($nextSleep !== null && $nextSleep['deep_seconds'] !== null && $nextSleep['deep_seconds'] > 0)
                ? round($nextSleep['deep_seconds'] / 60.0, 1)
                : null;
            $hrvVal = ($nextHrv !== null && $nextHrv['last_night_avg'] !== null && $nextHrv['last_night_avg'] > 0)
                ? $nextHrv['last_night_avg']
                : null;
            $trailingBaseline = $hrvVal !== null
                ? $this->trailingHrvBaseline($nextDate, $hrvByDate, $timezone)
                : null;
            $hrvDelta = ($hrvVal !== null && $trailingBaseline !== null)
                ? round($hrvVal - $trailingBaseline, 2)
                : null;

            if ($sleepScore === null && $deepMinutes === null && $hrvVal === null) {
                continue;
            }

            $matchedCount++;

            $hourDecimal = $day['hour_decimal'];
            $effectiveLoad = $day['effective_load'];

            $timeKey = $this->classifyRecoveryTimeSlot($day['hour']);
            $intensityKey = $day['intensity'];
            $cellKey = "{$timeKey}:{$intensityKey}";

            $this->pushRecoverySample($timeAcc[$timeKey], $sleepScore, $deepMinutes, $hrvVal, $hrvDelta);
            $this->pushRecoverySample($intensityAcc[$intensityKey], $sleepScore, $deepMinutes, $hrvVal, $hrvDelta);
            $this->pushRecoveryMatrixSample($matrixAcc[$cellKey], $sleepScore, $deepMinutes, $hrvVal, $hrvDelta);

            if ($hrvDelta !== null) {
                $hourHrvDeltaX[] = $hourDecimal;
                $hourHrvDeltaY[] = $hrvDelta;
                $timeHrvDeltaSamples[$timeKey][] = $hrvDelta;
                $cellHrvDeltaSamples[$cellKey][] = $hrvDelta;

                if ($effectiveLoad !== null) {
                    $loadHrvDeltaX[] = $effectiveLoad;
                    $loadHrvDeltaY[] = $hrvDelta;
                }
            }

            if ($sleepScore !== null) {
                $hourSleepX[] = $hourDecimal;
                $hourSleepY[] = (float) $sleepScore;
            }
        }

        $byTimeOfDay = $this->finalizeRecoveryBuckets($timeAcc);
        $byIntensity = $this->finalizeRecoveryBuckets($intensityAcc);

        /** @var list<RecoveryMatrixCell> $matrix */
        $matrix = [];

        foreach ($matrixAcc as $cell) {
            $matrix[] = [
                'time_key' => $cell['time_key'],
                'time_label' => $cell['time_label'],
                'intensity_key' => $cell['intensity_key'],
                'intensity_label' => $cell['intensity_label'],
                'count' => $cell['count'],
                'avg_sleep_score' => $cell['sleep_score_count'] > 0
                    ? round($cell['sleep_score_sum'] / $cell['sleep_score_count'], 1)
                    : null,
                'avg_deep_minutes' => $cell['deep_min_count'] > 0
                    ? round($cell['deep_min_sum'] / $cell['deep_min_count'], 1)
                    : null,
                'avg_hrv' => $cell['hrv_count'] > 0
                    ? round($cell['hrv_sum'] / $cell['hrv_count'], 1)
                    : null,
                'avg_hrv_delta' => $cell['hrv_delta_count'] > 0
                    ? round($cell['hrv_delta_sum'] / $cell['hrv_delta_count'], 1)
                    : null,
            ];
        }

        $hourVsHrvDelta = $this->buildCorrelationStat($hourHrvDeltaX, $hourHrvDeltaY);
        $hourVsSleep = $this->buildCorrelationStat($hourSleepX, $hourSleepY);
        $loadVsHrvDelta = $this->buildCorrelationStat($loadHrvDeltaX, $loadHrvDeltaY);

        $takeaway = $this->buildRecoveryTakeaway(
            $matchedCount,
            $byTimeOfDay,
            $timeHrvDeltaSamples,
            $matrix,
            $cellHrvDeltaSamples,
            $hourVsHrvDelta,
            $hourVsSleep,
        );

        return [
            'n' => $matchedCount,
            'by_time_of_day' => $byTimeOfDay,
            'by_intensity' => $byIntensity,
            'matrix' => $matrix,
            'correlations' => [
                'start_hour_vs_hrv_delta' => $hourVsHrvDelta,
                'start_hour_vs_sleep_score' => $hourVsSleep,
                'load_vs_hrv_delta' => $loadVsHrvDelta,
            ],
            'takeaway' => $takeaway,
        ];
    }

    /**
     * Analysis 5: Day-of-week & time-of-day volume and performance patterns.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     n: int,
     *     by_day_of_week: list<ScheduleBucket>,
     *     by_time_of_day: list<ScheduleBucket>,
     *     takeaway: ?string
     * }
     */
    private function computeSchedulePatterns(array $rows): array
    {
        $dayLabels = [];

        foreach (self::WEEKDAY_BUCKETS as $meta) {
            $dayLabels[$meta['key']] = $meta['label'];
        }

        $dayAcc = $this->initScheduleAccumulators($dayLabels);
        $timeAcc = $this->initScheduleAccumulators(self::SCHEDULE_TIME_BUCKETS);
        /** @var array<string, list<float>> $timeEfSamples */
        $timeEfSamples = [];

        foreach ($rows as $item) {
            /** @var int $dowIso */
            $dowIso = $item['day_of_week_iso'];
            $dayKey = self::WEEKDAY_BUCKETS[$dowIso]['key'] ?? 'mon';

            /** @var int $hour */
            $hour = $item['hour'];
            $timeKey = $this->classifyScheduleTimeSlot($hour);

            /** @var float $distance */
            $distance = $item['distance_meters'];
            /** @var ?float $ef */
            $ef = $item['efficiency_factor'];
            /** @var ?float $decoupling */
            $decoupling = $item['decoupling_pct'];
            /** @var ?int $avgHr */
            $avgHr = $item['avg_hr'];
            /** @var ?float $avgSpeed */
            $avgSpeed = $item['avg_speed'];

            $this->pushScheduleSample($dayAcc[$dayKey], $distance, $ef, $decoupling, $avgHr, $avgSpeed);
            $this->pushScheduleSample($timeAcc[$timeKey], $distance, $ef, $decoupling, $avgHr, $avgSpeed);

            if ($ef !== null) {
                $timeEfSamples[$timeKey][] = $ef;
            }
        }

        $byDayOfWeek = $this->finalizeScheduleBuckets($dayAcc);
        $byTimeOfDay = $this->finalizeScheduleBuckets($timeAcc);
        $totalCount = count($rows);

        $takeaway = $this->buildScheduleTakeaway($totalCount, $byDayOfWeek, $byTimeOfDay, $timeEfSamples);

        return [
            'n' => $totalCount,
            'by_day_of_week' => $byDayOfWeek,
            'by_time_of_day' => $byTimeOfDay,
            'takeaway' => $takeaway,
        ];
    }

    /**
     * Load SleepRecord rows strictly scoped via HealthSourceRegistry.
     *
     * @return array<string, array{sleep_seconds: ?int, deep_seconds: ?int, sleep_score: ?int}>
     */
    private function loadSleepRecords(string $startDate, string $endDate): array
    {
        $rows = $this->healthSources
            ->applyScope(SleepRecord::query(), 'sleep')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get(['calendar_date', 'sleep_seconds', 'deep_seconds', 'sleep_score']);

        /** @var array<string, array{sleep_seconds: ?int, deep_seconds: ?int, sleep_score: ?int}> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row->calendar_date] = [
                'sleep_seconds' => $row->sleep_seconds,
                'deep_seconds' => $row->deep_seconds,
                'sleep_score' => $row->sleep_score,
            ];
        }

        return $byDate;
    }

    /**
     * Load HrvRecord rows strictly scoped via HealthSourceRegistry.
     *
     * @return array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>
     */
    private function loadHrvRecords(string $startDate, string $endDate): array
    {
        $rows = $this->healthSources
            ->applyScope(HrvRecord::query(), 'hrv')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get([
                'calendar_date',
                'last_night_avg',
                'weekly_avg',
                'status',
                'baseline_balanced_low',
                'baseline_balanced_upper',
            ]);

        /** @var array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row->calendar_date] = [
                'last_night_avg' => $row->last_night_avg,
                'weekly_avg' => $row->weekly_avg,
                'status' => $row->status,
                'baseline_balanced_low' => $row->baseline_balanced_low,
                'baseline_balanced_upper' => $row->baseline_balanced_upper,
            ];
        }

        return $byDate;
    }

    /**
     * Load DailyMetric rows strictly scoped via HealthSourceRegistry.
     *
     * @return array<string, array{resting_hr: ?int, body_battery_high: ?int}>
     */
    private function loadDailyMetrics(string $startDate, string $endDate): array
    {
        $rows = $this->healthSources
            ->applyScope(DailyMetric::query(), 'daily_metrics')
            ->whereBetween('calendar_date', [$startDate, $endDate])
            ->orderBy('calendar_date')
            ->orderBy('id')
            ->get(['calendar_date', 'resting_hr', 'body_battery_high']);

        /** @var array<string, array{resting_hr: ?int, body_battery_high: ?int}> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[$row->calendar_date] = [
                'resting_hr' => $row->resting_hr,
                'body_battery_high' => $row->body_battery_high,
            ];
        }

        return $byDate;
    }

    private function classifySleepDuration(float $hours): string
    {
        if ($hours < 6.5) {
            return 'lt_6_5';
        }

        if ($hours < 7.5) {
            return '6_5_to_7_5';
        }

        if ($hours < 8.5) {
            return '7_5_to_8_5';
        }

        return 'gte_8_5';
    }

    private function classifySleepScore(int $score): string
    {
        if ($score < 60) {
            return 'poor';
        }

        if ($score <= 74) {
            return 'fair';
        }

        if ($score <= 84) {
            return 'good';
        }

        return 'excellent';
    }

    private function normalizeHrvStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return null;
        }

        if (isset(self::HRV_STATUS_BUCKETS[$normalized])) {
            return $normalized;
        }

        if (str_contains($normalized, 'unbalanced')) {
            return 'unbalanced';
        }

        if (str_contains($normalized, 'balanced')) {
            return 'balanced';
        }

        if (str_contains($normalized, 'poor')) {
            return 'poor';
        }

        if (str_contains($normalized, 'low')) {
            return 'low';
        }

        return null;
    }

    /**
     * Classify overnight HRV relative to Garmin's stored 28-day balanced baseline bounds,
     * falling back to a trailing 28-day rolling baseline or weekly average when bounds are absent.
     *
     * @param  array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}  $record
     * @param  array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>  $hrvByDate
     */
    private function classifyHrvBaseline(
        string $date,
        int $hrvVal,
        array $record,
        array $hrvByDate,
        string $timezone,
    ): ?string {
        $low = $record['baseline_balanced_low'];
        $upper = $record['baseline_balanced_upper'];

        if ($low !== null && $upper !== null && $low > 0 && $upper >= $low) {
            if ($hrvVal < $low) {
                return 'below';
            }

            if ($hrvVal > $upper) {
                return 'above';
            }

            return 'in_baseline';
        }

        $priorValues = $this->priorHrvValues($date, $hrvByDate, 28, $timezone);
        $priorCount = count($priorValues);

        if ($priorCount >= self::MIN_ROLLING_BASELINE_NIGHTS) {
            $mean = array_sum($priorValues) / $priorCount;
            $varianceSum = 0.0;

            foreach ($priorValues as $val) {
                $varianceSum += ($val - $mean) ** 2;
            }

            $sd = sqrt($varianceSum / $priorCount);
            $band = max($mean * 0.05, $sd * 0.5);

            if ($hrvVal < ($mean - $band)) {
                return 'below';
            }

            if ($hrvVal > ($mean + $band)) {
                return 'above';
            }

            return 'in_baseline';
        }

        $weeklyAvg = $record['weekly_avg'];

        if ($weeklyAvg !== null && $weeklyAvg > 0) {
            if ($hrvVal < $weeklyAvg * 0.95) {
                return 'below';
            }

            if ($hrvVal > $weeklyAvg * 1.05) {
                return 'above';
            }

            return 'in_baseline';
        }

        return null;
    }

    /**
     * Mean overnight HRV across the nights strictly before `$date`, so the night being evaluated does
     * not dilute its own baseline (Garmin's `weekly_avg` already includes that night).
     *
     * @param  array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>  $hrvByDate
     */
    private function trailingHrvBaseline(string $date, array $hrvByDate, string $timezone): ?float
    {
        $priorValues = $this->priorHrvValues($date, $hrvByDate, self::HRV_TRAILING_BASELINE_NIGHTS, $timezone);

        if (count($priorValues) < self::MIN_HRV_TRAILING_NIGHTS) {
            return null;
        }

        return array_sum($priorValues) / count($priorValues);
    }

    /**
     * Overnight HRV readings for the `$nights` calendar days immediately before `$date`.
     *
     * @param  array<string, array{last_night_avg: ?int, weekly_avg: ?int, status: ?string, baseline_balanced_low: ?int, baseline_balanced_upper: ?int}>  $hrvByDate
     * @return list<float>
     */
    private function priorHrvValues(string $date, array $hrvByDate, int $nights, string $timezone): array
    {
        $anchor = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $values = [];

        for ($offset = $nights; $offset >= 1; $offset--) {
            $priorRecord = $hrvByDate[$anchor->subDays($offset)->format('Y-m-d')] ?? null;

            if ($priorRecord !== null && $priorRecord['last_night_avg'] !== null && $priorRecord['last_night_avg'] > 0) {
                $values[] = (float) $priorRecord['last_night_avg'];
            }
        }

        return $values;
    }

    private function classifyTemperature(float $tempC): string
    {
        if ($tempC < 10.0) {
            return 'lt_10';
        }

        if ($tempC < 15.0) {
            return '10_to_15';
        }

        if ($tempC < 20.0) {
            return '15_to_20';
        }

        if ($tempC < 25.0) {
            return '20_to_25';
        }

        return 'gte_25';
    }

    /**
     * Classify wind exposure into Tailwind Dominant, Balanced / Crosswind, or Headwind Dominant.
     * Splitline stores positive `avg_effective_wind_kmh` for tailwind and negative for headwind.
     */
    private function classifyWindExposure(
        ?float $avgEffectiveWindKmh,
        ?float $headwindPct,
        ?float $tailwindPct,
    ): ?string {
        if ($avgEffectiveWindKmh === null && $headwindPct === null && $tailwindPct === null) {
            return null;
        }

        if ($avgEffectiveWindKmh !== null) {
            if ($avgEffectiveWindKmh <= -1.5) {
                return 'headwind';
            }

            if ($avgEffectiveWindKmh >= 1.5) {
                return 'tailwind';
            }
        }

        if ($headwindPct !== null && $headwindPct >= 45.0 && ($tailwindPct === null || $headwindPct > $tailwindPct)) {
            return 'headwind';
        }

        if ($tailwindPct !== null && $tailwindPct >= 45.0 && ($headwindPct === null || $tailwindPct > $headwindPct)) {
            return 'tailwind';
        }

        return 'balanced';
    }

    private function classifyRecoveryTimeSlot(int $hour): string
    {
        if ($hour < 12) {
            return 'morning';
        }

        if ($hour < 18) {
            return 'afternoon';
        }

        return 'evening';
    }

    private function classifyWorkoutIntensity(?float $effectiveLoad, ?float $hrHighPct): string
    {
        if (($hrHighPct !== null && $hrHighPct >= 20.0) || ($effectiveLoad !== null && $effectiveLoad >= 100.0)) {
            return 'hard';
        }

        if (($hrHighPct !== null && $hrHighPct >= 8.0) || ($effectiveLoad !== null && $effectiveLoad >= 55.0)) {
            return 'moderate';
        }

        return 'easy';
    }

    private function classifyScheduleTimeSlot(int $hour): string
    {
        if ($hour < 7) {
            return 'early';
        }

        if ($hour < 11) {
            return 'morning';
        }

        if ($hour < 15) {
            return 'midday';
        }

        if ($hour < 18) {
            return 'afternoon';
        }

        return 'evening';
    }

    /**
     * @param  list<float>  $xs
     * @param  list<float>  $ys
     * @return CorrelationStat
     */
    private function buildCorrelationStat(array $xs, array $ys): array
    {
        $n = min(count($xs), count($ys));
        $r = $n >= self::MIN_CORRELATION_SAMPLES ? $this->statistics->pearson($xs, $ys) : null;
        $pValue = $r !== null ? $this->statistics->correlationPValue($r, $n) : null;
        $significant = $pValue !== null && $pValue < self::SIGNIFICANCE_LEVEL;

        return [
            'r' => $r !== null ? round($r, 3) : null,
            'n' => $n,
            'p_value' => $pValue !== null ? round($pValue, 4) : null,
            'significant' => $significant,
            'strength' => $this->correlationStrength($r, $significant),
        ];
    }

    /**
     * Magnitude label for a significant correlation; non-significant coefficients are reported as
     * "inconclusive" so the UI never calls sampling noise "strong".
     */
    private function correlationStrength(?float $r, bool $significant): ?string
    {
        if ($r === null) {
            return null;
        }

        if (! $significant) {
            return 'inconclusive';
        }

        $abs = abs($r);

        if ($abs >= 0.5) {
            return 'strong';
        }

        if ($abs >= 0.3) {
            return 'moderate';
        }

        if ($abs >= 0.15) {
            return 'weak';
        }

        return 'negligible';
    }

    private function formatPValue(float $pValue): string
    {
        return $pValue < 0.001 ? 'p < 0.001' : sprintf('p = %.3f', $pValue);
    }

    /**
     * Compare the highest- and lowest-scoring buckets on `$field` with Welch's t-test and return them
     * only when the difference is statistically significant.
     *
     * @template TBucket of array{key: string, label: string, count: int}
     *
     * @param  list<TBucket>  $buckets
     * @param  array<string, list<float>>  $samplesByKey
     * @return ?array{best: TBucket, worst: TBucket, p_value: float}
     */
    private function significantBucketSpread(array $buckets, array $samplesByKey, string $field): ?array
    {
        $eligible = array_values(array_filter(
            $buckets,
            static fn (array $bucket): bool => ($bucket[$field] ?? null) !== null
                && count($samplesByKey[$bucket['key']] ?? []) >= self::MIN_BUCKET_SAMPLES,
        ));

        if (count($eligible) < 2) {
            return null;
        }

        usort($eligible, static fn (array $a, array $b): int => $b[$field] <=> $a[$field]);
        $best = $eligible[0];
        $worst = $eligible[count($eligible) - 1];
        $pValue = $this->significantDifference($samplesByKey[$best['key']], $samplesByKey[$worst['key']]);

        return $pValue === null ? null : ['best' => $best, 'worst' => $worst, 'p_value' => $pValue];
    }

    /**
     * Welch's t-test p-value when both samples are large enough and differ significantly, otherwise null.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function significantDifference(array $a, array $b): ?float
    {
        if (count($a) < self::MIN_BUCKET_SAMPLES || count($b) < self::MIN_BUCKET_SAMPLES) {
            return null;
        }

        $pValue = $this->statistics->welchTTestPValue($a, $b);

        return $pValue !== null && $pValue < self::SIGNIFICANCE_LEVEL ? $pValue : null;
    }

    /**
     * Directional sentence for a significant correlation, or null when it is not significant.
     *
     * @param  CorrelationStat  $stat
     */
    private function describeSignificantCorrelation(array $stat, string $template, string $positiveWord, string $negativeWord, string $unit): ?string
    {
        if (! $stat['significant'] || $stat['r'] === null || $stat['p_value'] === null || $stat['strength'] === null) {
            return null;
        }

        return sprintf(
            $template.' (r = %+.2f, %s, n = %d %s).',
            $stat['strength'],
            $stat['r'] >= 0 ? $positiveWord : $negativeWord,
            $stat['r'],
            $this->formatPValue($stat['p_value']),
            $stat['n'],
            $unit,
        );
    }

    /**
     * @param  list<PerformanceBucket>  $durationBuckets
     * @param  array<string, list<float>>  $durationEfSamples
     * @param  CorrelationStat  $scoreVsEf
     * @param  CorrelationStat  $hoursVsDec
     */
    private function buildSleepTakeaway(
        int $n,
        array $durationBuckets,
        array $durationEfSamples,
        array $scoreVsEf,
        array $hoursVsDec,
    ): ?string {
        if ($n < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $spread = $this->significantBucketSpread($durationBuckets, $durationEfSamples, 'avg_ef');

        if ($spread !== null && $spread['worst']['avg_ef'] > 0) {
            $best = $spread['best'];
            $worst = $spread['worst'];

            return sprintf(
                'Days after %s sleep average %.2f EF vs %.2f EF after %s (%+.1f%%, %s, n = %d days).',
                $best['label'],
                $best['avg_ef'],
                $worst['avg_ef'],
                $worst['label'],
                (($best['avg_ef'] - $worst['avg_ef']) / $worst['avg_ef']) * 100.0,
                $this->formatPValue($spread['p_value']),
                $n,
            );
        }

        return $this->describeSignificantCorrelation(
            $scoreVsEf,
            'Sleep score shows a %s %s correlation with Efficiency Factor',
            'positive',
            'negative',
            'days',
        ) ?? $this->describeSignificantCorrelation(
            $hoursVsDec,
            'Longer sleep shows a %s link to %s cardiac decoupling',
            'higher',
            'lower',
            'days',
        ) ?? sprintf(
            'No statistically significant link between sleep and same-day performance yet (%d days analyzed; effects need p < 0.05 across at least %d paired days).',
            $n,
            self::MIN_CORRELATION_SAMPLES,
        );
    }

    /**
     * @param  list<HrvBucket>  $statusBuckets
     * @param  array<string, list<float>>  $statusEfSamples
     * @param  list<HrvBucket>  $baselineBuckets
     * @param  array<string, list<float>>  $baselineEfSamples
     * @param  CorrelationStat  $hrvVsEf
     * @param  CorrelationStat  $hrvVsDec
     */
    private function buildHrvTakeaway(
        int $n,
        array $statusBuckets,
        array $statusEfSamples,
        array $baselineBuckets,
        array $baselineEfSamples,
        array $hrvVsEf,
        array $hrvVsDec,
    ): ?string {
        if ($n < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $baselineByKey = [];

        foreach ($baselineBuckets as $bucket) {
            $baselineByKey[$bucket['key']] = $bucket;
        }

        $below = $baselineByKey['below'] ?? null;
        $comparisonKey = count($baselineEfSamples['above'] ?? []) >= self::MIN_BUCKET_SAMPLES ? 'above' : 'in_baseline';
        $inOrAbove = $baselineByKey[$comparisonKey] ?? null;
        $baselinePValue = $this->significantDifference(
            $baselineEfSamples[$comparisonKey] ?? [],
            $baselineEfSamples['below'] ?? [],
        );

        if (
            $baselinePValue !== null
            && $below !== null && $inOrAbove !== null
            && $below['avg_ef'] !== null && $inOrAbove['avg_ef'] !== null && $below['avg_ef'] > 0
        ) {
            return sprintf(
                'When HRV is %s, sessions average %.2f EF vs %.2f EF when Below Baseline (%+.1f%%, %s, n = %d days).',
                strtolower($inOrAbove['label']),
                $inOrAbove['avg_ef'],
                $below['avg_ef'],
                (($inOrAbove['avg_ef'] - $below['avg_ef']) / $below['avg_ef']) * 100.0,
                $this->formatPValue($baselinePValue),
                $n,
            );
        }

        $statusByKey = [];

        foreach ($statusBuckets as $bucket) {
            $statusByKey[$bucket['key']] = $bucket;
        }

        $balanced = $statusByKey['balanced'] ?? null;
        $suppressedKey = count($statusEfSamples['low'] ?? []) >= self::MIN_BUCKET_SAMPLES ? 'low' : 'unbalanced';
        $suppressed = $statusByKey[$suppressedKey] ?? null;
        $statusPValue = $this->significantDifference(
            $statusEfSamples['balanced'] ?? [],
            $statusEfSamples[$suppressedKey] ?? [],
        );

        if (
            $statusPValue !== null
            && $balanced !== null && $suppressed !== null
            && $balanced['avg_ef'] !== null && $suppressed['avg_ef'] !== null && $suppressed['avg_ef'] > 0
        ) {
            return sprintf(
                'Balanced HRV days average %.2f EF vs %.2f EF on %s HRV days (%+.1f%%, %s, n = %d days).',
                $balanced['avg_ef'],
                $suppressed['avg_ef'],
                $suppressed['label'],
                (($balanced['avg_ef'] - $suppressed['avg_ef']) / $suppressed['avg_ef']) * 100.0,
                $this->formatPValue($statusPValue),
                $n,
            );
        }

        return $this->describeSignificantCorrelation(
            $hrvVsEf,
            'Overnight HRV shows a %s %s relationship with Efficiency Factor',
            'positive',
            'negative',
            'days',
        ) ?? $this->describeSignificantCorrelation(
            $hrvVsDec,
            'Higher overnight HRV shows a %s link to %s cardiac drift',
            'higher',
            'lower',
            'days',
        ) ?? sprintf(
            'No statistically significant link between overnight HRV and same-day performance yet (%d days analyzed).',
            $n,
        );
    }

    /**
     * @param  list<TempBucket>  $tempBuckets
     * @param  array<string, list<float>>  $tempEfSamples
     * @param  CorrelationStat  $tempVsEf
     * @param  CorrelationStat  $tempVsDec
     */
    private function buildWeatherTakeaway(
        int $n,
        array $tempBuckets,
        array $tempEfSamples,
        array $tempVsEf,
        array $tempVsDec,
    ): ?string {
        if ($n < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $spread = $this->significantBucketSpread($tempBuckets, $tempEfSamples, 'avg_ef');

        if ($spread !== null && $spread['best']['avg_ef'] > 0) {
            $best = $spread['best'];
            $worst = $spread['worst'];

            return sprintf(
                'Aerobic efficiency peaks in %s (%.2f EF) and is %.1f%% lower in %s (%.2f EF, %s, n = %d sessions).',
                $best['label'],
                $best['avg_ef'],
                (($best['avg_ef'] - $worst['avg_ef']) / $best['avg_ef']) * 100.0,
                $worst['label'],
                $worst['avg_ef'],
                $this->formatPValue($spread['p_value']),
                $n,
            );
        }

        return $this->describeSignificantCorrelation(
            $tempVsEf,
            'Warmer conditions show a %s link to %s Efficiency Factor',
            'higher',
            'lower',
            'sessions',
        ) ?? $this->describeSignificantCorrelation(
            $tempVsDec,
            'Warmer temperatures show a %s link to %s aerobic decoupling',
            'higher',
            'lower',
            'sessions',
        ) ?? sprintf(
            'No statistically significant temperature effect on performance yet (%d weather-matched sessions analyzed).',
            $n,
        );
    }

    /**
     * @param  list<RecoveryBucket>  $byTimeOfDay
     * @param  array<string, list<float>>  $timeHrvDeltaSamples
     * @param  list<RecoveryMatrixCell>  $matrix
     * @param  array<string, list<float>>  $cellHrvDeltaSamples
     * @param  CorrelationStat  $hourVsHrvDelta
     * @param  CorrelationStat  $hourVsSleep
     */
    private function buildRecoveryTakeaway(
        int $n,
        array $byTimeOfDay,
        array $timeHrvDeltaSamples,
        array $matrix,
        array $cellHrvDeltaSamples,
        array $hourVsHrvDelta,
        array $hourVsSleep,
    ): ?string {
        if ($n < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $eveningHard = null;
        $morningHard = null;

        foreach ($matrix as $cell) {
            if ($cell['time_key'] === 'evening' && $cell['intensity_key'] === 'hard') {
                $eveningHard = $cell;
            } elseif ($cell['time_key'] === 'morning' && $cell['intensity_key'] === 'hard') {
                $morningHard = $cell;
            }
        }

        $hardPValue = $this->significantDifference(
            $cellHrvDeltaSamples['evening:hard'] ?? [],
            $cellHrvDeltaSamples['morning:hard'] ?? [],
        );

        if (
            $hardPValue !== null
            && $eveningHard !== null && $morningHard !== null
            && $eveningHard['avg_hrv_delta'] !== null && $morningHard['avg_hrv_delta'] !== null
        ) {
            $sleepNote = ($eveningHard['avg_sleep_score'] !== null && $morningHard['avg_sleep_score'] !== null)
                ? sprintf(' and %.0f vs %.0f sleep score', $eveningHard['avg_sleep_score'], $morningHard['avg_sleep_score'])
                : '';

            return sprintf(
                'Hard evening sessions average %+.1f ms next-night HRV vs %+.1f ms after hard morning sessions%s (%s, n = %d days).',
                $eveningHard['avg_hrv_delta'],
                $morningHard['avg_hrv_delta'],
                $sleepNote,
                $this->formatPValue($hardPValue),
                $n,
            );
        }

        $timeByKey = [];

        foreach ($byTimeOfDay as $bucket) {
            $timeByKey[$bucket['key']] = $bucket;
        }

        $evening = $timeByKey['evening'] ?? null;
        $morning = $timeByKey['morning'] ?? null;
        $timePValue = $this->significantDifference(
            $timeHrvDeltaSamples['evening'] ?? [],
            $timeHrvDeltaSamples['morning'] ?? [],
        );

        if (
            $timePValue !== null
            && $evening !== null && $morning !== null
            && $evening['avg_hrv_delta'] !== null && $morning['avg_hrv_delta'] !== null
        ) {
            $sleepNote = ($evening['avg_sleep_score'] !== null && $morning['avg_sleep_score'] !== null)
                ? sprintf('; sleep score %.0f vs %.0f', $evening['avg_sleep_score'], $morning['avg_sleep_score'])
                : '';

            return sprintf(
                'Evening workouts average %+.1f ms next-night HRV vs %+.1f ms after morning workouts (%s%s, n = %d days).',
                $evening['avg_hrv_delta'],
                $morning['avg_hrv_delta'],
                $this->formatPValue($timePValue),
                $sleepNote,
                $n,
            );
        }

        return $this->describeSignificantCorrelation(
            $hourVsHrvDelta,
            'Later workout start times show a %s link to %s overnight HRV vs the prior 7 nights',
            'higher',
            'lower',
            'days',
        ) ?? $this->describeSignificantCorrelation(
            $hourVsSleep,
            'Later workout start times show a %s link to %s next-night sleep scores',
            'higher',
            'lower',
            'days',
        ) ?? sprintf(
            '%d training days matched to next-night recovery data — no statistically significant timing or intensity effect yet.',
            $n,
        );
    }

    /**
     * @param  list<ScheduleBucket>  $byDayOfWeek
     * @param  list<ScheduleBucket>  $byTimeOfDay
     * @param  array<string, list<float>>  $timeEfSamples
     */
    private function buildScheduleTakeaway(
        int $n,
        array $byDayOfWeek,
        array $byTimeOfDay,
        array $timeEfSamples,
    ): ?string {
        if ($n < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $busiestDay = null;

        foreach ($byDayOfWeek as $day) {
            if ($day['count'] > 0 && ($busiestDay === null || $day['count'] > $busiestDay['count'])) {
                $busiestDay = $day;
            }
        }

        if ($busiestDay === null) {
            return null;
        }

        $spread = $this->significantBucketSpread($byTimeOfDay, $timeEfSamples, 'avg_ef');

        if ($spread !== null) {
            return sprintf(
                'Efficiency Factor is highest during %s (%.2f EF) vs %s (%.2f EF, %s), while %s is your highest-frequency training day (%d sessions).',
                $spread['best']['label'],
                $spread['best']['avg_ef'],
                $spread['worst']['label'],
                $spread['worst']['avg_ef'],
                $this->formatPValue($spread['p_value']),
                $busiestDay['label'],
                $busiestDay['count'],
            );
        }

        return sprintf(
            '%s is your most active training day (%d of %d sessions in this window).',
            $busiestDay['label'],
            $busiestDay['count'],
            $n,
        );
    }

    /**
     * @param  list<PerformanceBucket>  $buckets
     * @return ?HeadlineBucket
     */
    private function pickHeadlinePerformanceBucket(array $buckets, int $totalN): ?array
    {
        if ($totalN < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $candidates = array_values(array_filter(
            $buckets,
            static fn (array $b): bool => $b['avg_ef'] !== null && $b['count'] >= self::MIN_BUCKET_SAMPLES,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['avg_ef'] <=> $a['avg_ef']);
        $best = $candidates[0];

        return [
            'key' => $best['key'],
            'label' => $best['label'],
            'avg_ef' => $best['avg_ef'],
            'avg_decoupling' => $best['avg_decoupling'],
            'count' => $best['count'],
        ];
    }

    /**
     * @param  list<TempBucket>  $buckets
     * @return ?HeadlineTempBucket
     */
    private function pickHeadlineTempBucket(array $buckets, int $totalN): ?array
    {
        if ($totalN < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $candidates = array_values(array_filter(
            $buckets,
            static fn (array $b): bool => $b['avg_ef'] !== null && $b['count'] >= self::MIN_BUCKET_SAMPLES,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['avg_ef'] <=> $a['avg_ef']);
        $best = $candidates[0];

        return [
            'key' => $best['key'],
            'label' => $best['label'],
            'min_c' => $best['min_c'],
            'max_c' => $best['max_c'],
            'avg_ef' => $best['avg_ef'],
            'avg_decoupling' => $best['avg_decoupling'],
            'count' => $best['count'],
        ];
    }

    /**
     * @param  list<ScheduleBucket>  $buckets
     * @return ?HeadlineBucket
     */
    private function pickHeadlineScheduleBucket(array $buckets, int $totalN): ?array
    {
        if ($totalN < self::MIN_TAKEAWAY_SAMPLES) {
            return null;
        }

        $candidates = array_values(array_filter(
            $buckets,
            static fn (array $b): bool => $b['avg_ef'] !== null && $b['count'] >= self::MIN_BUCKET_SAMPLES,
        ));

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['avg_ef'] <=> $a['avg_ef']);
        $best = $candidates[0];

        return [
            'key' => $best['key'],
            'label' => $best['label'],
            'avg_ef' => $best['avg_ef'],
            'avg_decoupling' => $best['avg_decoupling'],
            'count' => $best['count'],
        ];
    }

    /**
     * @template TPoint
     *
     * @param  list<TPoint>  $points
     * @return list<TPoint>
     */
    private function capScatter(array $points): array
    {
        $count = count($points);

        if ($count <= self::MAX_SCATTER_POINTS) {
            return $points;
        }

        return array_slice($points, $count - self::MAX_SCATTER_POINTS);
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }>
     */
    private function initPerformanceAccumulators(array $labels): array
    {
        $acc = [];

        foreach ($labels as $key => $label) {
            $acc[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'ef_sum' => 0.0,
                'ef_count' => 0,
                'dec_sum' => 0.0,
                'dec_count' => 0,
                'hr_sum' => 0.0,
                'hr_count' => 0,
                'speed_sum' => 0.0,
                'speed_count' => 0,
            ];
        }

        return $acc;
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }  $bucket
     */
    private function pushPerformanceSample(
        array &$bucket,
        ?float $ef,
        ?float $decoupling,
        ?int $avgHr,
        ?float $avgSpeed,
    ): void {
        $bucket['count']++;

        if ($ef !== null) {
            $bucket['ef_sum'] += $ef;
            $bucket['ef_count']++;
        }

        if ($decoupling !== null) {
            $bucket['dec_sum'] += $decoupling;
            $bucket['dec_count']++;
        }

        if ($avgHr !== null) {
            $bucket['hr_sum'] += (float) $avgHr;
            $bucket['hr_count']++;
        }

        if ($avgSpeed !== null) {
            $bucket['speed_sum'] += $avgSpeed;
            $bucket['speed_count']++;
        }
    }

    /**
     * @param  array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }>  $acc
     * @return list<PerformanceBucket>
     */
    private function finalizePerformanceBuckets(array $acc): array
    {
        $buckets = [];

        foreach ($acc as $bucket) {
            $buckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'avg_ef' => $bucket['ef_count'] > 0 ? round($bucket['ef_sum'] / $bucket['ef_count'], 3) : null,
                'avg_decoupling' => $bucket['dec_count'] > 0 ? round($bucket['dec_sum'] / $bucket['dec_count'], 2) : null,
                'avg_hr' => $bucket['hr_count'] > 0 ? round($bucket['hr_sum'] / $bucket['hr_count'], 1) : null,
                'avg_speed' => $bucket['speed_count'] > 0 ? round($bucket['speed_sum'] / $bucket['speed_count'], 3) : null,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     rhr_sum: float,
     *     rhr_count: int
     * }>
     */
    private function initHrvAccumulators(array $labels): array
    {
        $acc = [];

        foreach ($labels as $key => $label) {
            $acc[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'ef_sum' => 0.0,
                'ef_count' => 0,
                'dec_sum' => 0.0,
                'dec_count' => 0,
                'hr_sum' => 0.0,
                'hr_count' => 0,
                'hrv_sum' => 0.0,
                'hrv_count' => 0,
                'rhr_sum' => 0.0,
                'rhr_count' => 0,
            ];
        }

        return $acc;
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     rhr_sum: float,
     *     rhr_count: int
     * }  $bucket
     */
    private function pushHrvSample(
        array &$bucket,
        ?float $ef,
        ?float $decoupling,
        ?int $avgHr,
        ?int $hrv,
        ?int $rhr,
    ): void {
        $bucket['count']++;

        if ($ef !== null) {
            $bucket['ef_sum'] += $ef;
            $bucket['ef_count']++;
        }

        if ($decoupling !== null) {
            $bucket['dec_sum'] += $decoupling;
            $bucket['dec_count']++;
        }

        if ($avgHr !== null) {
            $bucket['hr_sum'] += (float) $avgHr;
            $bucket['hr_count']++;
        }

        if ($hrv !== null) {
            $bucket['hrv_sum'] += (float) $hrv;
            $bucket['hrv_count']++;
        }

        if ($rhr !== null) {
            $bucket['rhr_sum'] += (float) $rhr;
            $bucket['rhr_count']++;
        }
    }

    /**
     * @param  array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     rhr_sum: float,
     *     rhr_count: int
     * }>  $acc
     * @return list<HrvBucket>
     */
    private function finalizeHrvBuckets(array $acc): array
    {
        $buckets = [];

        foreach ($acc as $bucket) {
            $buckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'avg_ef' => $bucket['ef_count'] > 0 ? round($bucket['ef_sum'] / $bucket['ef_count'], 3) : null,
                'avg_decoupling' => $bucket['dec_count'] > 0 ? round($bucket['dec_sum'] / $bucket['dec_count'], 2) : null,
                'avg_hr' => $bucket['hr_count'] > 0 ? round($bucket['hr_sum'] / $bucket['hr_count'], 1) : null,
                'avg_hrv' => $bucket['hrv_count'] > 0 ? round($bucket['hrv_sum'] / $bucket['hrv_count'], 1) : null,
                'avg_rhr' => $bucket['rhr_count'] > 0 ? round($bucket['rhr_sum'] / $bucket['rhr_count'], 1) : null,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     sleep_score_sum: float,
     *     sleep_score_count: int,
     *     deep_min_sum: float,
     *     deep_min_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     hrv_delta_sum: float,
     *     hrv_delta_count: int
     * }>
     */
    private function initRecoveryAccumulators(array $labels): array
    {
        $acc = [];

        foreach ($labels as $key => $label) {
            $acc[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'sleep_score_sum' => 0.0,
                'sleep_score_count' => 0,
                'deep_min_sum' => 0.0,
                'deep_min_count' => 0,
                'hrv_sum' => 0.0,
                'hrv_count' => 0,
                'hrv_delta_sum' => 0.0,
                'hrv_delta_count' => 0,
            ];
        }

        return $acc;
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     sleep_score_sum: float,
     *     sleep_score_count: int,
     *     deep_min_sum: float,
     *     deep_min_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     hrv_delta_sum: float,
     *     hrv_delta_count: int
     * }  $bucket
     */
    private function pushRecoverySample(
        array &$bucket,
        ?int $sleepScore,
        ?float $deepMinutes,
        ?int $hrvVal,
        ?float $hrvDelta,
    ): void {
        $bucket['count']++;

        if ($sleepScore !== null) {
            $bucket['sleep_score_sum'] += (float) $sleepScore;
            $bucket['sleep_score_count']++;
        }

        if ($deepMinutes !== null) {
            $bucket['deep_min_sum'] += $deepMinutes;
            $bucket['deep_min_count']++;
        }

        if ($hrvVal !== null) {
            $bucket['hrv_sum'] += (float) $hrvVal;
            $bucket['hrv_count']++;
        }

        if ($hrvDelta !== null) {
            $bucket['hrv_delta_sum'] += $hrvDelta;
            $bucket['hrv_delta_count']++;
        }
    }

    /**
     * @param  array{
     *     time_key: string,
     *     time_label: string,
     *     intensity_key: string,
     *     intensity_label: string,
     *     count: int,
     *     sleep_score_sum: float,
     *     sleep_score_count: int,
     *     deep_min_sum: float,
     *     deep_min_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     hrv_delta_sum: float,
     *     hrv_delta_count: int
     * }  $cell
     */
    private function pushRecoveryMatrixSample(
        array &$cell,
        ?int $sleepScore,
        ?float $deepMinutes,
        ?int $hrvVal,
        ?float $hrvDelta,
    ): void {
        $cell['count']++;

        if ($sleepScore !== null) {
            $cell['sleep_score_sum'] += (float) $sleepScore;
            $cell['sleep_score_count']++;
        }

        if ($deepMinutes !== null) {
            $cell['deep_min_sum'] += $deepMinutes;
            $cell['deep_min_count']++;
        }

        if ($hrvVal !== null) {
            $cell['hrv_sum'] += (float) $hrvVal;
            $cell['hrv_count']++;
        }

        if ($hrvDelta !== null) {
            $cell['hrv_delta_sum'] += $hrvDelta;
            $cell['hrv_delta_count']++;
        }
    }

    /**
     * @param  array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     sleep_score_sum: float,
     *     sleep_score_count: int,
     *     deep_min_sum: float,
     *     deep_min_count: int,
     *     hrv_sum: float,
     *     hrv_count: int,
     *     hrv_delta_sum: float,
     *     hrv_delta_count: int
     * }>  $acc
     * @return list<RecoveryBucket>
     */
    private function finalizeRecoveryBuckets(array $acc): array
    {
        $buckets = [];

        foreach ($acc as $bucket) {
            $buckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'avg_sleep_score' => $bucket['sleep_score_count'] > 0
                    ? round($bucket['sleep_score_sum'] / $bucket['sleep_score_count'], 1)
                    : null,
                'avg_deep_minutes' => $bucket['deep_min_count'] > 0
                    ? round($bucket['deep_min_sum'] / $bucket['deep_min_count'], 1)
                    : null,
                'avg_hrv' => $bucket['hrv_count'] > 0
                    ? round($bucket['hrv_sum'] / $bucket['hrv_count'], 1)
                    : null,
                'avg_hrv_delta' => $bucket['hrv_delta_count'] > 0
                    ? round($bucket['hrv_delta_sum'] / $bucket['hrv_delta_count'], 1)
                    : null,
            ];
        }

        return $buckets;
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     total_distance_meters: float,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }>
     */
    private function initScheduleAccumulators(array $labels): array
    {
        $acc = [];

        foreach ($labels as $key => $label) {
            $acc[$key] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'total_distance_meters' => 0.0,
                'ef_sum' => 0.0,
                'ef_count' => 0,
                'dec_sum' => 0.0,
                'dec_count' => 0,
                'hr_sum' => 0.0,
                'hr_count' => 0,
                'speed_sum' => 0.0,
                'speed_count' => 0,
            ];
        }

        return $acc;
    }

    /**
     * @param  array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     total_distance_meters: float,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }  $bucket
     */
    private function pushScheduleSample(
        array &$bucket,
        float $distanceMeters,
        ?float $ef,
        ?float $decoupling,
        ?int $avgHr,
        ?float $avgSpeed,
    ): void {
        $bucket['count']++;
        $bucket['total_distance_meters'] += max(0.0, $distanceMeters);

        if ($ef !== null) {
            $bucket['ef_sum'] += $ef;
            $bucket['ef_count']++;
        }

        if ($decoupling !== null) {
            $bucket['dec_sum'] += $decoupling;
            $bucket['dec_count']++;
        }

        if ($avgHr !== null) {
            $bucket['hr_sum'] += (float) $avgHr;
            $bucket['hr_count']++;
        }

        if ($avgSpeed !== null) {
            $bucket['speed_sum'] += $avgSpeed;
            $bucket['speed_count']++;
        }
    }

    /**
     * @param  array<string, array{
     *     key: string,
     *     label: string,
     *     count: int,
     *     total_distance_meters: float,
     *     ef_sum: float,
     *     ef_count: int,
     *     dec_sum: float,
     *     dec_count: int,
     *     hr_sum: float,
     *     hr_count: int,
     *     speed_sum: float,
     *     speed_count: int
     * }>  $acc
     * @return list<ScheduleBucket>
     */
    private function finalizeScheduleBuckets(array $acc): array
    {
        $buckets = [];

        foreach ($acc as $bucket) {
            $buckets[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $bucket['count'],
                'total_distance_meters' => round($bucket['total_distance_meters'], 1),
                'avg_ef' => $bucket['ef_count'] > 0 ? round($bucket['ef_sum'] / $bucket['ef_count'], 3) : null,
                'avg_decoupling' => $bucket['dec_count'] > 0 ? round($bucket['dec_sum'] / $bucket['dec_count'], 2) : null,
                'avg_hr' => $bucket['hr_count'] > 0 ? round($bucket['hr_sum'] / $bucket['hr_count'], 1) : null,
                'avg_speed' => $bucket['speed_count'] > 0 ? round($bucket['speed_sum'] / $bucket['speed_count'], 3) : null,
            ];
        }

        return $buckets;
    }

    private function normalizeSport(?string $sport): string
    {
        if ($sport === null || trim($sport) === '') {
            return self::DEFAULT_SPORT;
        }

        $normalized = $this->sports->normalizeKey($sport);

        if ($normalized === 'all') {
            return 'all';
        }

        return $this->sports->get($normalized) !== null ? $normalized : self::DEFAULT_SPORT;
    }

    /**
     * Resolve date window supporting '90d', '180d', '1y', 'all', and 'custom' (default '1y').
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
        return $this->dateWindows->resolveDayWindow(
            $range,
            $end,
            $from,
            $to,
            $today,
            self::VALID_RANGES,
            self::DEFAULT_RANGE,
        );
    }

    private function timezone(): string
    {
        $tz = config('app.display_timezone', config('app.timezone', 'UTC'));

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }

    private function toFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (is_string($value) && is_numeric($value)) {
            $parsed = (float) $value;

            return is_finite($parsed) ? $parsed : null;
        }

        return null;
    }

    private function toPositiveFloat(mixed $value): ?float
    {
        $float = $this->toFloat($value);

        return ($float !== null && $float > 0.0) ? $float : null;
    }

    private function toPositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $parsed = (int) round((float) $value);

            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * @param  DateWindow  $window
     * @param  list<array{key: string, label: string, short_label: string, accent_hex: string}>  $availableSports
     * @return CorrelationPayload
     */
    private function emptyPayload(
        string $sport,
        array $window,
        CarbonImmutable $today,
        array $availableSports,
    ): array {
        $emptyStat = ['r' => null, 'n' => 0, 'p_value' => null, 'significant' => false, 'strength' => null];

        $emptyTempBuckets = [];

        foreach (self::TEMP_BUCKETS as $key => $meta) {
            $emptyTempBuckets[] = [
                'key' => $key,
                'label' => $meta['label'],
                'min_c' => $meta['min_c'],
                'max_c' => $meta['max_c'],
                'count' => 0,
                'avg_ef' => null,
                'avg_decoupling' => null,
                'avg_speed' => null,
                'avg_hr' => null,
            ];
        }

        $emptyWindBuckets = [];

        foreach (self::WIND_BUCKETS as $key => $label) {
            $emptyWindBuckets[] = [
                'key' => $key,
                'label' => $label,
                'count' => 0,
                'avg_ef' => null,
                'avg_decoupling' => null,
                'avg_speed' => null,
                'avg_hr' => null,
                'avg_effective_wind_kmh' => null,
            ];
        }

        $emptyMatrix = [];

        foreach (self::RECOVERY_TIME_BUCKETS as $timeKey => $timeLabel) {
            foreach (self::INTENSITY_BUCKETS as $intKey => $intLabel) {
                $emptyMatrix[] = [
                    'time_key' => $timeKey,
                    'time_label' => $timeLabel,
                    'intensity_key' => $intKey,
                    'intensity_label' => $intLabel,
                    'count' => 0,
                    'avg_sleep_score' => null,
                    'avg_deep_minutes' => null,
                    'avg_hrv' => null,
                    'avg_hrv_delta' => null,
                ];
            }
        }

        $dayLabels = [];

        foreach (self::WEEKDAY_BUCKETS as $meta) {
            $dayLabels[$meta['key']] = $meta['label'];
        }

        return [
            'filters' => [
                'sport' => $sport,
                'range' => $window['range'],
            ],
            'dateWindow' => $window,
            'today' => $today->format('Y-m-d'),
            'available_sports' => $availableSports,
            'has_data' => false,
            'summary' => [
                'total_activities' => 0,
                'training_days' => 0,
                'with_derived_metrics' => 0,
                'ef_metric' => null,
                'ef_excluded_sessions' => 0,
                'with_sleep' => 0,
                'with_hrv' => 0,
                'with_weather' => 0,
                'best_sleep_bucket' => null,
                'optimal_temp_bucket' => null,
                'peak_time_of_day' => null,
            ],
            'sleep_vs_performance' => [
                'n' => 0,
                'duration_buckets' => $this->finalizePerformanceBuckets($this->initPerformanceAccumulators(self::SLEEP_DURATION_BUCKETS)),
                'score_buckets' => $this->finalizePerformanceBuckets($this->initPerformanceAccumulators(self::SLEEP_SCORE_BUCKETS)),
                'scatter' => [],
                'correlations' => [
                    'score_vs_ef' => $emptyStat,
                    'hours_vs_decoupling' => $emptyStat,
                    'hours_vs_ef' => $emptyStat,
                ],
                'takeaway' => null,
            ],
            'hrv_vs_performance' => [
                'n' => 0,
                'status_buckets' => $this->finalizeHrvBuckets($this->initHrvAccumulators(self::HRV_STATUS_BUCKETS)),
                'baseline_buckets' => $this->finalizeHrvBuckets($this->initHrvAccumulators(self::HRV_BASELINE_BUCKETS)),
                'scatter' => [],
                'correlations' => [
                    'hrv_vs_ef' => $emptyStat,
                    'hrv_vs_decoupling' => $emptyStat,
                    'rhr_vs_ef' => $emptyStat,
                ],
                'takeaway' => null,
            ],
            'weather_impact' => [
                'n' => 0,
                'wind_n' => 0,
                'temperature_buckets' => $emptyTempBuckets,
                'wind_buckets' => $emptyWindBuckets,
                'scatter' => [],
                'correlations' => [
                    'temp_vs_ef' => $emptyStat,
                    'temp_vs_decoupling' => $emptyStat,
                ],
                'takeaway' => null,
            ],
            'workout_to_recovery' => [
                'n' => 0,
                'by_time_of_day' => $this->finalizeRecoveryBuckets($this->initRecoveryAccumulators(self::RECOVERY_TIME_BUCKETS)),
                'by_intensity' => $this->finalizeRecoveryBuckets($this->initRecoveryAccumulators(self::INTENSITY_BUCKETS)),
                'matrix' => $emptyMatrix,
                'correlations' => [
                    'start_hour_vs_hrv_delta' => $emptyStat,
                    'start_hour_vs_sleep_score' => $emptyStat,
                    'load_vs_hrv_delta' => $emptyStat,
                ],
                'takeaway' => null,
            ],
            'schedule_patterns' => [
                'n' => 0,
                'by_day_of_week' => $this->finalizeScheduleBuckets($this->initScheduleAccumulators($dayLabels)),
                'by_time_of_day' => $this->finalizeScheduleBuckets($this->initScheduleAccumulators(self::SCHEDULE_TIME_BUCKETS)),
                'takeaway' => null,
            ],
        ];
    }
}
