<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\HeartRateZone;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonInterface;

/**
 * Computes vendor-neutral HR-zone Training Impulse (TRIMP, inspired by Dr. Eric Banister's model)
 * alongside Garmin Exercise Load for weekly effort context and 12-week trend analysis.
 *
 * @phpstan-type EffortSummaryBlock array{
 *     activity_score: ?int,
 *     rating: ?string,
 *     summary: string,
 *     week_total: int,
 *     week_status: string,
 *     week_status_detail: string,
 *     typical_low: int,
 *     typical_high: int
 * }
 * @phpstan-type DailyEffortBar array{
 *     date: string,
 *     day_label: string,
 *     trimp: int,
 *     load: int,
 *     is_activity_day: bool
 * }
 * @phpstan-type WeeklyEffortTrendPoint array{
 *     week_start: string,
 *     label: string,
 *     trimp: int,
 *     load: int,
 *     trimp_band_low: int,
 *     trimp_band_high: int,
 *     load_band_low: int,
 *     load_band_high: int,
 *     is_current_week: bool
 * }
 * @phpstan-type WeekActivityEffortRow array{
 *     id: int,
 *     name: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     started_at_local: string,
 *     duration_seconds: float,
 *     trimp: ?int,
 *     load: ?int,
 *     is_current: bool
 * }
 * @phpstan-type EffortContextPayload array{
 *     week_start: string,
 *     week_end: string,
 *     week_label: string,
 *     trimp: EffortSummaryBlock,
 *     garmin_load: EffortSummaryBlock,
 *     daily_bars: list<DailyEffortBar>,
 *     twelve_week_trend: list<WeeklyEffortTrendPoint>,
 *     week_activities: list<WeekActivityEffortRow>
 * }
 */
class ActivityEffortService
{
    public function __construct(
        private readonly ?HealthSourceRegistry $healthSources = null,
    ) {}

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    /**
     * Exponentially weighted HR-zone multipliers per minute (Z1–Z5).
     *
     * @var array<int, float>
     */
    private const array ZONE_WEIGHTS = [
        1 => 0.5,
        2 => 1.2,
        3 => 2.4,
        4 => 4.5,
        5 => 7.5,
    ];

    /**
     * @return EffortContextPayload
     */
    public function forActivity(Activity $activity): array
    {
        $activityDate = $activity->started_at_local;
        $weekStart = $activityDate->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $weekEnd = $weekStart->endOfWeek(CarbonInterface::SUNDAY)->endOfDay();

        // Fetch 15 weeks ending at $weekEnd (12 visible weeks + 3 prior weeks for rolling typical band)
        $queryStart = $weekStart->subWeeks(14)->format('Y-m-d').' 00:00:00';
        $queryEnd = $weekEnd->format('Y-m-d').' 23:59:59';

        $windowActivities = Activity::query()
            ->select([
                'id',
                'name',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'started_at_local',
                'duration_seconds',
                'avg_hr',
                'training_load',
                'hr_zone_1_seconds',
                'hr_zone_2_seconds',
                'hr_zone_3_seconds',
                'hr_zone_4_seconds',
                'hr_zone_5_seconds',
            ])
            ->whereBetween('started_at_local', [$queryStart, $queryEnd])
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        $defaultZone = $this->healthSources()->applyScope(HeartRateZone::query(), 'heart_rate_zones')
            ->where('sport', 'DEFAULT')
            ->first(['max_hr', 'resting_hr']);

        $defaultMaxHr = $defaultZone !== null && $defaultZone->max_hr !== null ? $defaultZone->max_hr : 190;
        $defaultRestingHr = $defaultZone !== null && $defaultZone->resting_hr !== null ? $defaultZone->resting_hr : 50;

        /** @var array<string, float> $weeklyTrimp */
        $weeklyTrimp = [];
        /** @var array<string, float> $weeklyLoad */
        $weeklyLoad = [];
        /** @var array<string, float> $dailyTrimp */
        $dailyTrimp = [];
        /** @var array<string, float> $dailyLoad */
        $dailyLoad = [];
        /** @var list<float> $recentTrimpSamples */
        $recentTrimpSamples = [];
        /** @var list<float> $recentLoadSamples */
        $recentLoadSamples = [];
        /** @var list<WeekActivityEffortRow> $weekActivities */
        $weekActivities = [];

        $ninetyDaysBefore = $activityDate->subDays(90)->startOfDay();
        $currentActivityTrimp = $this->calculateTrimp($activity, $defaultMaxHr, $defaultRestingHr);
        $currentActivityLoad = $activity->training_load !== null ? (int) round($activity->training_load) : null;

        foreach ($windowActivities as $item) {
            $itemTrimp = $this->calculateTrimp($item, $defaultMaxHr, $defaultRestingHr);
            $itemLoad = $item->training_load !== null ? max(0.0, $item->training_load) : null;

            $itemWeekKey = $item->started_at_local
                ->startOfWeek(CarbonInterface::MONDAY)
                ->format('Y-m-d');

            $weeklyTrimp[$itemWeekKey] = ($weeklyTrimp[$itemWeekKey] ?? 0.0) + ($itemTrimp ?? 0.0);
            $weeklyLoad[$itemWeekKey] = ($weeklyLoad[$itemWeekKey] ?? 0.0) + ($itemLoad ?? 0.0);

            if (
                $item->started_at_local->greaterThanOrEqualTo($ninetyDaysBefore)
                && $item->started_at_local->lessThanOrEqualTo($activityDate)
            ) {
                if ($itemTrimp !== null && $itemTrimp > 0.0) {
                    $recentTrimpSamples[] = $itemTrimp;
                }

                if ($itemLoad !== null && $itemLoad > 0.0) {
                    $recentLoadSamples[] = $itemLoad;
                }
            }

            if ($itemWeekKey === $weekStart->format('Y-m-d')) {
                $dayKey = $item->started_at_local->format('Y-m-d');
                $dailyTrimp[$dayKey] = ($dailyTrimp[$dayKey] ?? 0.0) + ($itemTrimp ?? 0.0);
                $dailyLoad[$dayKey] = ($dailyLoad[$dayKey] ?? 0.0) + ($itemLoad ?? 0.0);

                $weekActivities[] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sport_category' => $item->sport_category,
                    'effective_discipline' => $item->effectiveDiscipline(),
                    'started_at_local' => $item->started_at_local->format('Y-m-d H:i:s'),
                    'duration_seconds' => $item->duration_seconds,
                    'trimp' => $itemTrimp !== null ? (int) round($itemTrimp) : null,
                    'load' => $itemLoad !== null ? (int) round($itemLoad) : null,
                    'is_current' => $item->id === $activity->id,
                ];
            }
        }

