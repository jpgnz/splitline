<?php

namespace App\Services\Health;

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightGoal;
use App\Models\WeightMeasurement;
use App\Services\Activities\AnnualActivitySummaryService;
use App\Services\Goals\GoalProgressService;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DashboardService
{
    public function __construct(
        private readonly GoalProgressService $goalProgressService = new GoalProgressService,
        private readonly TrainingReadinessService $trainingReadinessService = new TrainingReadinessService,
        private readonly HealthStatusService $healthStatusService = new HealthStatusService,
        private readonly ?SportRegistry $sports = null,
        private readonly AnnualActivitySummaryService $annualActivitySummaryService = new AnnualActivitySummaryService,
    ) {}

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    /**
     * Build the complete dashboard payload for a given calendar date (or latest available date).
     *
     * @return array{
     *     date: string,
     *     dateLabel: string,
     *     prevDate: string,
     *     nextDate: ?string,
     *     isToday: bool,
     *     todayDate: string,
     *     latestActivity: ?array{
     *         id: int,
     *         name: string,
     *         sport_category: string,
     *         effective_discipline: ?string,
     *         distance_meters: float,
     *         duration_seconds: float,
     *         avg_speed: ?float,
     *         avg_hr: ?int,
     *         started_at_local: string,
     *         is_today: bool
     *     },
     *     bodyBattery: array{
     *         current: ?int,
     *         high: ?int,
     *         low: ?int,
     *         charged: ?int,
     *         drained: ?int,
     *         sleep: ?int,
     *         dayStartMs: int,
     *         dayEndMs: int,
     *         wakeTimestampMs: ?int,
     *         wakeTimeLabel: ?string,
     *         series: list<array{0: int, 1: int}>
     *     },
     *     sleep: array{
     *         totalSeconds: ?int,
     *         deepSeconds: ?int,
     *         lightSeconds: ?int,
     *         remSeconds: ?int,
     *         awakeSeconds: ?int,
     *         napSeconds: ?int,
     *         score: ?int,
     *         quality: ?string,
     *         needMinutes: ?int,
     *         nextNeedMinutes: ?int,
     *         startLocal: ?string,
     *         endLocal: ?string,
     *         trend7d: list<array{
     *             date: string,
     *             totalHours: float,
     *             deepHours: float,
     *             lightHours: float,
     *             remHours: float,
     *             score: ?int
     *         }>
     *     },
     *     hrv: array{
     *         lastNightAvg: ?int,
     *         weeklyAvg: ?int,
     *         lastNight5MinHigh: ?int,
     *         status: ?string,
     *         baselineLow: ?int,
     *         baselineBalancedLow: ?int,
     *         baselineBalancedUpper: ?int,
     *         feedbackPhrase: ?string,
     *         trend28d: list<array{
     *             date: string,
     *             lastNight: ?int,
     *             weeklyAvg: ?int
     *         }>
     *     },
     *     trainingReadiness: array{
     *         score: ?int,
     *         level: ?string,
     *         subtitle: ?string,
     *         tone: string,
     *         recoveryHours: ?int,
     *         factors: array{
     *             sleep: array{label: string, tone: string, score: ?int},
     *             recovery: array{label: string, tone: string, score: ?int, hours: ?int},
     *             hrvStatus: array{label: string, tone: string, score: ?int},
     *             acuteLoad: array{label: string, tone: string, score: ?int, ratio: ?float},
     *             recentSleep: array{label: string, tone: string, score: ?int},
     *             recentStress: array{label: string, tone: string, score: ?int}
     *         }
     *     },
     *     weeklyActivity: array{
     *         weekStart: string,
     *         weekEnd: string,
     *         weekLabel: string,
     *         totalDurationSeconds: float,
     *         days: list<array{
     *             date: string,
     *             label: string,
     *             durationSeconds: float,
     *             activityCount: int,
     *             isSelected: bool,
     *             isFuture: bool
     *         }>,
     *         last4w: list<array{
     *             date: string,
     *             durationSeconds: float,
     *             activityCount: int,
     *             level: int,
     *             isFuture: bool
     *         }>
     *     },
     *     training7d: array{
     *         total_load: float,
     *         total_duration_seconds: float,
     *         total_distance_meters: float,
     *         activity_count: int,
     *         by_sport: array{
     *             running: array{count: int, distance_meters: float, duration_seconds: float, load: float},
     *             road: array{count: int, distance_meters: float, duration_seconds: float, load: float},
     *             mtb: array{count: int, distance_meters: float, elevation_gain: float, duration_seconds: float, load: float},
     *             other: array{count: int, distance_meters: float, duration_seconds: float, load: float}
     *         },
     *         goals: list<array<string, mixed>>
     *     },
     *     atAGlance: array{
     *         steps: array{count: ?int, goal: ?int, distanceMeters: ?float},
     *         heartRate: array{
     *             resting: ?int,
     *             sevenDayAvg: ?int,
     *             min: ?int,
     *             max: ?int,
     *             series: list<array{0: int, 1: int}>
     *         },
     *         stress: array{
     *             avg: ?int,
     *             max: ?int,
     *             qualifier: ?string,
     *             restSeconds: ?int,
     *             lowSeconds: ?int,
     *             mediumSeconds: ?int,
     *             highSeconds: ?int,
     *             restPct: ?int
     *         },
     *         intensityMinutes: array{
     *             moderate: ?int,
     *             vigorous: ?int,
     *             total: ?int,
     *             goal: ?int,
     *             dailyTotal: ?int,
     *             weekStart: string
     *         },
     *         weight: array{
     *             weightKg: ?float,
     *             goalKg: ?float,
     *             deltaLastKg: ?float,
     *             delta30dKg: ?float,
     *             bmi: ?float,
     *             bodyFatPct: ?float,
     *             bodyWaterPct: ?float,
     *             muscleMassKg: ?float,
     *             boneMassKg: ?float,
     *             measuredDate: ?string
     *         },
     *         vo2Fitness: array{
     *             runningVo2: ?float,
     *             cyclingVo2: ?float,
     *             fitnessAge: ?float,
     *             chronologicalAge: ?float,
     *             delta: ?float,
     *             fitnessAgeDate: ?string
     *         },
     *         healthStatus: array{
     *             status: ?string,
     *             tone: string,
     *             summary: string,
     *             inRangeCount: int,
     *             checkedCount: int,
     *             metrics: list<array{key: string, label: string, inRange: bool, detail: string}>
     *         },
     *         calories: array{
     *             total: ?int,
     *             active: ?int,
     *             bmr: ?int,
     *             rawTotal: ?int,
     *             rawActive: ?int,
     *             rawBmr: ?int,
     *             dayNote: ?string
     *         },
     *         floors: array{ascended: ?float, descended: ?float, goal: ?int},
     *         respirationSpo2: array{
     *             avgRespiration: ?float,
     *             minRespiration: ?float,
     *             maxRespiration: ?float,
     *             avgSpo2: ?float,
     *             lowestSpo2: ?float,
     *             latestSpo2: ?float
     *         },
     *         hydration: array{ml: ?int}
     *     },
     *     annualActivitySummary: array{
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
     *     }
     * }
     */
    public function forDate(?string $date = null): array
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $todayStr = $today->format('Y-m-d');

        $selectedDate = $this->resolveSelectedDate($date, $today, $timezone);
        $selectedDateStr = $selectedDate->format('Y-m-d');

        $prevDate = $selectedDate->subDay()->format('Y-m-d');
        $isToday = $selectedDateStr === $todayStr;
        $nextDate = $selectedDate->greaterThanOrEqualTo($today)
            ? null
            : $selectedDate->addDay()->format('Y-m-d');
        $dateLabel = $selectedDate->format('D, j M Y');

        $dailyMetric = DailyMetric::query()
            ->where('calendar_date', $selectedDateStr)
            ->first();

        $sleepRecord = SleepRecord::query()
            ->where('calendar_date', $selectedDateStr)
            ->first();

        $hrvRecord = HrvRecord::query()
            ->where('calendar_date', $selectedDateStr)
            ->first();

        return [
            'date' => $selectedDateStr,
            'dateLabel' => $dateLabel,
            'prevDate' => $prevDate,
            'nextDate' => $nextDate,
            'isToday' => $isToday,
            'todayDate' => $todayStr,
            'latestActivity' => $this->buildLatestActivity($selectedDateStr),
            'bodyBattery' => $this->buildBodyBattery($dailyMetric, $sleepRecord, $selectedDate, $timezone),
            'sleep' => $this->buildSleep($sleepRecord, $selectedDate),
            'hrv' => $this->buildHrv($hrvRecord, $selectedDate),
            'trainingReadiness' => $this->trainingReadinessService->forDate(
                selectedDate: $selectedDate,
                today: $today,
                dailyMetric: $dailyMetric,
                sleepRecord: $sleepRecord,
                hrvRecord: $hrvRecord,
                timezone: $timezone,
            ),
            'weeklyActivity' => $this->buildWeeklyActivity($selectedDate, $today),
            'training7d' => $this->buildTraining7d($selectedDate),
            'atAGlance' => $this->buildAtAGlance($dailyMetric, $hrvRecord, $selectedDate, $todayStr, $timezone),
            'annualActivitySummary' => $this->annualActivitySummaryService->forQuery(Activity::query(), $today),
        ];
    }

    private function timezone(): string
    {
        return (string) config('app.display_timezone', 'UTC');
    }

    private function resolveSelectedDate(?string $date, CarbonImmutable $today, string $timezone): CarbonImmutable
    {
        if ($date !== null) {
            $parsed = $this->parseDateString($date, $timezone);

            if ($parsed !== null) {
                return $parsed->greaterThan($today) ? $today : $parsed;
            }
        }

        $latestDate = DailyMetric::query()->max('calendar_date');

        if (is_string($latestDate)) {
            $parsedLatest = $this->parseDateString($latestDate, $timezone);

            if ($parsedLatest !== null) {
                return $parsedLatest->greaterThan($today) ? $today : $parsedLatest;
            }
        }

        return $today;
    }

    private function parseDateString(string $value, string $timezone): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        if ($parsed === null || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    /**
     * @return ?array{
     *     id: int,
     *     name: string,
     *     sport_category: string,
     *     effective_discipline: ?string,
     *     distance_meters: float,
     *     duration_seconds: float,
     *     avg_speed: ?float,
     *     avg_hr: ?int,
     *     started_at_local: string,
     *     is_today: bool
     * }
     */
    private function buildLatestActivity(string $selectedDateStr): ?array
    {
        $latestTimestamp = Activity::query()
            ->where('started_at_local', '<=', $selectedDateStr.' 23:59:59')
            ->max('started_at_local');

        if (! is_string($latestTimestamp) || strlen($latestTimestamp) < 10) {
            return null;
        }

        $targetDate = substr($latestTimestamp, 0, 10);

        $activity = Activity::query()
            ->whereBetween('started_at_local', [$targetDate.' 00:00:00', $targetDate.' 23:59:59'])
            ->orderByRaw('COALESCE(calories, -1) DESC')
            ->orderByDesc('duration_seconds')
            ->orderByDesc('started_at_local')
            ->orderByDesc('id')
            ->first();

        if ($activity === null) {
            return null;
        }

        return [
            'id' => $activity->id,
            'name' => $activity->name,
            'sport_category' => $activity->sport_category,
            'effective_discipline' => $activity->effectiveDiscipline(),
            'distance_meters' => $activity->distance_meters,
            'duration_seconds' => $activity->duration_seconds,
            'avg_speed' => $activity->avg_speed,
            'avg_hr' => $activity->avg_hr,
            'started_at_local' => $activity->started_at_local->format('Y-m-d H:i:s'),
            'is_today' => $activity->started_at_local->format('Y-m-d') === $selectedDateStr,
        ];
    }

    /**
     * @return array{
     *     current: ?int,
     *     high: ?int,
     *     low: ?int,
     *     charged: ?int,
     *     drained: ?int,
     *     sleep: ?int,
     *     dayStartMs: int,
     *     dayEndMs: int,
     *     wakeTimestampMs: ?int,
     *     wakeTimeLabel: ?string,
     *     series: list<array{0: int, 1: int}>
     * }
     */
    private function buildBodyBattery(
        ?DailyMetric $dailyMetric,
        ?SleepRecord $sleepRecord,
        CarbonImmutable $selectedDate,
        string $timezone,
    ): array {
        $dayStartMs = $selectedDate->startOfDay()->getTimestampMs();
        $dayEndMs = $selectedDate->addDay()->startOfDay()->getTimestampMs();
        $selectedDateStr = $selectedDate->format('Y-m-d');

        $wakeTimestampMs = null;
        $wakeTimeLabel = null;

        if ($sleepRecord?->sleep_end_local !== null && $sleepRecord->sleep_end_local->format('Y-m-d') === $selectedDateStr) {
            $wakeLocal = CarbonImmutable::parse($sleepRecord->sleep_end_local->format('Y-m-d H:i:s'), $timezone);
            $wakeTimestampMs = $wakeLocal->getTimestampMs();
            $wakeTimeLabel = $wakeLocal->format('H:i');
        }

        return [
            'current' => $dailyMetric?->body_battery_current,
            'high' => $dailyMetric?->body_battery_high,
            'low' => $dailyMetric?->body_battery_low,
            'charged' => $dailyMetric?->body_battery_charged,
            'drained' => $dailyMetric?->body_battery_drained,
            'sleep' => $dailyMetric?->body_battery_sleep,
            'dayStartMs' => $dayStartMs,
            'dayEndMs' => $dayEndMs,
            'wakeTimestampMs' => $wakeTimestampMs,
            'wakeTimeLabel' => $wakeTimeLabel,
            'series' => $this->normalizeIntradaySeries($dailyMetric?->body_battery_series),
        ];
    }

    /**
     * @return array{
     *     totalSeconds: ?int,
     *     deepSeconds: ?int,
     *     lightSeconds: ?int,
     *     remSeconds: ?int,
     *     awakeSeconds: ?int,
     *     napSeconds: ?int,
     *     score: ?int,
     *     quality: ?string,
     *     needMinutes: ?int,
     *     nextNeedMinutes: ?int,
     *     startLocal: ?string,
     *     endLocal: ?string,
     *     trend7d: list<array{
     *         date: string,
     *         totalHours: float,
     *         deepHours: float,
     *         lightHours: float,
     *         remHours: float,
     *         score: ?int
     *     }>
     * }
     */
    private function buildSleep(?SleepRecord $sleepRecord, CarbonImmutable $selectedDate): array
    {
        $startStr = $selectedDate->subDays(6)->format('Y-m-d');
        $endStr = $selectedDate->format('Y-m-d');

        $records = SleepRecord::query()
            ->whereBetween('calendar_date', [$startStr, $endStr])
            ->orderBy('calendar_date')
            ->get();

        $trend7d = [];

        foreach ($records as $record) {
            $trend7d[] = [
                'date' => $record->calendar_date,
                'totalHours' => round(($record->sleep_seconds ?? 0) / 3600, 2),
                'deepHours' => round(($record->deep_seconds ?? 0) / 3600, 2),
                'lightHours' => round(($record->light_seconds ?? 0) / 3600, 2),
                'remHours' => round(($record->rem_seconds ?? 0) / 3600, 2),
                'score' => $record->sleep_score,
            ];
        }

        return [
            'totalSeconds' => $sleepRecord?->sleep_seconds,
            'deepSeconds' => $sleepRecord?->deep_seconds,
            'lightSeconds' => $sleepRecord?->light_seconds,
            'remSeconds' => $sleepRecord?->rem_seconds,
            'awakeSeconds' => $sleepRecord?->awake_seconds,
            'napSeconds' => $sleepRecord?->nap_seconds,
            'score' => $sleepRecord?->sleep_score,
            'quality' => $sleepRecord?->qualityQualifier(),
            'needMinutes' => $sleepRecord?->sleep_need_minutes,
            'nextNeedMinutes' => $sleepRecord?->next_sleep_need_minutes,
            'startLocal' => $sleepRecord?->sleep_start_local?->format('Y-m-d H:i:s'),
            'endLocal' => $sleepRecord?->sleep_end_local?->format('Y-m-d H:i:s'),
            'trend7d' => $trend7d,
        ];
    }

    /**
     * @return array{
     *     lastNightAvg: ?int,
     *     weeklyAvg: ?int,
     *     lastNight5MinHigh: ?int,
     *     status: ?string,
     *     baselineLow: ?int,
     *     baselineBalancedLow: ?int,
     *     baselineBalancedUpper: ?int,
     *     feedbackPhrase: ?string,
     *     trend28d: list<array{
     *         date: string,
     *         lastNight: ?int,
     *         weeklyAvg: ?int
     *     }>
     * }
     */
    private function buildHrv(?HrvRecord $hrvRecord, CarbonImmutable $selectedDate): array
    {
        $startStr = $selectedDate->subDays(27)->format('Y-m-d');
        $endStr = $selectedDate->format('Y-m-d');

        $records = HrvRecord::query()
            ->whereBetween('calendar_date', [$startStr, $endStr])
            ->orderBy('calendar_date')
            ->get();

        $trend28d = [];

        foreach ($records as $record) {
            $trend28d[] = [
                'date' => $record->calendar_date,
                'lastNight' => $record->last_night_avg,
                'weeklyAvg' => $record->weekly_avg,
            ];
        }

        return [
            'lastNightAvg' => $hrvRecord?->last_night_avg,
            'weeklyAvg' => $hrvRecord?->weekly_avg,
            'lastNight5MinHigh' => $hrvRecord?->last_night_5min_high,
            'status' => $hrvRecord?->status,
            'baselineLow' => $hrvRecord?->baseline_low_upper,
            'baselineBalancedLow' => $hrvRecord?->baseline_balanced_low,
            'baselineBalancedUpper' => $hrvRecord?->baseline_balanced_upper,
            'feedbackPhrase' => $hrvRecord?->feedback_phrase,
            'trend28d' => $trend28d,
        ];
    }

    /**
     * Build Garmin-style Mon–Sun daily activity bars and a 4-week daily consistency dot strip.
     *
     * @return array{
     *     weekStart: string,
     *     weekEnd: string,
     *     weekLabel: string,
     *     totalDurationSeconds: float,
     *     days: list<array{
     *         date: string,
     *         label: string,
     *         durationSeconds: float,
     *         activityCount: int,
     *         isSelected: bool,
     *         isFuture: bool
     *     }>,
     *     last4w: list<array{
     *         date: string,
     *         durationSeconds: float,
     *         activityCount: int,
     *         level: int,
     *         isFuture: bool
     *     }>
     * }
     */
    private function buildWeeklyActivity(CarbonImmutable $selectedDate, CarbonImmutable $today): array
    {
        $weekStart = $selectedDate->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $weekStart->addDays(6);
        $fourWeekStart = $weekStart->subWeeks(3);
        $selectedDateStr = $selectedDate->format('Y-m-d');
        $todayStr = $today->format('Y-m-d');

        $activities = Activity::query()
            ->whereBetween('started_at_local', [
                $fourWeekStart->format('Y-m-d').' 00:00:00',
                $weekEnd->format('Y-m-d').' 23:59:59',
            ])
            ->get(['started_at_local', 'duration_seconds']);

        /** @var array<string, array{duration: float, count: int}> $byDate */
        $byDate = [];

        foreach ($activities as $activity) {
            $dayStr = $activity->started_at_local->format('Y-m-d');

            if (! isset($byDate[$dayStr])) {
                $byDate[$dayStr] = ['duration' => 0.0, 'count' => 0];
            }

            $byDate[$dayStr]['duration'] += max(0.0, $activity->duration_seconds);
            $byDate[$dayStr]['count']++;
        }

        $dayLabels = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        $days = [];
        $totalDuration = 0.0;

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->addDays($offset);
            $dayStr = $day->format('Y-m-d');
            $duration = round($byDate[$dayStr]['duration'] ?? 0.0, 1);
            $count = $byDate[$dayStr]['count'] ?? 0;

            if ($dayStr <= $selectedDateStr) {
                $totalDuration += $duration;
            }

            $days[] = [
                'date' => $dayStr,
                'label' => $dayLabels[$offset],
                'durationSeconds' => $duration,
                'activityCount' => $count,
                'isSelected' => $dayStr === $selectedDateStr,
                'isFuture' => $dayStr > $todayStr,
            ];
        }

        $last4w = [];

        for ($offset = 0; $offset < 28; $offset++) {
            $day = $fourWeekStart->addDays($offset);
            $dayStr = $day->format('Y-m-d');
            $duration = round($byDate[$dayStr]['duration'] ?? 0.0, 1);
            $count = $byDate[$dayStr]['count'] ?? 0;

            $level = match (true) {
                $count === 0 || $duration <= 0.0 => 0,
                $duration < 1800.0 => 1,
                $duration < 3600.0 => 2,
                default => 3,
            };

            $last4w[] = [
                'date' => $dayStr,
                'durationSeconds' => $duration,
                'activityCount' => $count,
                'level' => $level,
                'isFuture' => $dayStr > $todayStr,
            ];
        }

        $weekLabel = $weekStart->month === $weekEnd->month
            ? sprintf('%s %d–%d', $weekStart->format('M'), $weekStart->day, $weekEnd->day)
            : sprintf('%s %d–%s %d', $weekStart->format('M'), $weekStart->day, $weekEnd->format('M'), $weekEnd->day);

        return [
            'weekStart' => $weekStart->format('Y-m-d'),
            'weekEnd' => $weekEnd->format('Y-m-d'),
            'weekLabel' => $weekLabel,
            'totalDurationSeconds' => round($totalDuration, 1),
            'days' => $days,
            'last4w' => $last4w,
        ];
    }

    /**
     * @return array{
     *     total_load: float,
     *     total_duration_seconds: float,
     *     total_distance_meters: float,
     *     activity_count: int,
     *     by_sport: array{
     *         running: array{count: int, distance_meters: float, duration_seconds: float, load: float},
     *         road: array{count: int, distance_meters: float, duration_seconds: float, load: float},
     *         mtb: array{count: int, distance_meters: float, elevation_gain: float, duration_seconds: float, load: float},
     *         other: array{count: int, distance_meters: float, duration_seconds: float, load: float}
     *     },
     *     by_sport_list: list<array{
     *         key: string,
     *         label: string,
     *         short_label: string,
     *         accent_hex: string,
     *         count: int,
     *         distance_meters: float,
     *         elevation_gain: float,
     *         duration_seconds: float,
     *         load: float
     *     }>,
     *     goals: list<array<string, mixed>>
     * }
     */
    private function buildTraining7d(CarbonImmutable $selectedDate): array
    {
        $windowStart = $selectedDate->subDays(6)->format('Y-m-d').' 00:00:00';
        $windowEnd = $selectedDate->format('Y-m-d').' 23:59:59';

        $activities = Activity::query()
            ->whereBetween('started_at_local', [$windowStart, $windowEnd])
            ->get();

        $totalLoad = 0.0;
        $totalDuration = 0.0;
        $totalDistance = 0.0;

        $registry = $this->sportRegistry();
        $catalog = $registry->catalog();
        /** @var array<string, array{key: string, label: string, short_label: string, accent_hex: string, count: int, distance_meters: float, elevation_gain: float, duration_seconds: float, load: float}> $dynamicBuckets */
        $dynamicBuckets = [];

        foreach ($registry->activeSportKeys() as $activeKey) {
            $entry = $catalog[$activeKey] ?? null;

            if ($entry === null) {
                continue;
            }

            $dynamicBuckets[$activeKey] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'short_label' => $entry['short_label'],
                'accent_hex' => $entry['accent_hex'],
                'count' => 0,
                'distance_meters' => 0.0,
                'elevation_gain' => 0.0,
                'duration_seconds' => 0.0,
                'load' => 0.0,
            ];
        }

        $dynamicOther = [
            'key' => 'other',
            'label' => 'Other',
            'short_label' => 'Other',
            'accent_hex' => '#71717a',
            'count' => 0,
            'distance_meters' => 0.0,
            'elevation_gain' => 0.0,
            'duration_seconds' => 0.0,
            'load' => 0.0,
        ];

        $running = ['count' => 0, 'distance_meters' => 0.0, 'duration_seconds' => 0.0, 'load' => 0.0];
        $road = ['count' => 0, 'distance_meters' => 0.0, 'duration_seconds' => 0.0, 'load' => 0.0];
        $mtb = ['count' => 0, 'distance_meters' => 0.0, 'elevation_gain' => 0.0, 'duration_seconds' => 0.0, 'load' => 0.0];
        $other = ['count' => 0, 'distance_meters' => 0.0, 'duration_seconds' => 0.0, 'load' => 0.0];

        foreach ($activities as $activity) {
            $load = $activity->training_load ?? 0.0;
            $distance = $activity->distance_meters;
            $duration = $activity->duration_seconds;
            $elevation = $activity->elevation_gain ?? 0.0;

            $totalLoad += $load;
            $totalDuration += $duration;
            $totalDistance += $distance;

            if ($activity->sport_category === 'running') {
                $running['count']++;
                $running['distance_meters'] += $distance;
                $running['duration_seconds'] += $duration;
                $running['load'] += $load;
            } elseif ($activity->sport_category === 'biking' && $activity->effectiveDiscipline() === 'road') {
                $road['count']++;
                $road['distance_meters'] += $distance;
                $road['duration_seconds'] += $duration;
                $road['load'] += $load;
            } elseif ($activity->sport_category === 'biking' && $activity->effectiveDiscipline() === 'mtb') {
                $mtb['count']++;
                $mtb['distance_meters'] += $distance;
                $mtb['elevation_gain'] += $elevation;
                $mtb['duration_seconds'] += $duration;
                $mtb['load'] += $load;
            } else {
                $other['count']++;
                $other['distance_meters'] += $distance;
                $other['duration_seconds'] += $duration;
                $other['load'] += $load;
            }

            $canonicalKey = $registry->resolveActivitySportKey($activity);
            $targetKey = null;

            if (isset($dynamicBuckets[$canonicalKey])) {
                $targetKey = $canonicalKey;
            } elseif ($activity->sport_category === 'running' && isset($dynamicBuckets['running'])) {
                $targetKey = 'running';
            } elseif ($activity->sport_category === 'biking' && isset($dynamicBuckets['cycling'])) {
                $targetKey = 'cycling';
            }

            if ($targetKey !== null && isset($dynamicBuckets[$targetKey])) {
                $bucket = $dynamicBuckets[$targetKey];
                $bucket['count']++;
                $bucket['distance_meters'] += $distance;
                $bucket['elevation_gain'] += $elevation;
                $bucket['duration_seconds'] += $duration;
                $bucket['load'] += $load;
                $dynamicBuckets[$targetKey] = $bucket;
            } else {
                $dynamicOther['count']++;
                $dynamicOther['distance_meters'] += $distance;
                $dynamicOther['elevation_gain'] += $elevation;
                $dynamicOther['duration_seconds'] += $duration;
                $dynamicOther['load'] += $load;
            }
        }

        $bySportList = [];

        foreach ($dynamicBuckets as $bucket) {
            $bySportList[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'short_label' => $bucket['short_label'],
                'accent_hex' => $bucket['accent_hex'],
                'count' => $bucket['count'],
                'distance_meters' => round($bucket['distance_meters'], 1),
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'load' => round($bucket['load'], 1),
            ];
        }

        if ($dynamicOther['count'] > 0) {
            $bySportList[] = [
                'key' => 'other',
                'label' => 'Other',
                'short_label' => 'Other',
                'accent_hex' => '#71717a',
                'count' => $dynamicOther['count'],
                'distance_meters' => round($dynamicOther['distance_meters'], 1),
                'elevation_gain' => round($dynamicOther['elevation_gain'], 1),
                'duration_seconds' => round($dynamicOther['duration_seconds'], 1),
                'load' => round($dynamicOther['load'], 1),
            ];
        }

        return [
            'total_load' => round($totalLoad, 1),
            'total_duration_seconds' => round($totalDuration, 1),
            'total_distance_meters' => round($totalDistance, 1),
            'activity_count' => $activities->count(),
            'by_sport' => [
                'running' => [
                    'count' => $running['count'],
                    'distance_meters' => round($running['distance_meters'], 1),
                    'duration_seconds' => round($running['duration_seconds'], 1),
                    'load' => round($running['load'], 1),
                ],
                'road' => [
                    'count' => $road['count'],
                    'distance_meters' => round($road['distance_meters'], 1),
                    'duration_seconds' => round($road['duration_seconds'], 1),
                    'load' => round($road['load'], 1),
                ],
                'mtb' => [
                    'count' => $mtb['count'],
                    'distance_meters' => round($mtb['distance_meters'], 1),
                    'elevation_gain' => round($mtb['elevation_gain'], 1),
                    'duration_seconds' => round($mtb['duration_seconds'], 1),
                    'load' => round($mtb['load'], 1),
                ],
                'other' => [
                    'count' => $other['count'],
                    'distance_meters' => round($other['distance_meters'], 1),
                    'duration_seconds' => round($other['duration_seconds'], 1),
                    'load' => round($other['load'], 1),
                ],
            ],
            'by_sport_list' => $bySportList,
            'goals' => $this->goalProgressService->activeGoals(
                sport: null,
                period: 'weekly',
                referenceDate: $selectedDate,
            ),
        ];
    }

    /**
     * @return array{
     *     steps: array{count: ?int, goal: ?int, distanceMeters: ?float},
     *     heartRate: array{
     *         resting: ?int,
     *         sevenDayAvg: ?int,
     *         min: ?int,
     *         max: ?int,
     *         series: list<array{0: int, 1: int}>
     *     },
     *     stress: array{
     *         avg: ?int,
     *         max: ?int,
     *         qualifier: ?string,
     *         restSeconds: ?int,
     *         lowSeconds: ?int,
     *         mediumSeconds: ?int,
     *         highSeconds: ?int,
     *         restPct: ?int
     *     },
     *     intensityMinutes: array{
     *         moderate: ?int,
     *         vigorous: ?int,
     *         total: ?int,
     *         goal: ?int,
     *         dailyTotal: ?int,
     *         weekStart: string
     *     },
     *     weight: array{
     *         weightKg: ?float,
     *         goalKg: ?float,
     *         deltaLastKg: ?float,
     *         delta30dKg: ?float,
     *         bmi: ?float,
     *         bodyFatPct: ?float,
     *         bodyWaterPct: ?float,
     *         muscleMassKg: ?float,
     *         boneMassKg: ?float,
     *         measuredDate: ?string
     *     },
     *     vo2Fitness: array{
     *         runningVo2: ?float,
     *         cyclingVo2: ?float,
     *         fitnessAge: ?float,
     *         chronologicalAge: ?float,
     *         delta: ?float,
     *         fitnessAgeDate: ?string
     *     },
     *     healthStatus: array{
     *         status: ?string,
     *         tone: string,
     *         summary: string,
     *         inRangeCount: int,
     *         checkedCount: int,
     *         metrics: list<array{key: string, label: string, inRange: bool, detail: string}>
     *     },
     *     calories: array{
     *         total: ?int,
     *         active: ?int,
     *         bmr: ?int,
     *         rawTotal: ?int,
     *         rawActive: ?int,
     *         rawBmr: ?int,
     *         dayNote: ?string
     *     },
     *     floors: array{ascended: ?float, descended: ?float, goal: ?int},
     *     respirationSpo2: array{
     *         avgRespiration: ?float,
     *         minRespiration: ?float,
     *         maxRespiration: ?float,
     *         avgSpo2: ?float,
     *         lowestSpo2: ?float,
     *         latestSpo2: ?float
     *     },
     *     hydration: array{ml: ?int}
     * }
     */
    private function buildAtAGlance(
        ?DailyMetric $dailyMetric,
        ?HrvRecord $hrvRecord,
        CarbonImmutable $selectedDate,
        string $todayStr,
        string $timezone,
    ): array {
        $selectedDateStr = $selectedDate->format('Y-m-d');

        $restSeconds = $dailyMetric?->rest_stress_seconds;
        $lowSeconds = $dailyMetric?->low_stress_seconds;
        $mediumSeconds = $dailyMetric?->medium_stress_seconds;
        $highSeconds = $dailyMetric?->high_stress_seconds;
        $totalStressSeconds = ($restSeconds ?? 0) + ($lowSeconds ?? 0) + ($mediumSeconds ?? 0) + ($highSeconds ?? 0);
        $restPct = ($restSeconds !== null && $totalStressSeconds > 0)
            ? (int) round(($restSeconds / $totalStressSeconds) * 100)
            : null;

        $stressQualifier = $dailyMetric?->stress_qualifier;

        if ($stressQualifier !== null && strtoupper(trim($stressQualifier)) === 'UNKNOWN') {
            $stressQualifier = null;
        }

        $normalizedCalories = $dailyMetric?->normalizedCalories($todayStr);

        return [
            'steps' => [
                'count' => $dailyMetric?->steps,
                'goal' => $dailyMetric?->step_goal,
                'distanceMeters' => $dailyMetric?->distance_meters,
            ],
            'heartRate' => [
                'resting' => $dailyMetric?->resting_hr,
                'sevenDayAvg' => $dailyMetric?->seven_day_avg_rhr,
                'min' => $dailyMetric?->min_hr,
                'max' => $dailyMetric?->max_hr,
                'series' => $this->normalizeIntradaySeries($dailyMetric?->heart_rate_series),
            ],
            'stress' => [
                'avg' => $dailyMetric?->avg_stress,
                'max' => $dailyMetric?->max_stress,
                'qualifier' => $stressQualifier,
                'restSeconds' => $restSeconds,
                'lowSeconds' => $lowSeconds,
                'mediumSeconds' => $mediumSeconds,
                'highSeconds' => $highSeconds,
                'restPct' => $restPct,
            ],
            'intensityMinutes' => $this->buildIntensityMinutes($dailyMetric, $selectedDate),
            'weight' => $this->buildWeight($selectedDate, $timezone),
            'vo2Fitness' => $this->buildVo2Fitness($selectedDateStr),
            'healthStatus' => $this->healthStatusService->forDate($selectedDate, $dailyMetric, $hrvRecord),
            'calories' => [
                'total' => $normalizedCalories['total'] ?? null,
                'active' => $normalizedCalories['active'] ?? null,
                'bmr' => $normalizedCalories['bmr'] ?? null,
                'rawTotal' => $normalizedCalories['rawTotal'] ?? null,
                'rawActive' => $normalizedCalories['rawActive'] ?? null,
                'rawBmr' => $normalizedCalories['rawBmr'] ?? null,
                'dayNote' => ($normalizedCalories['travelDayLabel'] ?? null) !== null
                    ? "{$normalizedCalories['travelDayLabel']} → 24h"
                    : null,
            ],
            'floors' => [
                'ascended' => $dailyMetric?->floors_ascended,
                'descended' => $dailyMetric?->floors_descended,
                'goal' => $dailyMetric?->floors_goal,
            ],
            'respirationSpo2' => [
                'avgRespiration' => $dailyMetric?->avg_respiration,
                'minRespiration' => $dailyMetric?->min_respiration,
                'maxRespiration' => $dailyMetric?->max_respiration,
                'avgSpo2' => $dailyMetric?->avg_spo2,
                'lowestSpo2' => $dailyMetric?->lowest_spo2,
                'latestSpo2' => $dailyMetric?->latest_spo2,
            ],
            'hydration' => [
                'ml' => $dailyMetric?->hydration_ml,
            ],
        ];
    }

    /**
     * Aggregate Mon–Sun week-to-date intensity minutes as of the selected date
     * so totals align with Garmin's weekly intensity minutes target.
     *
     * @return array{
     *     moderate: ?int,
     *     vigorous: ?int,
     *     total: ?int,
     *     goal: ?int,
     *     dailyTotal: ?int,
     *     weekStart: string
     * }
     */
    private function buildIntensityMinutes(?DailyMetric $dailyMetric, CarbonImmutable $selectedDate): array
    {
        $weekStartStr = $selectedDate->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');
        $selectedDateStr = $selectedDate->format('Y-m-d');

        $weekRecords = DailyMetric::query()
            ->whereBetween('calendar_date', [$weekStartStr, $selectedDateStr])
            ->orderBy('calendar_date')
            ->get([
                'calendar_date',
                'moderate_intensity_minutes',
                'vigorous_intensity_minutes',
                'intensity_minutes_goal',
            ]);

        $hasData = false;
        $weeklyModerate = 0;
        $weeklyVigorous = 0;
        $latestGoal = null;

        foreach ($weekRecords as $record) {
            if ($record->moderate_intensity_minutes !== null || $record->vigorous_intensity_minutes !== null) {
                $hasData = true;
                $weeklyModerate += $record->moderate_intensity_minutes ?? 0;
                $weeklyVigorous += $record->vigorous_intensity_minutes ?? 0;
            }

            if ($record->intensity_minutes_goal !== null) {
                $latestGoal = $record->intensity_minutes_goal;
            }
        }

        $dailyModerate = $dailyMetric?->moderate_intensity_minutes;
        $dailyVigorous = $dailyMetric?->vigorous_intensity_minutes;
        $dailyTotal = ($dailyModerate !== null || $dailyVigorous !== null)
            ? ($dailyModerate ?? 0) + (($dailyVigorous ?? 0) * 2)
            : null;

        $dailyGoal = $dailyMetric?->intensity_minutes_goal;

        return [
            'moderate' => $hasData ? $weeklyModerate : null,
            'vigorous' => $hasData ? $weeklyVigorous : null,
            'total' => $hasData ? $weeklyModerate + ($weeklyVigorous * 2) : null,
            'goal' => $dailyGoal ?? $latestGoal,
            'dailyTotal' => $dailyTotal,
            'weekStart' => $weekStartStr,
        ];
    }

    /**
     * @return array{
     *     weightKg: ?float,
     *     goalKg: ?float,
     *     deltaLastKg: ?float,
     *     delta30dKg: ?float,
     *     bmi: ?float,
     *     bodyFatPct: ?float,
     *     bodyWaterPct: ?float,
     *     muscleMassKg: ?float,
     *     boneMassKg: ?float,
     *     measuredDate: ?string
     * }
     */
    private function buildWeight(CarbonImmutable $selectedDate, string $timezone): array
    {
        $selectedDateStr = $selectedDate->format('Y-m-d');

        $effectiveGoal = WeightGoal::query()
            ->where('start_date', '<=', $selectedDateStr)
            ->where(function ($query) use ($selectedDateStr): void {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $selectedDateStr);
            })
            ->orderByDesc('start_date')
            ->first();

        $goalKg = $effectiveGoal !== null
            ? round($effectiveGoal->goal_grams / 1000, 1)
            : null;

        $latest = WeightMeasurement::query()
            ->where('calendar_date', '<=', $selectedDateStr)
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return [
                'weightKg' => null,
                'goalKg' => $goalKg,
                'deltaLastKg' => null,
                'delta30dKg' => null,
                'bmi' => null,
                'bodyFatPct' => null,
                'bodyWaterPct' => null,
                'muscleMassKg' => null,
                'boneMassKg' => null,
                'measuredDate' => null,
            ];
        }

        $previousMeasurement = WeightMeasurement::query()
            ->where('id', '!=', $latest->id)
            ->where('calendar_date', '<', $latest->calendar_date)
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        $deltaLastKg = $previousMeasurement !== null
            ? round(($latest->weight_grams - $previousMeasurement->weight_grams) / 1000, 2)
            : null;

        $prior = $this->findClosestWeightAround30DaysPrior($latest, $selectedDate, $timezone);
        $delta30dKg = $prior !== null
            ? round(($latest->weight_grams - $prior->weight_grams) / 1000, 2)
            : null;

        return [
            'weightKg' => round($latest->weight_grams / 1000, 2),
            'goalKg' => $goalKg,
            'deltaLastKg' => $deltaLastKg,
            'delta30dKg' => $delta30dKg,
            'bmi' => $latest->bmi,
            'bodyFatPct' => $latest->body_fat_pct,
            'bodyWaterPct' => $latest->body_water_pct,
            'muscleMassKg' => $latest->muscle_mass_grams !== null ? round($latest->muscle_mass_grams / 1000, 2) : null,
            'boneMassKg' => $latest->bone_mass_grams !== null ? round($latest->bone_mass_grams / 1000, 2) : null,
            'measuredDate' => $latest->calendar_date,
        ];
    }

    private function findClosestWeightAround30DaysPrior(
        WeightMeasurement $latest,
        CarbonImmutable $selectedDate,
        string $timezone,
    ): ?WeightMeasurement {
        $targetDate = $selectedDate->subDays(30);
        $targetStr = $targetDate->format('Y-m-d');

        $before = WeightMeasurement::query()
            ->where('id', '!=', $latest->id)
            ->where('calendar_date', '<=', $targetStr)
            ->where('calendar_date', '>=', $targetDate->subDays(30)->format('Y-m-d'))
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        $after = WeightMeasurement::query()
            ->where('id', '!=', $latest->id)
            ->where('calendar_date', '>', $targetStr)
            ->where('calendar_date', '<=', $targetDate->addDays(15)->format('Y-m-d'))
            ->where('calendar_date', '<', $latest->calendar_date)
            ->orderBy('calendar_date')
            ->orderBy('measured_at')
            ->orderBy('id')
            ->first();

        if ($before === null) {
            return $after;
        }

        if ($after === null) {
            return $before;
        }

        $beforeDate = $this->parseDateString($before->calendar_date, $timezone);
        $afterDate = $this->parseDateString($after->calendar_date, $timezone);

        if ($beforeDate === null) {
            return $after;
        }

        if ($afterDate === null) {
            return $before;
        }

        $beforeDiff = abs($targetDate->diffInDays($beforeDate));
        $afterDiff = abs($targetDate->diffInDays($afterDate));

        return $beforeDiff <= $afterDiff ? $before : $after;
    }

    /**
     * @return array{
     *     runningVo2: ?float,
     *     cyclingVo2: ?float,
     *     fitnessAge: ?float,
     *     chronologicalAge: ?float,
     *     delta: ?float,
     *     fitnessAgeDate: ?string
     * }
     */
    private function buildVo2Fitness(string $selectedDateStr): array
    {
        $runningRecord = Vo2MaxRecord::query()
            ->where('sport', 'running')
            ->where('calendar_date', '<=', $selectedDateStr)
            ->orderByDesc('calendar_date')
            ->first();

        $cyclingRecord = Vo2MaxRecord::query()
            ->where('sport', 'cycling')
            ->where('calendar_date', '<=', $selectedDateStr)
            ->orderByDesc('calendar_date')
            ->first();

        $fitnessAgeRecord = FitnessAgeRecord::query()
            ->where('calendar_date', '<=', $selectedDateStr)
            ->whereNotNull('fitness_age')
            ->orderByDesc('calendar_date')
            ->first();

        $runningVo2 = $runningRecord !== null
            ? ($runningRecord->vo2_max_precise ?? $runningRecord->vo2_max)
            : null;

        $cyclingVo2 = $cyclingRecord !== null
            ? ($cyclingRecord->vo2_max_precise ?? $cyclingRecord->vo2_max)
            : null;

        $fitnessAge = $fitnessAgeRecord?->fitness_age;
        $chronologicalAge = $fitnessAgeRecord?->chronological_age;
        $delta = ($fitnessAge !== null && $chronologicalAge !== null)
            ? round($fitnessAge - $chronologicalAge, 1)
            : null;

        return [
            'runningVo2' => $runningVo2,
            'cyclingVo2' => $cyclingVo2,
            'fitnessAge' => $fitnessAge,
            'chronologicalAge' => $chronologicalAge,
            'delta' => $delta,
            'fitnessAgeDate' => $fitnessAgeRecord?->calendar_date,
        ];
    }

    /**
     * @param  array<int|string, mixed>|null  $rawSeries
     * @return list<array{0: int, 1: int}>
     */
    private function normalizeIntradaySeries(?array $rawSeries): array
    {
        if ($rawSeries === null) {
            return [];
        }

        $series = [];

        foreach ($rawSeries as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1])) {
                continue;
            }

            if (! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }

            $series[] = [(int) $point[0], (int) $point[1]];
        }

        return $series;
    }
}
