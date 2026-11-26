<?php

namespace App\Services\Health;

use App\Models\DailyMetric;
use App\Models\FitnessAgeRecord;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Models\Vo2MaxRecord;
use App\Models\WeightMeasurement;
use App\Support\AppSettings;
use App\Support\DateWindowResolver;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class HealthMetricService
{
    private const float KG_TO_LB = 2.2046226218;

    private const float KM_TO_MI = 0.6213711922;

    private const float ML_TO_FLOZ = 0.0338140227;

    public function __construct(
        private readonly ?AppSettings $settings = null,
        private readonly ?DateWindowResolver $dateWindows = null,
        private readonly ?HealthSourceRegistry $healthSources = null,
    ) {}

    private function dateWindowResolver(): DateWindowResolver
    {
        return $this->dateWindows ?? app(DateWindowResolver::class);
    }

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    private function isImperial(): bool
    {
        return ($this->settings ?? app(AppSettings::class))->isImperial();
    }

    private const int MAX_TABLE_ROWS = 90;

    /**
     * @var list<string>
     */
    private const array VALID_RANGES = ['7d', '28d', '90d', '1y', 'all', 'custom'];

    /**
     * @var array<string, array{
     *     title: string,
     *     subtitle: string,
     *     accent: string,
     *     unit: string,
     *     chartType: string
     * }>
     */
    private const array METRICS = [
        'body-battery' => [
            'title' => 'Body Battery',
            'subtitle' => 'Daily energy reserve range, charging, and drain',
            'accent' => 'sky',
            'unit' => 'pts',
            'chartType' => 'range-bar',
        ],
        'sleep' => [
            'title' => 'Sleep',
            'subtitle' => 'Nightly duration, sleep stages, and recovery score',
            'accent' => 'indigo',
            'unit' => 'h',
            'chartType' => 'sleep-stages',
        ],
        'hrv' => [
            'title' => 'HRV Status',
            'subtitle' => 'Overnight heart rate variability and balanced baseline',
            'accent' => 'emerald',
            'unit' => 'ms',
            'chartType' => 'hrv-band',
        ],
        'steps' => [
            'title' => 'Steps',
            'subtitle' => 'Daily step count, distance, and goal attainment',
            'accent' => 'sky',
            'unit' => 'steps',
            'chartType' => 'bar-goal',
        ],
        'heart-rate' => [
            'title' => 'Heart Rate',
            'subtitle' => 'Resting heart rate, 7-day trend, and daily range',
            'accent' => 'rose',
            'unit' => 'bpm',
            'chartType' => 'hr-range',
        ],
        'stress' => [
            'title' => 'Stress',
            'subtitle' => 'Average stress score and daily share of time by stress level',
            'accent' => 'amber',
            'unit' => 'score',
            'chartType' => 'stress-breakdown',
        ],
        'intensity-minutes' => [
            'title' => 'Intensity Minutes',
            'subtitle' => 'Moderate and vigorous active minutes toward weekly target',
            'accent' => 'amber',
            'unit' => 'min',
            'chartType' => 'intensity-stacked',
        ],
        'weight' => [
            'title' => 'Weight & Body Composition',
            'subtitle' => 'Body mass, BMI, body fat, muscle, and hydration trends',
            'accent' => 'emerald',
            'unit' => 'kg',
            'chartType' => 'weight-comp',
        ],
        'vo2-max' => [
            'title' => 'VO2 Max',
            'subtitle' => 'Aerobic capacity trends for running and cycling',
            'accent' => 'indigo',
            'unit' => 'mL/kg/min',
            'chartType' => 'vo2-dual',
        ],
        'fitness-age' => [
            'title' => 'Fitness Age',
            'subtitle' => 'Estimated fitness age against chronological age and contributing factors',
            'accent' => 'violet',
            'unit' => 'yrs',
            'chartType' => 'fitness-age',
        ],
        'calories' => [
            'title' => 'Calories',
            'subtitle' => 'Total daily expenditure split between active and BMR burn',
            'accent' => 'orange',
            'unit' => 'kcal',
            'chartType' => 'calories-stacked',
        ],
        'floors' => [
            'title' => 'Floors Climbed',
            'subtitle' => 'Daily floors ascended, descended, and daily goal',
            'accent' => 'teal',
            'unit' => 'floors',
            'chartType' => 'bar-goal',
        ],
        'respiration-spo2' => [
            'title' => 'Respiration & SpO2',
            'subtitle' => 'Breathing rate and blood oxygen saturation trends',
            'accent' => 'teal',
            'unit' => 'brpm / %',
            'chartType' => 'dual-line',
        ],
        'hydration' => [
            'title' => 'Hydration',
            'subtitle' => 'Logged daily fluid intake',
            'accent' => 'sky',
            'unit' => 'mL',
            'chartType' => 'bar',
        ],
    ];

    public function isSupported(string $metric): bool
    {
        return array_key_exists($metric, self::METRICS);
    }

    /**
     * @return list<string>
     */
    public function supportedMetrics(): array
    {
        return array_keys(self::METRICS);
    }

    public function normalizeRange(?string $range): string
    {
        if ($range !== null && in_array($range, self::VALID_RANGES, true)) {
            return $range;
        }

        return '28d';
    }

    /**
     * Build the drilldown payload for a supported health metric and date range.
     *
     * @return array{
     *     metric: string,
     *     title: string,
     *     subtitle: string,
     *     accent: string,
     *     unit: string,
     *     chartType: string,
     *     range: string,
     *     startDate: ?string,
     *     endDate: string,
     *     dateWindow: array{
     *         range: string,
     *         start: ?string,
     *         end: string,
     *         from: ?string,
     *         to: ?string,
     *         prevEnd: ?string,
     *         nextEnd: ?string,
     *         isLatest: bool,
     *         label: string
     *     },
     *     summary: array{
     *         latest: ?string,
     *         average: ?string,
     *         min: ?string,
     *         max: ?string,
     *         extraLabel: ?string,
     *         extraValue: ?string
     *     },
     *     baseline: ?array{low: ?int, high: ?int},
     *     priorPoint: ?array<string, mixed>,
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    public function forMetric(
        string $metric,
        ?string $range = null,
        ?string $end = null,
        ?string $from = null,
        ?string $to = null,
    ): array {
        if (! $this->isSupported($metric)) {
            throw new InvalidArgumentException("Unsupported health metric: {$metric}");
        }

        $meta = self::METRICS[$metric];
        $timezone = (string) config('app.display_timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dateWindow = $this->dateWindowResolver()->resolveDayWindow(
            $range,
            $end,
            $from,
            $to,
            $today,
            self::VALID_RANGES,
            '28d',
        );
        $normalizedRange = $dateWindow['range'];
        $startDate = $dateWindow['start'];
        $endDate = $dateWindow['end'];

        $data = match ($metric) {
            'body-battery' => $this->buildBodyBattery($startDate, $endDate),
            'sleep' => $this->buildSleep($startDate, $endDate),
            'hrv' => $this->buildHrv($startDate, $endDate),
            'steps' => $this->buildSteps($startDate, $endDate),
            'heart-rate' => $this->buildHeartRate($startDate, $endDate),
            'stress' => $this->buildStress($startDate, $endDate),
            'intensity-minutes' => $this->buildIntensityMinutes($startDate, $endDate, $normalizedRange),
            'weight' => $this->buildWeight($startDate, $endDate),
            'vo2-max' => $this->buildVo2Max($startDate, $endDate),
            'fitness-age' => $this->buildFitnessAge($startDate, $endDate),
            'calories' => $this->buildCalories($startDate, $endDate),
            'floors' => $this->buildFloors($startDate, $endDate),
            'respiration-spo2' => $this->buildRespirationSpo2($startDate, $endDate),
            'hydration' => $this->buildHydration($startDate, $endDate),
            default => throw new InvalidArgumentException("Unsupported health metric: {$metric}"),
        };

        $isImperial = $this->isImperial();
        $unit = match ($metric) {
            'weight' => $isImperial ? 'lb' : 'kg',
            'hydration' => $isImperial ? 'fl oz' : 'mL',
            default => $meta['unit'],
        };

        return [
            'metric' => $metric,
            'title' => $meta['title'],
            'subtitle' => $meta['subtitle'],
            'accent' => $meta['accent'],
            'unit' => $unit,
            'chartType' => $meta['chartType'],
            'range' => $normalizedRange,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dateWindow' => $dateWindow,
            'summary' => $data['summary'],
            'baseline' => $data['baseline'],
            'priorPoint' => $data['priorPoint'] ?? null,
            'points' => $data['points'],
            'columns' => $data['columns'],
            'rows' => array_slice(array_reverse($data['rows']), 0, self::MAX_TABLE_ROWS),
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildBodyBattery(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('body_battery_current')
                    ->orWhereNotNull('body_battery_high')
                    ->orWhereNotNull('body_battery_low')
                    ->orWhereNotNull('body_battery_charged')
                    ->orWhereNotNull('body_battery_drained');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $highValues = [];
        $lowValues = [];
        $chargedValues = [];
        $latestValue = null;

        foreach ($records as $record) {
            $travelDayLabel = $record->travelDayLabel($endDate);

            $points[] = [
                'date' => $record->calendar_date,
                'low' => $record->body_battery_low,
                'high' => $record->body_battery_high,
                'current' => $record->body_battery_current,
                'charged' => $record->body_battery_charged,
                'drained' => $record->body_battery_drained,
                'sleep' => $record->body_battery_sleep,
                'dayHours' => $travelDayLabel !== null ? $record->dayDurationHours() : null,
                'travelDayLabel' => $travelDayLabel,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'dayNote' => $travelDayLabel,
                'current' => $record->body_battery_current,
                'high' => $record->body_battery_high,
                'low' => $record->body_battery_low,
                'charged' => $record->body_battery_charged !== null ? '+'.$record->body_battery_charged : null,
                'drained' => $record->body_battery_drained !== null ? '-'.$record->body_battery_drained : null,
                'sleep' => $record->body_battery_sleep,
            ];

            if ($record->body_battery_high !== null) {
                $highValues[] = $record->body_battery_high;
            }

            if ($record->body_battery_low !== null) {
                $lowValues[] = $record->body_battery_low;
            }

            if ($record->body_battery_charged !== null) {
                $chargedValues[] = $record->body_battery_charged;
            }

            $candidateLatest = $record->body_battery_current ?? $record->body_battery_high;

            if ($candidateLatest !== null) {
                $latestValue = $candidateLatest;
            }
        }

        $avgHigh = $this->average($highValues);
        $avgCharged = $this->average($chargedValues);

        return [
            'summary' => [
                'latest' => $latestValue !== null ? "{$latestValue} pts" : null,
                'average' => $avgHigh !== null ? ((int) round($avgHigh)).' pts' : null,
                'min' => $lowValues !== [] ? min($lowValues).' pts' : null,
                'max' => $highValues !== [] ? max($highValues).' pts' : null,
                'extraLabel' => 'Avg Charged',
                'extraValue' => $avgCharged !== null ? '+'.((int) round($avgCharged)).' pts' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'current', 'label' => 'Latest'],
                ['key' => 'high', 'label' => 'High'],
                ['key' => 'low', 'label' => 'Low'],
                ['key' => 'charged', 'label' => 'Charged'],
                ['key' => 'drained', 'label' => 'Drained'],
                ['key' => 'sleep', 'label' => 'Sleep'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildSleep(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(SleepRecord::query(), 'sleep')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('sleep_seconds')
                    ->orWhereNotNull('sleep_score');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $durations = [];
        $scores = [];

        foreach ($records as $record) {
            $points[] = [
                'date' => $record->calendar_date,
                'total' => round(($record->sleep_seconds ?? 0) / 3600, 2),
                'deep' => round(($record->deep_seconds ?? 0) / 3600, 2),
                'light' => round(($record->light_seconds ?? 0) / 3600, 2),
                'rem' => round(($record->rem_seconds ?? 0) / 3600, 2),
                'awake' => round(($record->awake_seconds ?? 0) / 3600, 2),
                'score' => $record->sleep_score,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'total' => $record->sleep_seconds !== null ? $this->formatDurationHoursMinutes($record->sleep_seconds) : null,
                'score' => $record->sleep_score,
                'deep' => $record->deep_seconds !== null ? $this->formatDurationHoursMinutes($record->deep_seconds) : null,
                'light' => $record->light_seconds !== null ? $this->formatDurationHoursMinutes($record->light_seconds) : null,
                'rem' => $record->rem_seconds !== null ? $this->formatDurationHoursMinutes($record->rem_seconds) : null,
                'awake' => $record->awake_seconds !== null ? $this->formatDurationHoursMinutes($record->awake_seconds) : null,
            ];

            if ($record->sleep_seconds !== null) {
                $durations[] = $record->sleep_seconds;
            }

            if ($record->sleep_score !== null) {
                $scores[] = $record->sleep_score;
            }
        }

        $avgDuration = $this->average($durations);
        $avgScore = $this->average($scores);

        return [
            'summary' => [
                'latest' => $durations !== [] ? $this->formatDurationHoursMinutes($durations[count($durations) - 1]) : null,
                'average' => $avgDuration !== null ? $this->formatDurationHoursMinutes((int) round($avgDuration)) : null,
                'min' => $durations !== [] ? $this->formatDurationHoursMinutes(min($durations)) : null,
                'max' => $durations !== [] ? $this->formatDurationHoursMinutes(max($durations)) : null,
                'extraLabel' => 'Avg Score',
                'extraValue' => $avgScore !== null ? ((int) round($avgScore)).' / 100' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'total', 'label' => 'Duration'],
                ['key' => 'score', 'label' => 'Score'],
                ['key' => 'deep', 'label' => 'Deep'],
                ['key' => 'light', 'label' => 'Light'],
                ['key' => 'rem', 'label' => 'REM'],
                ['key' => 'awake', 'label' => 'Awake'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildHrv(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(HrvRecord::query(), 'hrv')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('last_night_avg')
                    ->orWhereNotNull('weekly_avg');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $lastNightValues = [];
        $latestRecord = null;

        foreach ($records as $record) {
            $latestRecord = $record;

            $points[] = [
                'date' => $record->calendar_date,
                'lastNight' => $record->last_night_avg,
                'weeklyAvg' => $record->weekly_avg,
                'fiveMinHigh' => $record->last_night_5min_high,
                'baselineLow' => $record->baseline_balanced_low,
                'baselineHigh' => $record->baseline_balanced_upper,
            ];

            $baselineLabel = ($record->baseline_balanced_low !== null && $record->baseline_balanced_upper !== null)
                ? "{$record->baseline_balanced_low}–{$record->baseline_balanced_upper} ms"
                : null;

            $rows[] = [
                'date' => $record->calendar_date,
                'lastNight' => $record->last_night_avg !== null ? "{$record->last_night_avg} ms" : null,
                'weeklyAvg' => $record->weekly_avg !== null ? "{$record->weekly_avg} ms" : null,
                'fiveMinHigh' => $record->last_night_5min_high !== null ? "{$record->last_night_5min_high} ms" : null,
                'status' => $record->status !== null ? ucfirst(strtolower($record->status)) : null,
                'baseline' => $baselineLabel,
            ];

            if ($record->last_night_avg !== null) {
                $lastNightValues[] = $record->last_night_avg;
            }
        }

        $avgLastNight = $this->average($lastNightValues);
        $baseline = ($latestRecord !== null && ($latestRecord->baseline_balanced_low !== null || $latestRecord->baseline_balanced_upper !== null))
            ? [
                'low' => $latestRecord->baseline_balanced_low,
                'high' => $latestRecord->baseline_balanced_upper,
            ]
            : null;

        return [
            'summary' => [
                'latest' => $lastNightValues !== [] ? $lastNightValues[count($lastNightValues) - 1].' ms' : null,
                'average' => $avgLastNight !== null ? ((int) round($avgLastNight)).' ms' : null,
                'min' => $lastNightValues !== [] ? min($lastNightValues).' ms' : null,
                'max' => $lastNightValues !== [] ? max($lastNightValues).' ms' : null,
                'extraLabel' => 'Status',
                'extraValue' => $latestRecord?->status !== null ? ucfirst(strtolower($latestRecord->status)) : null,
            ],
            'baseline' => $baseline,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'lastNight', 'label' => 'Last Night'],
                ['key' => 'weeklyAvg', 'label' => '7d Avg'],
                ['key' => 'fiveMinHigh', 'label' => '5m High'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'baseline', 'label' => 'Baseline'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildSteps(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->whereNotNull('steps')
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $values = [];
        $goalDays = 0;
        $goalHitDays = 0;

        foreach ($records as $record) {
            $steps = (int) $record->steps;
            $values[] = $steps;

            $goalPct = null;

            if ($record->step_goal !== null && $record->step_goal > 0) {
                $goalDays++;

                if ($steps >= $record->step_goal) {
                    $goalHitDays++;
                }

                $goalPct = ((int) round(($steps / $record->step_goal) * 100)).'%';
            }

            $distanceKm = $record->distance_meters !== null
                ? round($record->distance_meters / 1000, 2)
                : null;
            $travelDayLabel = $record->travelDayLabel($endDate);

            $points[] = [
                'date' => $record->calendar_date,
                'value' => $steps,
                'goal' => $record->step_goal,
                'distanceKm' => $distanceKm,
                'dayHours' => $travelDayLabel !== null ? $record->dayDurationHours() : null,
                'travelDayLabel' => $travelDayLabel,
            ];

            $isImperial = $this->isImperial();
            $distanceFormatted = null;

            if ($distanceKm !== null) {
                $distanceFormatted = $isImperial
                    ? number_format($distanceKm * self::KM_TO_MI, 2).' mi'
                    : number_format($distanceKm, 2).' km';
            }

            $rows[] = [
                'date' => $record->calendar_date,
                'dayNote' => $travelDayLabel,
                'steps' => number_format($steps),
                'goal' => $record->step_goal !== null ? number_format($record->step_goal) : null,
                'goalPct' => $goalPct,
                'distance' => $distanceFormatted,
            ];
        }

        $avgSteps = $this->average($values);
        $goalHitRate = $goalDays > 0
            ? sprintf('%d/%d (%d%%)', $goalHitDays, $goalDays, (int) round(($goalHitDays / $goalDays) * 100))
            : null;

        return [
            'summary' => [
                'latest' => $values !== [] ? number_format($values[count($values) - 1]) : null,
                'average' => $avgSteps !== null ? number_format((int) round($avgSteps)) : null,
                'min' => $values !== [] ? number_format(min($values)) : null,
                'max' => $values !== [] ? number_format(max($values)) : null,
                'extraLabel' => 'Goal Hit Rate',
                'extraValue' => $goalHitRate,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'steps', 'label' => 'Steps'],
                ['key' => 'goal', 'label' => 'Goal'],
                ['key' => 'goalPct', 'label' => 'Goal %'],
                ['key' => 'distance', 'label' => 'Distance'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildHeartRate(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('resting_hr')
                    ->orWhereNotNull('min_hr')
                    ->orWhereNotNull('max_hr');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $restingValues = [];
        $maxValues = [];

        foreach ($records as $record) {
            $points[] = [
                'date' => $record->calendar_date,
                'resting' => $record->resting_hr,
                'sevenDayAvg' => $record->seven_day_avg_rhr,
                'min' => $record->min_hr,
                'max' => $record->max_hr,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'resting' => $record->resting_hr !== null ? "{$record->resting_hr} bpm" : null,
                'sevenDayAvg' => $record->seven_day_avg_rhr !== null ? "{$record->seven_day_avg_rhr} bpm" : null,
                'min' => $record->min_hr !== null ? "{$record->min_hr} bpm" : null,
                'max' => $record->max_hr !== null ? "{$record->max_hr} bpm" : null,
            ];

            if ($record->resting_hr !== null) {
                $restingValues[] = $record->resting_hr;
            }

            if ($record->max_hr !== null) {
                $maxValues[] = $record->max_hr;
            }
        }

        $avgResting = $this->average($restingValues);

        return [
            'summary' => [
                'latest' => $restingValues !== [] ? $restingValues[count($restingValues) - 1].' bpm' : null,
                'average' => $avgResting !== null ? ((int) round($avgResting)).' bpm' : null,
                'min' => $restingValues !== [] ? min($restingValues).' bpm' : null,
                'max' => $restingValues !== [] ? max($restingValues).' bpm' : null,
                'extraLabel' => 'Peak HR',
                'extraValue' => $maxValues !== [] ? max($maxValues).' bpm' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'resting', 'label' => 'Resting'],
                ['key' => 'sevenDayAvg', 'label' => '7d Avg'],
                ['key' => 'min', 'label' => 'Min'],
                ['key' => 'max', 'label' => 'Max'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildStress(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->whereNotNull('avg_stress')
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $avgStressValues = [];
        $restPcts = [];

        foreach ($records as $record) {
            $avgStress = (int) $record->avg_stress;
            $avgStressValues[] = $avgStress;

            $rest = $record->rest_stress_seconds ?? 0;
            $low = $record->low_stress_seconds ?? 0;
            $medium = $record->medium_stress_seconds ?? 0;
            $high = $record->high_stress_seconds ?? 0;
            $totalSeconds = $rest + $low + $medium + $high;

            $breakdownPcts = $this->calculateStressBreakdownPercentages($rest, $low, $medium, $high);
            $restPct = ($record->rest_stress_seconds !== null && $totalSeconds > 0)
                ? (int) round(($rest / $totalSeconds) * 100)
                : null;

            if ($restPct !== null) {
                $restPcts[] = $restPct;
            }

            $travelDayLabel = $record->travelDayLabel($endDate);

            $points[] = [
                'date' => $record->calendar_date,
                'avg' => $avgStress,
                'max' => $record->max_stress,
                'restPct' => $breakdownPcts['rest'],
                'lowPct' => $breakdownPcts['low'],
                'mediumPct' => $breakdownPcts['medium'],
                'highPct' => $breakdownPcts['high'],
                'restHours' => round($rest / 3600, 2),
                'lowHours' => round($low / 3600, 2),
                'mediumHours' => round($medium / 3600, 2),
                'highHours' => round($high / 3600, 2),
                'dayHours' => $travelDayLabel !== null ? $record->dayDurationHours() : null,
                'travelDayLabel' => $travelDayLabel,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'dayNote' => $travelDayLabel,
                'avg' => $avgStress,
                'max' => $record->max_stress,
                'qualifier' => $record->stress_qualifier !== null ? ucfirst(strtolower($record->stress_qualifier)) : null,
                'restPct' => $restPct !== null ? "{$restPct}%" : null,
                'highTime' => $record->high_stress_seconds !== null ? $this->formatDurationHoursMinutes($record->high_stress_seconds) : null,
            ];
        }

        $avgScore = $this->average($avgStressValues);
        $avgRestPct = $this->average($restPcts);

        return [
            'summary' => [
                'latest' => $avgStressValues !== [] ? (string) $avgStressValues[count($avgStressValues) - 1] : null,
                'average' => $avgScore !== null ? (string) ((int) round($avgScore)) : null,
                'min' => $avgStressValues !== [] ? (string) min($avgStressValues) : null,
                'max' => $avgStressValues !== [] ? (string) max($avgStressValues) : null,
                'extraLabel' => 'Avg Rest Time',
                'extraValue' => $avgRestPct !== null ? ((int) round($avgRestPct)).'%' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'avg', 'label' => 'Avg'],
                ['key' => 'max', 'label' => 'Max'],
                ['key' => 'qualifier', 'label' => 'Qualifier'],
                ['key' => 'restPct', 'label' => 'Rest %'],
                ['key' => 'highTime', 'label' => 'High Stress'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildIntensityMinutes(?string $startDate, string $endDate, string $range): array
    {
        if (in_array($range, ['7d', '28d', '90d'], true)) {
            return $this->buildDailyIntensityMinutes($startDate, $endDate, $range);
        }

        if ($range === 'custom' && $startDate !== null) {
            $spanDays = (int) CarbonImmutable::parse($startDate)->diffInDays(CarbonImmutable::parse($endDate)) + 1;

            if ($spanDays <= 90) {
                return $this->buildDailyIntensityMinutes($startDate, $endDate, $range);
            }
        }

        return $this->buildWeeklyIntensityMinutes($startDate, $endDate);
    }

    /**
     * Build daily intensity minutes with running Mon–Sun weekly cumulative progress
     * so daily cadence and weekly target attainment can be read together.
     *
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildDailyIntensityMinutes(?string $startDate, string $endDate, string $range): array
    {
        $windowStart = $startDate ?? CarbonImmutable::parse($endDate)->subDays(27)->format('Y-m-d');
        $queryStart = CarbonImmutable::parse($windowStart)
            ->startOfWeek(CarbonInterface::MONDAY)
            ->format('Y-m-d');

        $records = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '>=', $queryStart)
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('moderate_intensity_minutes')
                    ->orWhereNotNull('vigorous_intensity_minutes');
            })
            ->orderBy('calendar_date')
            ->get();

        $points = [];
        $rows = [];
        $totals = [];
        /** @var array<string, int> $weekRunningTotals */
        $weekRunningTotals = [];
        /** @var array<string, int> $weekRunningModerate */
        $weekRunningModerate = [];
        /** @var array<string, int> $weekRunningVigorous */
        $weekRunningVigorous = [];
        /** @var array<string, array{total: int, goal: ?int}> $windowWeeks */
        $windowWeeks = [];
        $latestWeekCumulative = null;
        $latestGoal = null;
        $endCarbon = CarbonImmutable::parse($endDate);

        foreach ($records as $record) {
            $moderate = $record->moderate_intensity_minutes ?? 0;
            $vigorous = $record->vigorous_intensity_minutes ?? 0;
            $vigorousWeighted = $vigorous * 2;
            $total = $moderate + $vigorousWeighted;

            $weekStartCarbon = CarbonImmutable::parse($record->calendar_date)
                ->startOfWeek(CarbonInterface::MONDAY);
            $weekKey = $weekStartCarbon->format('Y-m-d');
            $weekSunday = $weekStartCarbon->endOfWeek(CarbonInterface::SUNDAY);
            $weekEndStr = ($weekSunday->greaterThan($endCarbon) ? $endCarbon : $weekSunday)->format('Y-m-d');

            $weekRunningTotals[$weekKey] = ($weekRunningTotals[$weekKey] ?? 0) + $total;
            $weekRunningModerate[$weekKey] = ($weekRunningModerate[$weekKey] ?? 0) + $moderate;
            $weekRunningVigorous[$weekKey] = ($weekRunningVigorous[$weekKey] ?? 0) + $vigorous;

            if ($record->calendar_date < $windowStart) {
                continue;
            }

            $weeklyCumulative = $weekRunningTotals[$weekKey];
            $totals[] = $total;
            $latestWeekCumulative = $weeklyCumulative;

            if ($record->intensity_minutes_goal !== null) {
                $latestGoal = $record->intensity_minutes_goal;
            }

            $windowWeeks[$weekKey] = [
                'total' => $weeklyCumulative,
                'goal' => $record->intensity_minutes_goal ?? ($windowWeeks[$weekKey]['goal'] ?? null),
            ];

            $points[] = [
                'date' => $record->calendar_date,
                'weekStart' => $weekKey,
                'weekEnd' => $weekEndStr,
                'granularity' => 'daily',
                'moderate' => $moderate,
                'vigorous' => $vigorous,
                'vigorousWeighted' => $vigorousWeighted,
                'total' => $total,
                'weeklyCumulative' => $weeklyCumulative,
                'weekModerate' => $weekRunningModerate[$weekKey],
                'weekVigorous' => $weekRunningVigorous[$weekKey],
                'goal' => $record->intensity_minutes_goal,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'total' => "{$total} min",
                'moderate' => "{$moderate} min",
                'vigorous' => "{$vigorous} min",
                'weekTotal' => "{$weeklyCumulative} min",
                'goal' => $record->intensity_minutes_goal !== null ? "{$record->intensity_minutes_goal} min" : null,
            ];
        }

        $avgTotal = $this->average($totals);

        if ($range === '7d') {
            $extraLabel = 'Week Progress';
            $extraValue = null;

            if ($latestWeekCumulative !== null) {
                $extraValue = $latestGoal !== null
                    ? number_format($latestWeekCumulative).' / '.number_format($latestGoal).' min'
                    : number_format($latestWeekCumulative).' min';
            }
        } else {
            $goalWeeks = 0;
            $goalHitWeeks = 0;

            foreach ($windowWeeks as $weekSummary) {
                if ($weekSummary['goal'] !== null && $weekSummary['goal'] > 0) {
                    $goalWeeks++;

                    if ($weekSummary['total'] >= $weekSummary['goal']) {
                        $goalHitWeeks++;
                    }
                }
            }

            if ($goalWeeks > 0) {
                $extraLabel = 'Weekly Goals Hit';
                $extraValue = sprintf(
                    '%d/%d (%d%%)',
                    $goalHitWeeks,
                    $goalWeeks,
                    (int) round(($goalHitWeeks / $goalWeeks) * 100),
                );
            } else {
                $extraLabel = 'Period Total';
                $extraValue = $totals !== [] ? number_format(array_sum($totals)).' min' : null;
            }
        }

        return [
            'summary' => [
                'latest' => $totals !== [] ? $totals[count($totals) - 1].' min' : null,
                'average' => $avgTotal !== null ? ((int) round($avgTotal)).' min/day' : null,
                'min' => $totals !== [] ? min($totals).' min' : null,
                'max' => $totals !== [] ? max($totals).' min' : null,
                'extraLabel' => $extraLabel,
                'extraValue' => $extraValue,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'total', 'label' => 'Day Total'],
                ['key' => 'moderate', 'label' => 'Moderate'],
                ['key' => 'vigorous', 'label' => 'Vigorous'],
                ['key' => 'weekTotal', 'label' => 'Week Total'],
                ['key' => 'goal', 'label' => 'Weekly Goal'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Build ISO-week (Mon–Sun) aggregated intensity minutes directly comparable to the weekly target.
     *
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildWeeklyIntensityMinutes(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('moderate_intensity_minutes')
                    ->orWhereNotNull('vigorous_intensity_minutes');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $queryStart = CarbonImmutable::parse($startDate)
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');
            $query->where('calendar_date', '>=', $queryStart);
        }

        $records = $query->get();
        $endCarbon = CarbonImmutable::parse($endDate);

        /** @var array<string, array{weekStart: CarbonImmutable, moderate: int, vigorous: int, goal: ?int}> $weeks */
        $weeks = [];

        foreach ($records as $record) {
            $weekStart = CarbonImmutable::parse($record->calendar_date)->startOfWeek(CarbonInterface::MONDAY);
            $weekKey = $weekStart->format('Y-m-d');

            if (! isset($weeks[$weekKey])) {
                $weeks[$weekKey] = [
                    'weekStart' => $weekStart,
                    'moderate' => 0,
                    'vigorous' => 0,
                    'goal' => null,
                ];
            }

            $weeks[$weekKey]['moderate'] += $record->moderate_intensity_minutes ?? 0;
            $weeks[$weekKey]['vigorous'] += $record->vigorous_intensity_minutes ?? 0;

            if ($record->intensity_minutes_goal !== null) {
                $weeks[$weekKey]['goal'] = $record->intensity_minutes_goal;
            }
        }

        $points = [];
        $rows = [];
        $totals = [];
        $goalWeeks = 0;
        $goalHitWeeks = 0;

        foreach ($weeks as $weekKey => $week) {
            $weekStart = $week['weekStart'];
            $weekSunday = $weekStart->endOfWeek(CarbonInterface::SUNDAY);
            $displayEnd = $weekSunday->greaterThan($endCarbon) ? $endCarbon : $weekSunday;
            $weekEndStr = $displayEnd->format('Y-m-d');

            $moderate = $week['moderate'];
            $vigorous = $week['vigorous'];
            $vigorousWeighted = $vigorous * 2;
            $total = $moderate + $vigorousWeighted;
            $goal = $week['goal'];
            $totals[] = $total;

            $goalPct = null;

            if ($goal !== null && $goal > 0) {
                $goalWeeks++;

                if ($total >= $goal) {
                    $goalHitWeeks++;
                }

                $goalPct = ((int) round(($total / $goal) * 100)).'%';
            }

            $weekRangeLabel = $weekStart->year === $displayEnd->year
                ? $weekStart->format('j M').' – '.$displayEnd->format('j M Y')
                : $weekStart->format('j M Y').' – '.$displayEnd->format('j M Y');

            $points[] = [
                'date' => $weekKey,
                'weekEnd' => $weekEndStr,
                'granularity' => 'weekly',
                'moderate' => $moderate,
                'vigorous' => $vigorous,
                'vigorousWeighted' => $vigorousWeighted,
                'total' => $total,
                'goal' => $goal,
            ];

            $rows[] = [
                'week' => $weekRangeLabel,
                'total' => "{$total} min",
                'moderate' => "{$moderate} min",
                'vigorous' => "{$vigorous} min",
                'goal' => $goal !== null ? "{$goal} min" : null,
                'goalPct' => $goalPct,
            ];
        }

        $avgTotal = $this->average($totals);
        $goalHitRate = $goalWeeks > 0
            ? sprintf('%d/%d (%d%%)', $goalHitWeeks, $goalWeeks, (int) round(($goalHitWeeks / $goalWeeks) * 100))
            : null;

        return [
            'summary' => [
                'latest' => $totals !== [] ? $totals[count($totals) - 1].' min' : null,
                'average' => $avgTotal !== null ? ((int) round($avgTotal)).' min/wk' : null,
                'min' => $totals !== [] ? min($totals).' min' : null,
                'max' => $totals !== [] ? max($totals).' min' : null,
                'extraLabel' => $goalHitRate !== null ? 'Goal Hit Rate' : 'Period Total',
                'extraValue' => $goalHitRate ?? ($totals !== [] ? number_format(array_sum($totals)).' min' : null),
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'week', 'label' => 'Week'],
                ['key' => 'total', 'label' => 'Total'],
                ['key' => 'moderate', 'label' => 'Moderate'],
                ['key' => 'vigorous', 'label' => 'Vigorous'],
                ['key' => 'goal', 'label' => 'Weekly Goal'],
                ['key' => 'goalPct', 'label' => 'Goal %'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     priorPoint: ?array<string, mixed>,
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildWeight(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(WeightMeasurement::query(), 'weight')
            ->where('calendar_date', '<=', $endDate)
            ->orderBy('calendar_date')
            ->orderBy('measured_at')
            ->orderBy('id');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        /** @var array<string, WeightMeasurement> $dailyLatest */
        $dailyLatest = [];

        foreach ($records as $record) {
            $dailyLatest[$record->calendar_date] = $record;
        }

        $points = [];
        $rows = [];
        $weightsKg = [];

        $isImperial = $this->isImperial();
        $massFactor = $isImperial ? self::KG_TO_LB : 1.0;
        $massUnit = $isImperial ? 'lb' : 'kg';

        foreach ($dailyLatest as $record) {
            $weightKg = round($record->weight_grams / 1000, 2);
            $muscleMassKg = $record->muscle_mass_grams !== null ? round($record->muscle_mass_grams / 1000, 2) : null;
            $weightsKg[] = $weightKg;

            $points[] = [
                'date' => $record->calendar_date,
                'weightKg' => $weightKg,
                'bodyFatPct' => $record->body_fat_pct,
                'muscleMassKg' => $muscleMassKg,
                'bmi' => $record->bmi,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'weight' => number_format($weightKg * $massFactor, 1).' '.$massUnit,
                'bmi' => $record->bmi !== null ? number_format($record->bmi, 1) : null,
                'bodyFat' => $record->body_fat_pct !== null ? number_format($record->body_fat_pct, 1).'%' : null,
                'muscle' => $muscleMassKg !== null ? number_format($muscleMassKg * $massFactor, 1).' '.$massUnit : null,
                'water' => $record->body_water_pct !== null ? number_format($record->body_water_pct, 1).'%' : null,
            ];
        }

        $avgWeight = $this->average($weightsKg);
        $delta = count($weightsKg) >= 2
            ? sprintf('%+.1f %s', ($weightsKg[count($weightsKg) - 1] - $weightsKg[0]) * $massFactor, $massUnit)
            : null;

        $priorPoint = null;

        if ($startDate !== null) {
            $priorRecord = $this->healthSources()->applyScope(WeightMeasurement::query(), 'weight')
                ->where('calendar_date', '<', $startDate)
                ->orderByDesc('calendar_date')
                ->orderByDesc('measured_at')
                ->orderByDesc('id')
                ->first();

            if ($priorRecord !== null) {
                $priorPoint = [
                    'date' => $priorRecord->calendar_date,
                    'weightKg' => round($priorRecord->weight_grams / 1000, 2),
                    'bodyFatPct' => $priorRecord->body_fat_pct,
                ];
            }
        }

        return [
            'summary' => [
                'latest' => $weightsKg !== [] ? number_format($weightsKg[count($weightsKg) - 1] * $massFactor, 1).' '.$massUnit : null,
                'average' => $avgWeight !== null ? number_format($avgWeight * $massFactor, 1).' '.$massUnit : null,
                'min' => $weightsKg !== [] ? number_format(min($weightsKg) * $massFactor, 1).' '.$massUnit : null,
                'max' => $weightsKg !== [] ? number_format(max($weightsKg) * $massFactor, 1).' '.$massUnit : null,
                'extraLabel' => 'Period Change',
                'extraValue' => $delta,
            ],
            'baseline' => null,
            'priorPoint' => $priorPoint,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'weight', 'label' => 'Weight'],
                ['key' => 'bmi', 'label' => 'BMI'],
                ['key' => 'bodyFat', 'label' => 'Body Fat'],
                ['key' => 'muscle', 'label' => 'Muscle'],
                ['key' => 'water', 'label' => 'Water'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     priorPoint: ?array<string, mixed>,
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildVo2Max(?string $startDate, string $endDate): array
    {
        $vo2Query = $this->healthSources()->applyScope(Vo2MaxRecord::query(), 'vo2_max')
            ->where('calendar_date', '<=', $endDate)
            ->whereIn('sport', ['running', 'cycling'])
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $vo2Query->where('calendar_date', '>=', $startDate);
        }

        $vo2Records = $vo2Query->get();

        /** @var array<string, array{running: ?float, cycling: ?float}> $byDate */
        $byDate = [];

        foreach ($vo2Records as $record) {
            $value = $record->vo2_max_precise ?? $record->vo2_max;

            if ($value === null) {
                continue;
            }

            if (! isset($byDate[$record->calendar_date])) {
                $byDate[$record->calendar_date] = [
                    'running' => null,
                    'cycling' => null,
                ];
            }

            if ($record->sport === 'running') {
                $byDate[$record->calendar_date]['running'] = $value;
            } elseif ($record->sport === 'cycling') {
                $byDate[$record->calendar_date]['cycling'] = $value;
            }
        }

        ksort($byDate);

        $points = [];
        $rows = [];
        $runningValues = [];
        $cyclingValues = [];

        foreach ($byDate as $date => $entry) {
            $points[] = [
                'date' => $date,
                'running' => $entry['running'],
                'cycling' => $entry['cycling'],
            ];

            $rows[] = [
                'date' => $date,
                'running' => $entry['running'] !== null ? number_format($entry['running'], 1) : null,
                'cycling' => $entry['cycling'] !== null ? number_format($entry['cycling'], 1) : null,
            ];

            if ($entry['running'] !== null) {
                $runningValues[] = $entry['running'];
            }

            if ($entry['cycling'] !== null) {
                $cyclingValues[] = $entry['cycling'];
            }
        }

        $primaryValues = $runningValues !== [] ? $runningValues : $cyclingValues;
        $avgPrimary = $this->average($primaryValues);

        $extraLabel = null;
        $extraValue = null;

        if ($runningValues !== [] && $cyclingValues !== []) {
            $extraLabel = 'Cycling VO2';
            $extraValue = number_format($cyclingValues[count($cyclingValues) - 1], 1);
        } elseif (count($primaryValues) >= 2) {
            $periodDelta = round($primaryValues[count($primaryValues) - 1] - $primaryValues[0], 1);
            $extraLabel = 'Period Change';
            $extraValue = abs($periodDelta) < 0.05 ? '0.0' : sprintf('%+.1f', $periodDelta);
        }

        $priorPoint = null;

        if ($startDate !== null) {
            $priorRunning = $this->healthSources()->applyScope(Vo2MaxRecord::query(), 'vo2_max')
                ->where('sport', 'running')
                ->where('calendar_date', '<', $startDate)
                ->where(function ($subQuery): void {
                    $subQuery
                        ->whereNotNull('vo2_max_precise')
                        ->orWhereNotNull('vo2_max');
                })
                ->orderByDesc('calendar_date')
                ->first();

            $priorCycling = $this->healthSources()->applyScope(Vo2MaxRecord::query(), 'vo2_max')
                ->where('sport', 'cycling')
                ->where('calendar_date', '<', $startDate)
                ->where(function ($subQuery): void {
                    $subQuery
                        ->whereNotNull('vo2_max_precise')
                        ->orWhereNotNull('vo2_max');
                })
                ->orderByDesc('calendar_date')
                ->first();

            $priorRunningVal = $priorRunning !== null ? ($priorRunning->vo2_max_precise ?? $priorRunning->vo2_max) : null;
            $priorCyclingVal = $priorCycling !== null ? ($priorCycling->vo2_max_precise ?? $priorCycling->vo2_max) : null;

            if ($priorRunningVal !== null || $priorCyclingVal !== null) {
                $priorPoint = [
                    'running' => $priorRunningVal !== null ? [
                        'date' => $priorRunning->calendar_date,
                        'value' => $priorRunningVal,
                    ] : null,
                    'cycling' => $priorCyclingVal !== null ? [
                        'date' => $priorCycling->calendar_date,
                        'value' => $priorCyclingVal,
                    ] : null,
                ];
            }
        }

        return [
            'summary' => [
                'latest' => $primaryValues !== [] ? number_format($primaryValues[count($primaryValues) - 1], 1) : null,
                'average' => $avgPrimary !== null ? number_format($avgPrimary, 1) : null,
                'min' => $primaryValues !== [] ? number_format(min($primaryValues), 1) : null,
                'max' => $primaryValues !== [] ? number_format(max($primaryValues), 1) : null,
                'extraLabel' => $extraLabel,
                'extraValue' => $extraValue,
            ],
            'baseline' => null,
            'priorPoint' => $priorPoint,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'running', 'label' => 'Running VO2'],
                ['key' => 'cycling', 'label' => 'Cycling VO2'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     priorPoint: ?array<string, mixed>,
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildFitnessAge(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(FitnessAgeRecord::query(), 'fitness_age')
            ->where('calendar_date', '<=', $endDate)
            ->whereNotNull('fitness_age')
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $fitnessAges = [];
        $latestDelta = null;
        $hasBodyFat = false;
        $hasBmi = false;

        foreach ($records as $record) {
            $fitnessAge = round((float) $record->fitness_age, 1);
            $chronologicalAge = $record->chronological_age !== null
                ? round($record->chronological_age, 1)
                : null;
            $delta = $chronologicalAge !== null
                ? round($fitnessAge - $chronologicalAge, 1)
                : null;

            $components = is_array($record->components) ? $record->components : null;
            $rhrRaw = $this->extractComponentFloat($components, 'rhr');
            $rhr = $rhrRaw !== null ? (int) round($rhrRaw) : null;
            $bodyFatRaw = $this->extractComponentFloat($components, 'bodyFat');
            $bodyFatPct = $bodyFatRaw !== null ? round($bodyFatRaw, 1) : null;
            $bmiRaw = $this->extractComponentFloat($components, 'bmi');
            $bmi = $bmiRaw !== null ? round($bmiRaw, 1) : null;
            $vigorousMinRaw = $this->extractComponentFloat($components, 'vigorousMinutesAvg');
            $vigorousMinutesAvg = $vigorousMinRaw !== null ? round($vigorousMinRaw, 1) : null;
            $vigorousDaysRaw = $this->extractComponentFloat($components, 'vigorousDaysAvg');
            $vigorousDaysAvg = $vigorousDaysRaw !== null ? round($vigorousDaysRaw, 1) : null;

            if ($bodyFatPct !== null) {
                $hasBodyFat = true;
            }

            if ($bmi !== null) {
                $hasBmi = true;
            }

            $fitnessAges[] = $fitnessAge;
            $latestDelta = $delta;

            $points[] = [
                'date' => $record->calendar_date,
                'fitnessAge' => $fitnessAge,
                'chronologicalAge' => $chronologicalAge,
                'delta' => $delta,
                'rhr' => $rhr,
                'bodyFatPct' => $bodyFatPct,
                'bmi' => $bmi,
                'vigorousMinutesAvg' => $vigorousMinutesAvg,
                'vigorousDaysAvg' => $vigorousDaysAvg,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'fitnessAge' => number_format($fitnessAge, 1).' yrs',
                'chronologicalAge' => $chronologicalAge !== null ? number_format($chronologicalAge, 1).' yrs' : null,
                'delta' => $delta !== null ? $this->formatSignedYears($delta) : null,
                'rhr' => $rhr !== null ? "{$rhr} bpm" : null,
                'bodyFat' => $bodyFatPct !== null ? number_format($bodyFatPct, 1).'%' : null,
                'bmi' => $bmi !== null ? number_format($bmi, 1) : null,
                'vigorousMinutes' => $vigorousMinutesAvg !== null ? number_format($vigorousMinutesAvg, 1).' min/wk' : null,
            ];
        }

        $avgFitnessAge = $this->average($fitnessAges);

        $extraLabel = 'vs. Age';
        $extraValue = $latestDelta !== null ? $this->formatSignedYears($latestDelta) : null;

        if ($extraValue === null && count($fitnessAges) >= 2) {
            $periodChange = round($fitnessAges[count($fitnessAges) - 1] - $fitnessAges[0], 1);
            $extraLabel = 'Period Change';
            $extraValue = $this->formatSignedYears($periodChange);
        }

        $bodyCompColumn = (! $hasBodyFat && $hasBmi)
            ? ['key' => 'bmi', 'label' => 'BMI']
            : ['key' => 'bodyFat', 'label' => 'Body Fat'];

        $priorPoint = null;

        if ($startDate !== null) {
            $priorRecord = $this->healthSources()->applyScope(FitnessAgeRecord::query(), 'fitness_age')
                ->where('calendar_date', '<', $startDate)
                ->whereNotNull('fitness_age')
                ->orderByDesc('calendar_date')
                ->first();

            if ($priorRecord !== null) {
                $priorPoint = [
                    'date' => $priorRecord->calendar_date,
                    'fitnessAge' => round((float) $priorRecord->fitness_age, 1),
                    'chronologicalAge' => $priorRecord->chronological_age !== null
                        ? round($priorRecord->chronological_age, 1)
                        : null,
                ];
            }
        }

        return [
            'summary' => [
                'latest' => $fitnessAges !== [] ? number_format($fitnessAges[count($fitnessAges) - 1], 1).' yrs' : null,
                'average' => $avgFitnessAge !== null ? number_format($avgFitnessAge, 1).' yrs' : null,
                'min' => $fitnessAges !== [] ? number_format(min($fitnessAges), 1).' yrs' : null,
                'max' => $fitnessAges !== [] ? number_format(max($fitnessAges), 1).' yrs' : null,
                'extraLabel' => $extraLabel,
                'extraValue' => $extraValue,
            ],
            'baseline' => null,
            'priorPoint' => $priorPoint,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'fitnessAge', 'label' => 'Fitness Age'],
                ['key' => 'chronologicalAge', 'label' => 'Age'],
                ['key' => 'delta', 'label' => 'vs. Age'],
                ['key' => 'rhr', 'label' => 'RHR'],
                $bodyCompColumn,
                ['key' => 'vigorousMinutes', 'label' => 'Vigorous Min'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int|string, mixed>|null  $components
     */
    private function extractComponentFloat(?array $components, string $key): ?float
    {
        if ($components === null || ! isset($components[$key]) || ! is_array($components[$key])) {
            return null;
        }

        $value = $components[$key]['value'] ?? null;

        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function formatSignedYears(float $delta): string
    {
        if (abs($delta) < 0.05) {
            return '0.0 yrs';
        }

        return sprintf('%+.1f yrs', $delta);
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildCalories(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('total_calories')
                    ->orWhereNotNull('active_calories')
                    ->orWhereNotNull('bmr_calories');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $totals = [];
        $actives = [];

        foreach ($records as $record) {
            $calories = $record->normalizedCalories($endDate);
            $total = $calories['total'];
            $active = $calories['active'];
            $bmr = $calories['bmr'];
            $travelDayLabel = $calories['travelDayLabel'];

            $points[] = [
                'date' => $record->calendar_date,
                'total' => $total,
                'active' => $active,
                'bmr' => $bmr,
                'rawTotal' => $calories['rawTotal'],
                'rawActive' => $calories['rawActive'],
                'rawBmr' => $calories['rawBmr'],
                'dayHours' => $calories['dayHours'],
                'travelDayLabel' => $travelDayLabel,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'dayNote' => $travelDayLabel !== null ? "{$travelDayLabel} → 24h" : null,
                'total' => $total !== null ? number_format($total).' kcal' : null,
                'active' => $active !== null ? number_format($active).' kcal' : null,
                'bmr' => $bmr !== null ? number_format($bmr).' kcal' : null,
            ];

            if ($total !== null) {
                $totals[] = $total;
            }

            if ($active !== null) {
                $actives[] = $active;
            }
        }

        $avgTotal = $this->average($totals);
        $avgActive = $this->average($actives);

        return [
            'summary' => [
                'latest' => $totals !== [] ? number_format($totals[count($totals) - 1]).' kcal' : null,
                'average' => $avgTotal !== null ? number_format((int) round($avgTotal)).' kcal' : null,
                'min' => $totals !== [] ? number_format(min($totals)).' kcal' : null,
                'max' => $totals !== [] ? number_format(max($totals)).' kcal' : null,
                'extraLabel' => 'Avg Active',
                'extraValue' => $avgActive !== null ? number_format((int) round($avgActive)).' kcal' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'total', 'label' => 'Total'],
                ['key' => 'active', 'label' => 'Active'],
                ['key' => 'bmr', 'label' => 'BMR'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildFloors(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->whereNotNull('floors_ascended')
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $ascendedValues = [];
        $goalDays = 0;
        $goalHitDays = 0;

        foreach ($records as $record) {
            $ascended = round((float) $record->floors_ascended, 1);
            $descended = $record->floors_descended !== null ? round($record->floors_descended, 1) : null;
            $ascendedValues[] = $ascended;

            if ($record->floors_goal !== null && $record->floors_goal > 0) {
                $goalDays++;

                if ($ascended >= $record->floors_goal) {
                    $goalHitDays++;
                }
            }

            $points[] = [
                'date' => $record->calendar_date,
                'value' => $ascended,
                'descended' => $descended,
                'goal' => $record->floors_goal,
            ];

            $rows[] = [
                'date' => $record->calendar_date,
                'ascended' => number_format($ascended, 1),
                'descended' => $descended !== null ? number_format($descended, 1) : null,
                'goal' => $record->floors_goal,
            ];
        }

        $avgAscended = $this->average($ascendedValues);
        $goalHitRate = $goalDays > 0
            ? sprintf('%d/%d (%d%%)', $goalHitDays, $goalDays, (int) round(($goalHitDays / $goalDays) * 100))
            : null;

        return [
            'summary' => [
                'latest' => $ascendedValues !== [] ? number_format($ascendedValues[count($ascendedValues) - 1], 1) : null,
                'average' => $avgAscended !== null ? number_format($avgAscended, 1) : null,
                'min' => $ascendedValues !== [] ? number_format(min($ascendedValues), 1) : null,
                'max' => $ascendedValues !== [] ? number_format(max($ascendedValues), 1) : null,
                'extraLabel' => 'Goal Hit Rate',
                'extraValue' => $goalHitRate,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'ascended', 'label' => 'Ascended'],
                ['key' => 'descended', 'label' => 'Descended'],
                ['key' => 'goal', 'label' => 'Goal'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildRespirationSpo2(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->where(function ($subQuery): void {
                $subQuery
                    ->whereNotNull('avg_respiration')
                    ->orWhereNotNull('avg_spo2');
            })
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $respValues = [];
        $spo2Values = [];

        foreach ($records as $record) {
            $points[] = [
                'date' => $record->calendar_date,
                'avgRespiration' => $record->avg_respiration,
                'minRespiration' => $record->min_respiration,
                'maxRespiration' => $record->max_respiration,
                'avgSpo2' => $record->avg_spo2,
                'lowestSpo2' => $record->lowest_spo2,
            ];

            $respRange = ($record->min_respiration !== null && $record->max_respiration !== null)
                ? sprintf('%.1f–%.1f', $record->min_respiration, $record->max_respiration)
                : null;

            $rows[] = [
                'date' => $record->calendar_date,
                'avgRespiration' => $record->avg_respiration !== null ? number_format($record->avg_respiration, 1).' brpm' : null,
                'respRange' => $respRange,
                'avgSpo2' => $record->avg_spo2 !== null ? number_format($record->avg_spo2, 1).'%' : null,
                'lowestSpo2' => $record->lowest_spo2 !== null ? number_format($record->lowest_spo2, 1).'%' : null,
            ];

            if ($record->avg_respiration !== null) {
                $respValues[] = $record->avg_respiration;
            }

            if ($record->avg_spo2 !== null) {
                $spo2Values[] = $record->avg_spo2;
            }
        }

        $avgResp = $this->average($respValues);
        $avgSpo2 = $this->average($spo2Values);

        return [
            'summary' => [
                'latest' => $respValues !== [] ? number_format($respValues[count($respValues) - 1], 1).' brpm' : null,
                'average' => $avgResp !== null ? number_format($avgResp, 1).' brpm' : null,
                'min' => $respValues !== [] ? number_format(min($respValues), 1).' brpm' : null,
                'max' => $respValues !== [] ? number_format(max($respValues), 1).' brpm' : null,
                'extraLabel' => 'Avg SpO2',
                'extraValue' => $avgSpo2 !== null ? number_format($avgSpo2, 1).'%' : null,
            ],
            'baseline' => null,
            'points' => $points,
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'avgRespiration', 'label' => 'Avg Resp'],
                ['key' => 'respRange', 'label' => 'Resp Range'],
                ['key' => 'avgSpo2', 'label' => 'Avg SpO2'],
                ['key' => 'lowestSpo2', 'label' => 'Lowest SpO2'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     summary: array{latest: ?string, average: ?string, min: ?string, max: ?string, extraLabel: ?string, extraValue: ?string},
     *     baseline: ?array{low: ?int, high: ?int},
     *     points: list<array<string, mixed>>,
     *     columns: list<array{key: string, label: string}>,
     *     rows: list<array<string, string|int|float|null>>
     * }
     */
    private function buildHydration(?string $startDate, string $endDate): array
    {
        $query = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->where('calendar_date', '<=', $endDate)
            ->whereNotNull('hydration_ml')
            ->orderBy('calendar_date');

        if ($startDate !== null) {
            $query->where('calendar_date', '>=', $startDate);
        }

        $records = $query->get();

        $points = [];
        $rows = [];
        $values = [];

        $isImperial = $this->isImperial();

        foreach ($records as $record) {
            $ml = (int) $record->hydration_ml;
            $values[] = $ml;

            $points[] = [
                'date' => $record->calendar_date,
                'value' => $ml,
            ];

            $rows[] = $isImperial
                ? [
                    'date' => $record->calendar_date,
                    'hydration' => number_format($ml * self::ML_TO_FLOZ, 1).' fl oz',
                    'liters' => number_format(($ml * self::ML_TO_FLOZ) / 128, 2).' gal',
                ]
                : [
                    'date' => $record->calendar_date,
                    'hydration' => number_format($ml).' mL',
                    'liters' => number_format($ml / 1000, 2).' L',
                ];
        }

        $avgMl = $this->average($values);

        return [
            'summary' => $isImperial
                ? [
                    'latest' => $values !== [] ? number_format($values[count($values) - 1] * self::ML_TO_FLOZ, 1).' fl oz' : null,
                    'average' => $avgMl !== null ? number_format($avgMl * self::ML_TO_FLOZ, 1).' fl oz' : null,
                    'min' => $values !== [] ? number_format(min($values) * self::ML_TO_FLOZ, 1).' fl oz' : null,
                    'max' => $values !== [] ? number_format(max($values) * self::ML_TO_FLOZ, 1).' fl oz' : null,
                    'extraLabel' => 'Avg Gallons',
                    'extraValue' => $avgMl !== null ? number_format(($avgMl * self::ML_TO_FLOZ) / 128, 2).' gal' : null,
                ]
                : [
                    'latest' => $values !== [] ? number_format($values[count($values) - 1]).' mL' : null,
                    'average' => $avgMl !== null ? number_format((int) round($avgMl)).' mL' : null,
                    'min' => $values !== [] ? number_format(min($values)).' mL' : null,
                    'max' => $values !== [] ? number_format(max($values)).' mL' : null,
                    'extraLabel' => 'Avg Liters',
                    'extraValue' => $avgMl !== null ? number_format($avgMl / 1000, 2).' L' : null,
                ],
            'baseline' => null,
            'points' => $points,
            'columns' => $isImperial
                ? [
                    ['key' => 'date', 'label' => 'Date'],
                    ['key' => 'hydration', 'label' => 'Intake (fl oz)'],
                    ['key' => 'liters', 'label' => 'Gallons'],
                ]
                : [
                    ['key' => 'date', 'label' => 'Date'],
                    ['key' => 'hydration', 'label' => 'Intake (mL)'],
                    ['key' => 'liters', 'label' => 'Liters'],
                ],
            'rows' => $rows,
        ];
    }

    /**
     * Convert raw stress zone durations into 1-decimal percentages summing cleanly to 100.0%.
     *
     * @return array{rest: ?float, low: ?float, medium: ?float, high: ?float}
     */
    private function calculateStressBreakdownPercentages(int $rest, int $low, int $medium, int $high): array
    {
        $durations = [
            'rest' => max(0, $rest),
            'low' => max(0, $low),
            'medium' => max(0, $medium),
            'high' => max(0, $high),
        ];
        $total = array_sum($durations);

        if ($total <= 0) {
            return [
                'rest' => null,
                'low' => null,
                'medium' => null,
                'high' => null,
            ];
        }

        $percentages = [
            'rest' => round(($durations['rest'] / $total) * 100, 1),
            'low' => round(($durations['low'] / $total) * 100, 1),
            'medium' => round(($durations['medium'] / $total) * 100, 1),
            'high' => round(($durations['high'] / $total) * 100, 1),
        ];

        $diff = round(100.0 - array_sum($percentages), 1);

        if ($diff !== 0.0) {
            $largestKey = 'rest';
            $largestValue = -1;

            foreach ($durations as $key => $seconds) {
                if ($seconds > $largestValue) {
                    $largestValue = $seconds;
                    $largestKey = $key;
                }
            }

            $percentages[$largestKey] = round(max(0.0, $percentages[$largestKey] + $diff), 1);
        }

        return $percentages;
    }

    /**
     * @param  list<int|float>  $values
     */
    private function average(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function formatDurationHoursMinutes(int $seconds): string
    {
        $totalMinutes = (int) round($seconds / 60);
        $hours = intdiv($totalMinutes, 60);
        $minutes = $totalMinutes % 60;

        if ($hours === 0) {
            return "{$minutes}m";
        }

        return sprintf('%dh %02dm', $hours, $minutes);
    }
}