        $activityDayKey = $activityDate->format('Y-m-d');
        $dayLetters = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
        $dailyBars = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $weekStart->addDays($offset);
            $dayKey = $day->format('Y-m-d');

            $dailyBars[] = [
                'date' => $dayKey,
                'day_label' => $dayLetters[$offset],
                'trimp' => (int) round($dailyTrimp[$dayKey] ?? 0.0),
                'load' => (int) round($dailyLoad[$dayKey] ?? 0.0),
                'is_activity_day' => $dayKey === $activityDayKey,
            ];
        }

        $twelveWeekTrend = [];

        for ($i = 11; $i >= 0; $i--) {
            $cursorWeek = $weekStart->subWeeks($i);
            $weekKey = $cursorWeek->format('Y-m-d');

            [$trimpLow, $trimpHigh] = $this->computeRollingBand($weeklyTrimp, $cursorWeek);
            [$loadLow, $loadHigh] = $this->computeRollingBand($weeklyLoad, $cursorWeek);

            $twelveWeekTrend[] = [
                'week_start' => $weekKey,
                'label' => $cursorWeek->format('M j'),
                'trimp' => (int) round($weeklyTrimp[$weekKey] ?? 0.0),
                'load' => (int) round($weeklyLoad[$weekKey] ?? 0.0),
                'trimp_band_low' => $trimpLow,
                'trimp_band_high' => $trimpHigh,
                'load_band_low' => $loadLow,
                'load_band_high' => $loadHigh,
                'is_current_week' => $i === 0,
            ];
        }

        $currentWeekTrend = $twelveWeekTrend[count($twelveWeekTrend) - 1];

        $trimpBlock = $this->buildSummaryBlock(
            activityScore: $currentActivityTrimp !== null ? (int) round($currentActivityTrimp) : null,
            recentSamples: $recentTrimpSamples,
            weekTotal: $currentWeekTrend['trimp'],
            typicalLow: $currentWeekTrend['trimp_band_low'],
            typicalHigh: $currentWeekTrend['trimp_band_high'],
        );

        $loadBlock = $this->buildSummaryBlock(
            activityScore: $currentActivityLoad,
            recentSamples: $recentLoadSamples,
            weekTotal: $currentWeekTrend['load'],
            typicalLow: $currentWeekTrend['load_band_low'],
            typicalHigh: $currentWeekTrend['load_band_high'],
        );

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekEnd->format('Y-m-d'),
            'week_label' => $weekStart->format('M j, Y').' – '.$weekEnd->format('M j, Y'),
            'trimp' => $trimpBlock,
            'garmin_load' => $loadBlock,
            'daily_bars' => $dailyBars,
            'twelve_week_trend' => $twelveWeekTrend,
            'week_activities' => $weekActivities,
        ];
    }

    /**
     * Calculate vendor-neutral HR-zone weighted Training Impulse (TRIMP).
     */
    public function calculateTrimp(Activity $activity, int $maxHr = 190, int $restingHr = 50): ?float
    {
        $zoneSeconds = [
            1 => $activity->hr_zone_1_seconds ?? 0.0,
            2 => $activity->hr_zone_2_seconds ?? 0.0,
            3 => $activity->hr_zone_3_seconds ?? 0.0,
            4 => $activity->hr_zone_4_seconds ?? 0.0,
            5 => $activity->hr_zone_5_seconds ?? 0.0,
        ];

        $totalZoneSeconds = array_sum($zoneSeconds);

        if ($totalZoneSeconds > 0.0) {
            $score = 0.0;

            foreach ($zoneSeconds as $zone => $seconds) {
                $score += ($seconds / 60.0) * self::ZONE_WEIGHTS[$zone];
            }

            return round($score, 1);
        }

        // Fallback: classic Banister exponential TRIMP from average HR and duration
        if ($activity->avg_hr !== null && $activity->avg_hr > 0 && $activity->duration_seconds > 0.0) {
            $hrSpan = max(30, $maxHr - $restingHr);
            $hrr = max(0.05, min(1.0, ($activity->avg_hr - $restingHr) / $hrSpan));
            $durationMinutes = $activity->duration_seconds / 60.0;
            $banister = $durationMinutes * $hrr * 0.64 * exp(1.92 * $hrr);

            return round($banister, 1);
        }

        return null;
    }

    /**
     * Compute a typical weekly effort band from the prior 3 weeks (mean ± 25%, floored at 0).
     *
     * @param  array<string, float>  $weeklyMap
     * @return array{0: int, 1: int}
     */
    private function computeRollingBand(array $weeklyMap, CarbonInterface $weekStart): array
    {
        $priorTotals = [];

        for ($offset = 1; $offset <= 3; $offset++) {
            $priorKey = $weekStart->subWeeks($offset)->format('Y-m-d');
            $priorTotals[] = $weeklyMap[$priorKey] ?? 0.0;
        }

        $mean = array_sum($priorTotals) / 3.0;

        if ($mean <= 0.0) {
            return [0, 0];
        }

        $low = (int) max(0, round($mean * 0.75));
        $high = (int) round($mean * 1.25);

        return [$low, max($low, $high)];
    }

    /**
     * @param  list<float>  $recentSamples
     * @return EffortSummaryBlock
     */
    private function buildSummaryBlock(
        ?int $activityScore,
        array $recentSamples,
        int $weekTotal,
        int $typicalLow,
        int $typicalHigh,
    ): array {
        $rating = null;
        $summary = 'Not enough heart rate or load data to rate this session.';

        if ($activityScore !== null && $activityScore > 0) {
            sort($recentSamples);
            $sampleCount = count($recentSamples);

            if ($sampleCount >= 4) {
                $p40 = $this->percentile($recentSamples, 0.40);
                $p70 = $this->percentile($recentSamples, 0.70);
                $p90 = $this->percentile($recentSamples, 0.90);

                if ($activityScore >= $p90) {
                    $rating = 'Very Hard';
                    $summary = 'Well above your typical recent sessions.';
                } elseif ($activityScore >= $p70) {
                    $rating = 'Tough';
                    $summary = 'Near the upper end of your recent sessions.';
                } elseif ($activityScore >= $p40) {
                    $rating = 'Moderate';
                    $summary = 'In line with your typical recent sessions.';
                } else {
                    $rating = 'Easy';
                    $summary = 'Lower effort than most of your recent sessions.';
                }
            } else {
                if ($activityScore >= 140) {
                    $rating = 'Very Hard';
                    $summary = 'High cardiovascular demand for this session.';
                } elseif ($activityScore >= 85) {
                    $rating = 'Tough';
                    $summary = 'Strong aerobic and threshold effort.';
                } elseif ($activityScore >= 40) {
                    $rating = 'Moderate';
                    $summary = 'Steady endurance work.';
                } else {
                    $rating = 'Easy';
                    $summary = 'Light recovery-level effort.';
                }
            }
        }

        if ($typicalHigh <= 0) {
            $weekStatus = 'Building baseline';
            $weekStatusDetail = 'More sessions needed to establish a 3-week typical range.';
        } elseif ($weekTotal < $typicalLow) {
            $weekStatus = 'Below typical';
            $weekStatusDetail = 'Lower weekly load than your prior 3-week range.';
        } elseif ($weekTotal <= $typicalHigh) {
            $weekStatus = 'Within typical';
            $weekStatusDetail = 'Inside your prior 3-week load range.';
        } elseif ($weekTotal <= (int) round($typicalHigh * 1.25)) {
            $weekStatus = 'Above typical';
            $weekStatusDetail = 'Higher weekly load than your prior 3-week range.';
        } else {
            $weekStatus = 'Peak load week';
            $weekStatusDetail = 'Well above your prior 3-week load range.';
        }

        return [
            'activity_score' => $activityScore,
            'rating' => $rating,
            'summary' => $summary,
            'week_total' => $weekTotal,
            'week_status' => $weekStatus,
            'week_status_detail' => $weekStatusDetail,
            'typical_low' => $typicalLow,
            'typical_high' => $typicalHigh,
        ];
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0.0;
        }

        $index = ($count - 1) * $p;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        $weight = $index - $lower;

        return $sorted[$lower] + ($weight * ($sorted[$upper] - $sorted[$lower]));
    }
}
