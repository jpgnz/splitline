<?php

namespace App\Services\Health;

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use App\Models\SleepRecord;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class TrainingReadinessService
{
    public function __construct(
        private readonly ?HealthSourceRegistry $healthSources = null,
    ) {}

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    /**
     * Weight hierarchy aligned with Garmin's Training Readiness documentation:
     * last night's sleep and residual recovery time are primary drivers, followed by
     * HRV status, acute training load, 3-night sleep history, and 3-day stress history.
     *
     * @var array<string, float>
     */
    private const array FACTOR_WEIGHTS = [
        'sleep' => 0.28,
        'recovery' => 0.24,
        'hrvStatus' => 0.15,
        'acuteLoad' => 0.13,
        'recentSleep' => 0.12,
        'recentStress' => 0.08,
    ];

    /**
     * Compute a Garmin-calibrated 1–100 Training Readiness estimate and its 6 factor ratings.
     *
     * @return array{
     *     score: ?int,
     *     level: ?string,
     *     subtitle: ?string,
     *     tone: string,
     *     recoveryHours: ?int,
     *     factors: array{
     *         sleep: array{label: string, tone: string, score: ?int},
     *         recovery: array{label: string, tone: string, score: ?int, hours: ?int},
     *         hrvStatus: array{label: string, tone: string, score: ?int},
     *         acuteLoad: array{label: string, tone: string, score: ?int, ratio: ?float},
     *         recentSleep: array{label: string, tone: string, score: ?int},
     *         recentStress: array{label: string, tone: string, score: ?int}
     *     }
     * }
     */
    public function forDate(
        CarbonImmutable $selectedDate,
        CarbonImmutable $today,
        ?DailyMetric $dailyMetric,
        ?SleepRecord $sleepRecord,
        ?HrvRecord $hrvRecord,
        string $timezone,
    ): array {
        $selectedDateStr = $selectedDate->format('Y-m-d');
        $isToday = $selectedDateStr === $today->format('Y-m-d');

        $sleepFactor = $this->evaluateLastNightSleep($sleepRecord);
        $recentSleepResult = $this->evaluateRecentSleep($selectedDate, $sleepRecord, $sleepFactor['score'], $timezone);
        $recentStressFactor = $this->evaluateRecentStress($selectedDate, $dailyMetric);
        $hrvFactor = $this->evaluateHrvStatus($selectedDate, $hrvRecord);

        $windowActivities = Activity::query()
            ->whereBetween('started_at_local', [
                $selectedDate->subDays(27)->format('Y-m-d').' 00:00:00',
                $selectedDateStr.' 23:59:59',
            ])
            ->orderBy('started_at_local')
            ->get([
                'started_at_local',
                'duration_seconds',
                'training_load',
                'aerobic_te',
                'anaerobic_te',
            ]);

        $acuteLoadFactor = $this->evaluateAcuteLoad($selectedDate, $windowActivities, $timezone);
        $recoveryFactor = $this->evaluateRecoveryTime(
            selectedDate: $selectedDate,
            isToday: $isToday,
            sleepRecord: $sleepRecord,
            sleepScore: $sleepFactor['score'],
            stressScore: $recentStressFactor['score'],
            activities: $windowActivities,
            timezone: $timezone,
        );

        $hasCoreSignal = $sleepFactor['score'] !== null
            || $hrvFactor['score'] !== null
            || $recentStressFactor['score'] !== null
            || $windowActivities->isNotEmpty();

        if (! $hasCoreSignal) {
            return [
                'score' => null,
                'level' => null,
                'subtitle' => null,
                'tone' => 'neutral',
                'recoveryHours' => null,
                'factors' => [
                    'sleep' => $sleepFactor,
                    'recovery' => $recoveryFactor,
                    'hrvStatus' => $hrvFactor,
                    'acuteLoad' => $acuteLoadFactor,
                    'recentSleep' => $recentSleepResult['factor'],
                    'recentStress' => $recentStressFactor,
                ],
            ];
        }

        $factorScores = [
            'sleep' => $sleepFactor['score'],
            'recovery' => $recoveryFactor['score'],
            'hrvStatus' => $hrvFactor['score'],
            'acuteLoad' => $acuteLoadFactor['score'],
            'recentSleep' => $recentSleepResult['factor']['score'],
            'recentStress' => $recentStressFactor['score'],
        ];

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach (self::FACTOR_WEIGHTS as $key => $weight) {
            $factorScore = $factorScores[$key];

            if ($factorScore === null) {
                continue;
            }

            $weightedSum += $factorScore * $weight;
            $weightTotal += $weight;
        }

        $baseScore = $weightTotal > 0.0 ? ($weightedSum / $weightTotal) : 50.0;

        // Garmin penalizes unusually long awake periods (> 20h) before the latest sleep.
        $awakeGapHours = $recentSleepResult['awakeGapHours'];
        $awakePenalty = ($awakeGapHours !== null && $awakeGapHours > 20.0)
            ? min(14.0, ($awakeGapHours - 20.0) * 0.7)
            : 0.0;

        // Primary bottlenecks drag overall readiness down rather than being masked by secondary metrics.
        $primaryDrivers = array_values(array_filter(
            [$sleepFactor['score'], $recoveryFactor['score'], $recentSleepResult['factor']['score'], $hrvFactor['score']],
            static fn (?int $value): bool => $value !== null,
        ));

        $bottleneckPenalty = 0.0;

        if ($primaryDrivers !== []) {
            $minDriver = (float) min($primaryDrivers);

            if ($minDriver < 70.0 && $baseScore > $minDriver) {
                $bottleneckPenalty = 0.25 * ($baseScore - $minDriver);
            }
        }

        $finalScore = (int) round(max(1.0, min(100.0, $baseScore - $awakePenalty - $bottleneckPenalty)));
        [$level, $subtitle, $tone] = $this->classifyReadinessScore($finalScore);

        return [
            'score' => $finalScore,
            'level' => $level,
            'subtitle' => $subtitle,
            'tone' => $tone,
            'recoveryHours' => $recoveryFactor['hours'],
            'factors' => [
                'sleep' => $sleepFactor,
                'recovery' => $recoveryFactor,
                'hrvStatus' => $hrvFactor,
                'acuteLoad' => $acuteLoadFactor,
                'recentSleep' => $recentSleepResult['factor'],
                'recentStress' => $recentStressFactor,
            ],
        ];
    }

    /**
     * @return array{label: string, tone: string, score: ?int}
     */
    private function evaluateLastNightSleep(?SleepRecord $sleepRecord): array
    {
        $score = $this->resolveSleepNightScore($sleepRecord);

        if ($score === null) {
            return ['label' => '—', 'tone' => 'neutral', 'score' => null];
        }

        $qualifier = $sleepRecord?->qualityQualifier();
        $label = match ($qualifier) {
            'EXCELLENT' => 'Excellent',
            'GOOD' => 'Good',
            'FAIR' => 'Fair',
            'POOR' => 'Poor',
            default => $this->sleepLabelFromScore($score),
        };

        return [
            'label' => $label,
            'tone' => $this->toneFromSleepLabel($label),
            'score' => $score,
        ];
    }

    /**
     * @return array{
     *     factor: array{label: string, tone: string, score: ?int},
     *     awakeGapHours: ?float
     * }
     */
    private function evaluateRecentSleep(
        CarbonImmutable $selectedDate,
        ?SleepRecord $lastNight,
        ?int $lastNightScore,
        string $timezone,
    ): array {
        $priorRecords = $this->healthSources()->applyScope(SleepRecord::query(), 'sleep')
            ->whereBetween('calendar_date', [
                $selectedDate->subDays(4)->format('Y-m-d'),
                $selectedDate->subDay()->format('Y-m-d'),
            ])
            ->orderBy('calendar_date')
            ->get();

        $threeNightCutoff = $selectedDate->subDays(3)->format('Y-m-d');
        $priorScores = [];

        foreach ($priorRecords as $record) {
            if ($record->calendar_date < $threeNightCutoff) {
                continue;
            }

            $nightScore = $this->resolveSleepNightScore($record);

            if ($nightScore !== null) {
                $priorScores[] = $nightScore;
            }
        }

        $awakeGapHours = $this->computeAwakeGapHours($lastNight, $priorRecords, $timezone);

        if ($priorScores === [] && $lastNightScore === null) {
            return [
                'factor' => ['label' => '—', 'tone' => 'neutral', 'score' => null],
                'awakeGapHours' => $awakeGapHours,
            ];
        }

        $priorAvg = $priorScores !== [] ? (array_sum($priorScores) / count($priorScores)) : null;

        if ($priorAvg !== null && $lastNightScore !== null) {
            $blended = ($priorAvg * 0.6) + ($lastNightScore * 0.4);
        } elseif ($priorAvg !== null) {
            $blended = $priorAvg;
        } else {
            $blended = (float) $lastNightScore;
        }

        $score = (int) round(max(10.0, min(100.0, $blended)));
        $label = $this->sleepLabelFromScore($score);

        return [
            'factor' => [
                'label' => $label,
                'tone' => $this->toneFromSleepLabel($label),
                'score' => $score,
            ],
            'awakeGapHours' => $awakeGapHours,
        ];
    }

    /**
     * @param  Collection<int, SleepRecord>  $priorRecords
     */
    private function computeAwakeGapHours(?SleepRecord $lastNight, $priorRecords, string $timezone): ?float
    {
        if ($lastNight?->sleep_start_local === null) {
            return null;
        }

        $sleepStart = CarbonImmutable::parse($lastNight->sleep_start_local->format('Y-m-d H:i:s'), $timezone);
        $latestPriorEnd = null;

        foreach ($priorRecords as $record) {
            if ($record->sleep_end_local === null) {
                continue;
            }

            $candidate = CarbonImmutable::parse($record->sleep_end_local->format('Y-m-d H:i:s'), $timezone);

            if ($candidate->lessThan($sleepStart) && ($latestPriorEnd === null || $candidate->greaterThan($latestPriorEnd))) {
                $latestPriorEnd = $candidate;
            }
        }

        if ($latestPriorEnd === null) {
            return null;
        }

        return round($latestPriorEnd->diffInMinutes($sleepStart) / 60.0, 1);
    }

    /**
     * @return array{label: string, tone: string, score: ?int}
     */
    private function evaluateRecentStress(CarbonImmutable $selectedDate, ?DailyMetric $dailyMetric): array
    {
        $priorStress = $this->healthSources()->applyScope(DailyMetric::query(), 'daily_metrics')
            ->whereBetween('calendar_date', [
                $selectedDate->subDays(3)->format('Y-m-d'),
                $selectedDate->subDay()->format('Y-m-d'),
            ])
            ->whereNotNull('avg_stress')
            ->pluck('avg_stress')
            ->all();

        $values = array_values(array_filter($priorStress, static fn (mixed $v): bool => is_numeric($v)));

        if ($values === [] && $dailyMetric?->avg_stress !== null) {
            $values = [$dailyMetric->avg_stress];
        }

        if ($values === []) {
            return ['label' => '—', 'tone' => 'neutral', 'score' => null];
        }

        $avgStress = array_sum($values) / count($values);

        if ($avgStress <= 25.0) {
            $score = (int) round(96.0 - ($avgStress / 25.0) * 14.0);

            return ['label' => 'Low', 'tone' => 'positive', 'score' => $score];
        }

        if ($avgStress <= 40.0) {
            $score = (int) round(80.0 - (($avgStress - 25.0) / 15.0) * 26.0);

            return ['label' => 'Moderate', 'tone' => 'moderate', 'score' => $score];
        }

        if ($avgStress <= 55.0) {
            $score = (int) round(53.0 - (($avgStress - 40.0) / 15.0) * 25.0);

            return ['label' => 'High', 'tone' => 'negative', 'score' => $score];
        }

        $score = (int) round(max(8.0, 27.0 - (($avgStress - 55.0) / 45.0) * 19.0));

        return ['label' => 'Very High', 'tone' => 'negative', 'score' => $score];
    }

    /**
     * @return array{label: string, tone: string, score: ?int}
     */
    private function evaluateHrvStatus(CarbonImmutable $selectedDate, ?HrvRecord $hrvRecord): array
    {
        $record = $hrvRecord;

        if ($record === null || ($record->status === null && $record->weekly_avg === null && $record->last_night_avg === null)) {
            $record = $this->healthSources()->applyScope(HrvRecord::query(), 'hrv')
                ->whereBetween('calendar_date', [
                    $selectedDate->subDays(2)->format('Y-m-d'),
                    $selectedDate->format('Y-m-d'),
                ])
                ->whereNotNull('status')
                ->orderByDesc('calendar_date')
                ->first();
        }

        if ($record === null) {
            return ['label' => '—', 'tone' => 'neutral', 'score' => null];
        }

        $status = strtoupper((string) ($record->status ?? ''));

        if ($status === 'BALANCED') {
            $score = 88;
            $low = $record->baseline_balanced_low;
            $high = $record->baseline_balanced_upper;
            $val = $record->weekly_avg ?? $record->last_night_avg;

            if ($low !== null && $high !== null && $high > $low && $val !== null) {
                $position = max(0.0, min(1.0, ($val - $low) / ($high - $low)));
                $score = (int) round(82.0 + ($position * 12.0));
            }

            return ['label' => 'Balanced', 'tone' => 'positive', 'score' => $score];
        }

        if ($status === 'UNBALANCED') {
            return ['label' => 'Unbalanced', 'tone' => 'moderate', 'score' => 50];
        }

        if ($status === 'LOW') {
            return ['label' => 'Low', 'tone' => 'negative', 'score' => 28];
        }

        if ($status === 'POOR') {
            return ['label' => 'Poor', 'tone' => 'negative', 'score' => 14];
        }

        return ['label' => '—', 'tone' => 'neutral', 'score' => null];
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return array{label: string, tone: string, score: ?int, ratio: ?float}
     */
    private function evaluateAcuteLoad(CarbonImmutable $selectedDate, $activities, string $timezone): array
    {
        if ($activities->isEmpty()) {
            return ['label' => '—', 'tone' => 'neutral', 'score' => null, 'ratio' => null];
        }

        $acuteDecayed = 0.0;
        $total28d = 0.0;

        foreach ($activities as $activity) {
            $load = $activity->training_load ?? 0.0;

            if ($load <= 0.0) {
                continue;
            }

            $total28d += $load;

            $activityDay = CarbonImmutable::parse($activity->started_at_local->format('Y-m-d'), $timezone);
            $daysAgo = (int) $activityDay->diffInDays($selectedDate, absolute: false);

            if ($daysAgo >= 0 && $daysAgo < 10) {
                $weight = (10.0 - $daysAgo) / 10.0;
                $acuteDecayed += $load * $weight;
            }
        }

        if ($total28d <= 0.0) {
            return ['label' => 'Low', 'tone' => 'positive', 'score' => 78, 'ratio' => 0.0];
        }

        // Convert 10-day linear decay sum (5.5 effective days) onto a 7-day weekly scale for ACWR.
        $acuteWeeklyEquivalent = $acuteDecayed * (7.0 / 5.5);
        $chronicWeeklyLoad = $total28d / 4.0;
        $ratio = round($acuteWeeklyEquivalent / max($chronicWeeklyLoad, 40.0), 2);

        if ($ratio >= 0.80 && $ratio <= 1.30) {
            $distanceFromIdeal = abs($ratio - 1.05);
            $score = (int) round(94.0 - ($distanceFromIdeal / 0.25) * 12.0);

            return ['label' => 'Optimal', 'tone' => 'positive', 'score' => $score, 'ratio' => $ratio];
        }

        if ($ratio >= 0.50 && $ratio < 0.80) {
            return ['label' => 'Low', 'tone' => 'positive', 'score' => 78, 'ratio' => $ratio];
        }

        if ($ratio < 0.50) {
            return ['label' => 'Very Low', 'tone' => 'moderate', 'score' => 72, 'ratio' => $ratio];
        }

        if ($ratio <= 1.50) {
            $score = (int) round(65.0 - (($ratio - 1.30) / 0.20) * 23.0);

            return ['label' => 'High', 'tone' => 'moderate', 'score' => $score, 'ratio' => $ratio];
        }

        $score = (int) round(max(15.0, 38.0 - (($ratio - 1.50) / 0.50) * 20.0));

        return ['label' => 'Very High', 'tone' => 'negative', 'score' => $score, 'ratio' => $ratio];
    }

    /**
     * Simulate Garmin's additive recovery time countdown across recent activities.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array{label: string, tone: string, score: ?int, hours: ?int}
     */
    private function evaluateRecoveryTime(
        CarbonImmutable $selectedDate,
        bool $isToday,
        ?SleepRecord $sleepRecord,
        ?int $sleepScore,
        ?int $stressScore,
        $activities,
        string $timezone,
    ): array {
        $cutoff = $selectedDate->subDays(5)->startOfDay();
        $referenceTime = $this->resolveRecoveryReferenceTime($selectedDate, $isToday, $sleepRecord, $activities, $timezone);

        $recoveryRate = 1.0;

        if (($sleepScore !== null && $sleepScore >= 80) && ($stressScore === null || $stressScore >= 80)) {
            $recoveryRate = 1.08;
        } elseif (($sleepScore !== null && $sleepScore < 60) || ($stressScore !== null && $stressScore < 55)) {
            $recoveryRate = 0.90;
        }

        $remainingHours = 0.0;
        $cursorTime = null;

        foreach ($activities as $activity) {
            $start = CarbonImmutable::parse($activity->started_at_local->format('Y-m-d H:i:s'), $timezone);

            if ($start->lessThan($cutoff) || $start->greaterThan($referenceTime)) {
                continue;
            }

            if ($cursorTime !== null && $start->greaterThan($cursorTime)) {
                $elapsedHours = $cursorTime->diffInMinutes($start) / 60.0;
                $remainingHours = max(0.0, $remainingHours - ($elapsedHours * $recoveryRate));
            }

            $load = $activity->training_load ?? 0.0;
            $durationSeconds = max(0.0, $activity->duration_seconds);
            $anaerobicTe = $activity->anaerobic_te ?? 0.0;

            if ($load > 0.0) {
                $demandHours = min(96.0, (pow($load, 1.05) * 0.16));

                if ($anaerobicTe >= 2.0) {
                    $demandHours += ($anaerobicTe - 1.5) * 3.0;
                }
            } else {
                $demandHours = min(36.0, ($durationSeconds / 3600.0) * 6.0);
            }

            $remainingHours = min(96.0, $remainingHours + $demandHours);
            $cursorTime = $start->addSeconds((int) round($durationSeconds));
        }

        if ($cursorTime !== null && $referenceTime->greaterThan($cursorTime)) {
            $elapsedHours = $cursorTime->diffInMinutes($referenceTime) / 60.0;
            $remainingHours = max(0.0, $remainingHours - ($elapsedHours * $recoveryRate));
        }

        $hours = (int) round($remainingHours);

        if ($remainingHours <= 4.0) {
            return ['label' => 'No Need', 'tone' => 'positive', 'score' => 96, 'hours' => $hours];
        }

        if ($remainingHours <= 18.0) {
            $score = (int) round(90.0 - (($remainingHours - 4.0) / 14.0) * 24.0);

            return ['label' => 'Low Need', 'tone' => 'positive', 'score' => $score, 'hours' => $hours];
        }

        if ($remainingHours <= 36.0) {
            $score = (int) round(65.0 - (($remainingHours - 18.0) / 18.0) * 25.0);

            return ['label' => 'Moderate', 'tone' => 'moderate', 'score' => $score, 'hours' => $hours];
        }

        if ($remainingHours <= 56.0) {
            $score = (int) round(39.0 - (($remainingHours - 36.0) / 20.0) * 20.0);

            return ['label' => 'High Need', 'tone' => 'negative', 'score' => $score, 'hours' => $hours];
        }

        $score = (int) round(max(5.0, 18.0 - (($remainingHours - 56.0) / 40.0) * 13.0));

        return ['label' => 'Very High', 'tone' => 'negative', 'score' => $score, 'hours' => $hours];
    }

    /**
     * @param  Collection<int, Activity>  $activities
     */
    private function resolveRecoveryReferenceTime(
        CarbonImmutable $selectedDate,
        bool $isToday,
        ?SleepRecord $sleepRecord,
        $activities,
        string $timezone,
    ): CarbonImmutable {
        if ($isToday) {
            return CarbonImmutable::now($timezone);
        }

        $selectedDateStr = $selectedDate->format('Y-m-d');
        $baseline = $selectedDate->setTime(8, 0);

        if ($sleepRecord?->sleep_end_local !== null && $sleepRecord->sleep_end_local->format('Y-m-d') === $selectedDateStr) {
            $baseline = CarbonImmutable::parse($sleepRecord->sleep_end_local->format('Y-m-d H:i:s'), $timezone);
        }

        foreach ($activities as $activity) {
            if ($activity->started_at_local->format('Y-m-d') !== $selectedDateStr) {
                continue;
            }

            $end = CarbonImmutable::parse($activity->started_at_local->format('Y-m-d H:i:s'), $timezone)
                ->addSeconds((int) round(max(0.0, $activity->duration_seconds)));

            if ($end->greaterThan($baseline)) {
                $baseline = $end;
            }
        }

        return $baseline;
    }

    private function resolveSleepNightScore(?SleepRecord $sleepRecord): ?int
    {
        if ($sleepRecord === null) {
            return null;
        }

        if ($sleepRecord->sleep_score !== null) {
            return max(0, min(100, $sleepRecord->sleep_score));
        }

        if ($sleepRecord->sleep_seconds === null || $sleepRecord->sleep_seconds <= 0) {
            return null;
        }

        $needSeconds = ($sleepRecord->sleep_need_minutes !== null && $sleepRecord->sleep_need_minutes > 0)
            ? $sleepRecord->sleep_need_minutes * 60
            : 28800;

        $ratio = $sleepRecord->sleep_seconds / $needSeconds;

        return (int) round(max(20.0, min(92.0, $ratio * 86.0)));
    }

    private function sleepLabelFromScore(int $score): string
    {
        if ($score >= 90) {
            return 'Excellent';
        }

        if ($score >= 80) {
            return 'Good';
        }

        if ($score >= 60) {
            return 'Fair';
        }

        return 'Poor';
    }

    private function toneFromSleepLabel(string $label): string
    {
        return match ($label) {
            'Excellent', 'Good' => 'positive',
            'Fair' => 'moderate',
            default => 'negative',
        };
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function classifyReadinessScore(int $score): array
    {
        if ($score >= 95) {
            return ['Prime', 'Best possible', 'prime'];
        }

        if ($score >= 75) {
            return ['High', 'Ready for challenges', 'high'];
        }

        if ($score >= 50) {
            return ['Moderate', 'Good recovery', 'moderate'];
        }

        if ($score >= 25) {
            return ['Low', 'Time to slow down', 'low'];
        }

        return ['Poor', 'Let your body recover', 'poor'];
    }
}
