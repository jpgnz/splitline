<?php

namespace App\Services\Goals;

use App\Models\Activity;
use App\Models\Goal;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type GoalEvaluation array{
 *     id: int,
 *     title: string,
 *     sport: string,
 *     metric: string,
 *     period: string,
 *     target_value: float,
 *     current_value: float,
 *     remaining_value: float,
 *     expected_value: float,
 *     expected_pct: float,
 *     delta: float,
 *     pct: float,
 *     unit: string,
 *     pace_status: 'completed'|'ahead'|'on_track'|'behind',
 *     pace_label: string,
 *     period_label: string,
 *     days_remaining: int,
 *     year: ?int,
 *     is_active: bool
 * }
 * @phpstan-type SportWeeklyStreak array{
 *     current_weekly_streak: int,
 *     longest_weekly_streak: int
 * }
 * @phpstan-type StreaksPayload array{
 *     current_daily_streak: int,
 *     longest_daily_streak: int,
 *     current_weekly_streak: int,
 *     longest_weekly_streak: int,
 *     by_sport: array<string, SportWeeklyStreak>,
 *     by_sport_list: list<array{
 *         key: string,
 *         label: string,
 *         short_label: string,
 *         accent_hex: string,
 *         current_weekly_streak: int,
 *         longest_weekly_streak: int
 *     }>
 * }
 */
class GoalProgressService
{
    public function __construct(
        private readonly ?SportRegistry $sports = null,
    ) {}

    private function sportRegistry(): SportRegistry
    {
        return $this->sports ?? app(SportRegistry::class);
    }

