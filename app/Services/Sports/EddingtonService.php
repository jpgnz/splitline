<?php

namespace App\Services\Sports;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type EddingtonTarget array{
 *     target: int,
 *     completed_days: int,
 *     remaining_days: int
 * }
 * @phpstan-type EddingtonUnitSummary array{
 *     unit: 'km'|'mi',
 *     number: int,
 *     next_target: int,
 *     completed_for_next: int,
 *     days_needed_for_next: int,
 *     next_targets: list<EddingtonTarget>
 * }
 * @phpstan-type EddingtonPayload array{
 *     active_days: int,
 *     max_single_day_meters: float,
 *     metric: EddingtonUnitSummary,
 *     imperial: EddingtonUnitSummary
 * }
 */
class EddingtonService
{
    private const float METERS_PER_KM = 1000.0;

    private const float METERS_PER_MILE = 1609.344;

    private const float FLOAT_EPSILON = 1e-9;

    private const int NEXT_TARGETS_COUNT = 3;

    /**
     * Compute metric (km) and imperial (mi) Eddington numbers from a scoped Activity query,
     * aggregating multiple activities performed on the same local calendar day. An activity that
     * crosses midnight is credited in full to the local day it started on.
     *
     * @param  Builder<Activity>  $baseQuery
     * @return EddingtonPayload
     */
    public function forQuery(Builder $baseQuery): array
    {
        $rows = (clone $baseQuery)
            ->whereNotNull('started_at_local')
            ->where('distance_meters', '>', 0)
            ->toBase()
            ->selectRaw('DATE(started_at_local) as local_date')
            ->selectRaw('SUM(distance_meters) as total_distance_meters')
            ->groupByRaw('DATE(started_at_local)')
            ->get();

        /** @var list<float> $dailyMeters */
        $dailyMeters = [];

        foreach ($rows as $row) {
            $total = is_numeric($row->total_distance_meters) ? (float) $row->total_distance_meters : 0.0;

            if ($total > 0.0) {
                $dailyMeters[] = $total;
            }
        }

        return $this->fromDailyDistances($dailyMeters);
    }

    /**
     * Compute metric and imperial Eddington summaries from pre-aggregated daily distance totals in meters.
     *
     * @param  list<float>  $dailyMeters
     * @return EddingtonPayload
     */
    public function fromDailyDistances(array $dailyMeters): array
    {
        $positiveDays = [];
        $maxSingleDayMeters = 0.0;

        foreach ($dailyMeters as $meters) {
            if (! is_finite($meters) || $meters <= 0.0) {
                continue;
            }

            $positiveDays[] = $meters;

            if ($meters > $maxSingleDayMeters) {
                $maxSingleDayMeters = $meters;
            }
        }

        return [
            'active_days' => count($positiveDays),
            'max_single_day_meters' => round($maxSingleDayMeters, 1),
            'metric' => $this->computeForUnit($positiveDays, self::METERS_PER_KM, 'km'),
            'imperial' => $this->computeForUnit($positiveDays, self::METERS_PER_MILE, 'mi'),
        ];
    }

    /**
     * Compute the Eddington number and upcoming targets for one unit system.
     *
     * @param  list<float>  $dailyMeters
     * @param  'km'|'mi'  $unit
     * @return EddingtonUnitSummary
     */
    private function computeForUnit(array $dailyMeters, float $metersPerUnit, string $unit): array
    {
        /** @var list<float> $distances */
        $distances = [];

        foreach ($dailyMeters as $meters) {
            $distances[] = $meters / $metersPerUnit;
        }

        rsort($distances, SORT_NUMERIC);

        $number = 0;

        foreach ($distances as $index => $distance) {
            $candidate = $index + 1;

            if ($distance + self::FLOAT_EPSILON >= $candidate) {
                $number = $candidate;
            } else {
                break;
            }
        }

        $nextTargets = [];

        for ($offset = 1; $offset <= self::NEXT_TARGETS_COUNT; $offset++) {
            $target = $number + $offset;
            $completed = $this->countDaysAtLeast($distances, $target);

            $nextTargets[] = [
                'target' => $target,
                'completed_days' => $completed,
                'remaining_days' => max(0, $target - $completed),
            ];
        }

        $nextTarget = $nextTargets[0];

        return [
            'unit' => $unit,
            'number' => $number,
            'next_target' => $nextTarget['target'],
            'completed_for_next' => $nextTarget['completed_days'],
            'days_needed_for_next' => $nextTarget['remaining_days'],
            'next_targets' => $nextTargets,
        ];
    }

    /**
     * Count how many days in a descending-sorted distance list meet or exceed the integer threshold.
     *
     * @param  list<float>  $sortedDescendingDistances
     */
    private function countDaysAtLeast(array $sortedDescendingDistances, int $threshold): int
    {
        $count = 0;

        foreach ($sortedDescendingDistances as $distance) {
            if ($distance + self::FLOAT_EPSILON >= $threshold) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }
}