    /**
     * Evaluate a single goal's progress and pace against its active period window.
     *
     * @return GoalEvaluation
     */
    public function evaluate(Goal $goal, ?CarbonImmutable $referenceDate = null): array
    {
        $reference = $this->resolveReferenceDate($referenceDate);
        [$windowStart, $windowEnd, $periodLabel] = $this->resolvePeriodWindow($goal, $reference);

        $query = Activity::query()
            ->whereBetween('started_at_local', [
                $windowStart->format('Y-m-d 00:00:00'),
                $windowEnd->format('Y-m-d 23:59:59'),
            ]);

        $this->applySportFilter($query, $goal->sport);

        [$currentValue, $unit] = $this->computeMetric($query, $goal->metric);

        $startUtc = $this->toUtcDate($windowStart);
        $endUtc = $this->toUtcDate($windowEnd);
        $refUtc = $this->toUtcDate($reference);

        $totalDays = max(1, (int) $startUtc->diffInDays($endUtc) + 1);

        if ($refUtc->lt($startUtc)) {
            $elapsedDays = 0;
        } elseif ($refUtc->gt($endUtc)) {
            $elapsedDays = $totalDays;
        } else {
            $elapsedDays = min($totalDays, max(1, (int) $startUtc->diffInDays($refUtc) + 1));
        }

        $daysRemaining = max(0, $totalDays - $elapsedDays);
        $targetValue = (float) $goal->target_value;
        $remainingValue = round(max(0.0, $targetValue - $currentValue), 1);
        $expectedPct = round(($elapsedDays / $totalDays) * 100.0, 1);
        $expectedValue = round($targetValue * ($elapsedDays / $totalDays), 1);
        $delta = round($currentValue - $expectedValue, 1);
        $pct = $targetValue > 0.0
            ? round(min(999.0, ($currentValue / $targetValue) * 100.0), 1)
            : 0.0;

        if ($targetValue > 0.0 && $currentValue >= $targetValue) {
            $paceStatus = 'completed';
            $paceLabel = "Completed · {$pct}%";
        } elseif ($delta >= 0.0) {
            $paceStatus = 'ahead';
            $absDelta = round(abs($delta), 1);
            $paceLabel = "On track · +{$absDelta} {$unit} ahead";
        } elseif ($delta >= -($targetValue * 0.05)) {
            $paceStatus = 'on_track';
            $paceLabel = 'On pace';
        } else {
            $paceStatus = 'behind';
            $absDelta = round(abs($delta), 1);
            $paceLabel = "Behind · {$absDelta} {$unit} behind";
        }

        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'sport' => $goal->sport,
            'metric' => $goal->metric,
            'period' => $goal->period,
            'target_value' => $targetValue,
            'current_value' => $currentValue,
            'remaining_value' => $remainingValue,
            'expected_value' => $expectedValue,
            'expected_pct' => $expectedPct,
            'delta' => $delta,
            'pct' => $pct,
            'unit' => $unit,
            'pace_status' => $paceStatus,
            'pace_label' => $paceLabel,
            'period_label' => $periodLabel,
            'days_remaining' => $daysRemaining,
            'year' => $goal->year,
            'is_active' => $goal->is_active,
        ];
    }

    /**
     * Evaluate all active goals matching optional sport and period filters.
     *
     * @return list<GoalEvaluation>
     */
    public function activeGoals(
        ?string $sport = null,
        ?string $period = null,
        ?CarbonImmutable $referenceDate = null,
    ): array {
        $query = Goal::query()->active();

        if ($sport !== null) {
            $normalized = $this->sportRegistry()->normalizeKey($sport);
            $aliases = match ($normalized) {
                'cycling' => ['cycling', 'biking'],
                'road_cycling' => ['road_cycling', 'road_biking'],
                'mtb_cycling' => ['mtb_cycling', 'mtb_biking'],
                default => [$sport, $normalized],
            };
            $query->whereIn('sport', array_values(array_unique($aliases)));
        }

        if ($period !== null) {
            $query->where('period', $period);
        }

        $goals = $this->orderByPeriodAndId($query)->get();

        $evaluated = [];

        foreach ($goals as $goal) {
            $evaluated[] = $this->evaluate($goal, $referenceDate);
        }

        return $evaluated;
    }

    /**
     * Build the full Goals & Streaks page payload.
     *
     * @return array{
     *     active: list<GoalEvaluation>,
     *     archived: list<GoalEvaluation>,
     *     streaks: StreaksPayload
     * }
     */
    public function dashboardData(?CarbonImmutable $referenceDate = null): array
    {
        $archivedGoals = $this->orderByPeriodAndId(Goal::query()->archived())->get();

        $archived = [];

        foreach ($archivedGoals as $goal) {
            $archived[] = $this->evaluate($goal, $referenceDate);
        }

        return [
            'active' => $this->activeGoals(null, null, $referenceDate),
            'archived' => $archived,
            'streaks' => $this->streaks($referenceDate),
        ];
    }

    /**
     * Compute overall daily/weekly streaks and per-sport weekly streaks.
     *
     * @return StreaksPayload
     */
    public function streaks(?CarbonImmutable $referenceDate = null): array
    {
        $reference = $this->resolveReferenceDate($referenceDate);
        $todayUtc = $this->toUtcDate($reference);

        $activities = Activity::query()
            ->where('started_at_local', '<=', $reference->format('Y-m-d').' 23:59:59')
            ->get(['started_at_local', 'sport_category', 'sport_discipline', 'discipline_override', 'is_indoor']);

        /** @var array<string, bool> $allDays */
        $allDays = [];
        /** @var array<string, bool> $allWeeks */
        $allWeeks = [];
        /** @var array<string, array<string, bool>> $weeksBySport */
        $weeksBySport = [
            'running' => [],
            'road_biking' => [],
            'mtb_biking' => [],
        ];

        $registry = $this->sportRegistry();

        foreach ($activities as $activity) {
            $dayKey = $activity->started_at_local->format('Y-m-d');
            $dayUtc = $this->parseUtcDate($dayKey);
            $weekKey = $dayUtc->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d');

            $allDays[$dayKey] = true;
            $allWeeks[$weekKey] = true;

            $canonicalKey = $registry->resolveActivitySportKey($activity);
            $weeksBySport[$canonicalKey][$weekKey] = true;

            if ($activity->sport_category === 'running') {
                $weeksBySport['running'][$weekKey] = true;
            } elseif ($activity->sport_category === 'biking') {
                $weeksBySport['cycling'][$weekKey] = true;
                $effectiveDiscipline = $activity->effectiveDiscipline();

                if ($effectiveDiscipline === 'road') {
                    $weeksBySport['road_biking'][$weekKey] = true;
                    $weeksBySport['road_cycling'][$weekKey] = true;
                } elseif ($effectiveDiscipline === 'mtb') {
                    $weeksBySport['mtb_biking'][$weekKey] = true;
                    $weeksBySport['mtb_cycling'][$weekKey] = true;
                }
            }
        }

        $daily = $this->computeDailyStreaks($allDays, $todayUtc);
        $weekly = $this->computeWeeklyStreaks($allWeeks, $todayUtc);

        $bySport = [];

        foreach ($weeksBySport as $sportKey => $weekMap) {
            $calc = $this->computeWeeklyStreaks($weekMap, $todayUtc);
            $bySport[$sportKey] = [
                'current_weekly_streak' => $calc['current'],
                'longest_weekly_streak' => $calc['longest'],
            ];
        }

        $catalog = $registry->catalog();
        $bySportList = [];

        foreach ($registry->activeSportKeys() as $activeKey) {
            $entry = $catalog[$activeKey] ?? null;

            if ($entry === null) {
                continue;
            }

            $calc = $bySport[$activeKey] ?? ['current_weekly_streak' => 0, 'longest_weekly_streak' => 0];
            $bySportList[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'short_label' => $entry['short_label'],
                'accent_hex' => $entry['accent_hex'],
                'current_weekly_streak' => $calc['current_weekly_streak'],
                'longest_weekly_streak' => $calc['longest_weekly_streak'],
            ];
        }

        return [
            'current_daily_streak' => $daily['current'],
            'longest_daily_streak' => $daily['longest'],
            'current_weekly_streak' => $weekly['current'],
            'longest_weekly_streak' => $weekly['longest'],
            'by_sport' => $bySport,
            'by_sport_list' => $bySportList,
        ];
    }

    private function timezone(): string
    {
        $timezone = config('app.display_timezone', 'UTC');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    private function resolveReferenceDate(?CarbonImmutable $referenceDate): CarbonImmutable
    {
        $timezone = $this->timezone();

        if ($referenceDate === null) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $referenceDate->format('Y-m-d'), $timezone);

        return $parsed instanceof CarbonImmutable
            ? $parsed
            : $referenceDate->setTimezone($timezone)->startOfDay();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolvePeriodWindow(Goal $goal, CarbonImmutable $reference): array
    {
        $timezone = $this->timezone();

        if ($goal->period === 'weekly') {
            $start = $reference->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $end = $start->addDays(6)->endOfDay();

            if ($start->year !== $end->year) {
                $label = $start->format('j M Y').' – '.$end->format('j M Y');
            } elseif ($start->month !== $end->month) {
                $label = $start->format('j M').' – '.$end->format('j M');
            } else {
                $label = $start->format('j').'–'.$end->format('j M');
            }

            return [$start, $end, $label];
        }

        if ($goal->period === 'monthly') {
            $start = $reference->startOfMonth()->startOfDay();
            $end = $reference->endOfMonth()->endOfDay();

            return [$start, $end, $start->format('F Y')];
        }

        $year = $goal->year ?? (int) $reference->year;
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $timezone);
        $end = CarbonImmutable::create($year, 12, 31, 23, 59, 59, $timezone);

        return [$start, $end, (string) $year];
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applySportFilter(Builder $query, string $sport): void
    {
        $this->sportRegistry()->applyScope($query, $sport);
    }

    /**
     * @param  Builder<Activity>  $query
     * @return array{0: float, 1: string}
     */
    private function computeMetric(Builder $query, string $metric): array
    {
        return match ($metric) {
            'elevation_m' => [round((float) $query->sum('elevation_gain'), 0), 'm'],
            'duration_hours' => [round(((float) $query->sum('duration_seconds')) / 3600.0, 1), 'h'],
            'activity_count' => [(float) $query->count(), 'sessions'],
            default => [round(((float) $query->sum('distance_meters')) / 1000.0, 1), 'km'],
        };
    }

    /**
     * @param  Builder<Goal>  $query
     * @return Builder<Goal>
     */
    private function orderByPeriodAndId(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE period WHEN 'weekly' THEN 1 WHEN 'monthly' THEN 2 WHEN 'yearly' THEN 3 ELSE 4 END")
            ->orderBy('id');
    }

    private function toUtcDate(CarbonImmutable $date): CarbonImmutable
    {
        return $this->parseUtcDate($date->format('Y-m-d'));
    }

    private function parseUtcDate(string $ymd): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $ymd, 'UTC');

        return $parsed instanceof CarbonImmutable
            ? $parsed
            : CarbonImmutable::parse($ymd, 'UTC')->startOfDay();
    }

    /**
     * @param  array<string, bool>  $dayMap
     * @return array{current: int, longest: int}
     */
    private function computeDailyStreaks(array $dayMap, CarbonImmutable $todayUtc): array
    {
        if ($dayMap === []) {
            return ['current' => 0, 'longest' => 0];
        }

        $current = 0;
        $cursor = null;

        if (isset($dayMap[$todayUtc->format('Y-m-d')])) {
            $cursor = $todayUtc;
        } elseif (isset($dayMap[$todayUtc->subDay()->format('Y-m-d')])) {
            $cursor = $todayUtc->subDay();
        }

        while ($cursor !== null && isset($dayMap[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor = $cursor->subDay();
        }

        ksort($dayMap);

        $longest = 0;
        $run = 0;
        $previous = null;

        foreach (array_keys($dayMap) as $dayKey) {
            $date = $this->parseUtcDate($dayKey);

            if ($previous !== null && $previous->addDay()->format('Y-m-d') === $dayKey) {
                $run++;
            } else {
                $run = 1;
            }

            if ($run > $longest) {
                $longest = $run;
            }

            $previous = $date;
        }

        return ['current' => $current, 'longest' => $longest];
    }

    /**
     * @param  array<string, bool>  $weekMap
     * @return array{current: int, longest: int}
     */
    private function computeWeeklyStreaks(array $weekMap, CarbonImmutable $todayUtc): array
    {
        if ($weekMap === []) {
            return ['current' => 0, 'longest' => 0];
        }

        $thisWeekMonday = $todayUtc->startOfWeek(CarbonInterface::MONDAY);
        $current = 0;
        $cursor = null;

        if (isset($weekMap[$thisWeekMonday->format('Y-m-d')])) {
            $cursor = $thisWeekMonday;
        } elseif (isset($weekMap[$thisWeekMonday->subWeek()->format('Y-m-d')])) {
            $cursor = $thisWeekMonday->subWeek();
        }

        while ($cursor !== null && isset($weekMap[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor = $cursor->subWeek();
        }

        ksort($weekMap);

        $longest = 0;
        $run = 0;
        $previous = null;

        foreach (array_keys($weekMap) as $weekKey) {
            $weekStart = $this->parseUtcDate($weekKey);

            if ($previous !== null && $previous->addWeek()->format('Y-m-d') === $weekKey) {
                $run++;
            } else {
                $run = 1;
            }

            if ($run > $longest) {
                $longest = $run;
            }

            $previous = $weekStart;
        }

        return ['current' => $current, 'longest' => $longest];
    }
}
