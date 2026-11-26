<?php

namespace App\Services\Activities;

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-type AnnualSummaryDayActivity array{
 *     id: int,
 *     name: string,
 *     sport_category: string,
 *     effective_discipline: ?string,
 *     distance_meters: float,
 *     duration_seconds: float
 * }
 * @phpstan-type AnnualSummaryDailyEntry array{
 *     date: string,
 *     activities: int,
 *     distance_meters: float,
 *     elevation_gain: float,
 *     duration_seconds: float,
 *     calories: int,
 *     training_load: float,
 *     items: list<AnnualSummaryDayActivity>
 * }
 * @phpstan-type AnnualActivitySummaryPayload array{
 *     today: string,
 *     years: list<int>,
 *     daily: list<AnnualSummaryDailyEntry>
 * }
 */
class AnnualActivitySummaryService
{
    /**
     * Build multi-year daily activity aggregates for cumulative year-over-year and calendar heatmap charts.
     *
     * @param  Builder<Activity>  $baseQuery
     * @return AnnualActivitySummaryPayload
     */
    public function forQuery(Builder $baseQuery, CarbonImmutable $today): array
    {
        $todayStr = $today->format('Y-m-d');

        $rows = (clone $baseQuery)
            ->toBase()
            ->select([
                'id',
                'name',
                'sport_category',
                'sport_discipline',
                'discipline_override',
                'started_at_local',
                'distance_meters',
                'elevation_gain',
                'duration_seconds',
                'calories',
                'tss',
                'training_load',
            ])
            ->where('started_at_local', '<=', $todayStr.' 23:59:59')
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        /** @var array<string, array{activities: int, distance_meters: float, elevation_gain: float, duration_seconds: float, calories: int, training_load: float, items: list<AnnualSummaryDayActivity>}> $byDate */
        $byDate = [];
        /** @var array<int, true> $yearMap */
        $yearMap = [];

        foreach ($rows as $row) {
            $startedAt = is_string($row->started_at_local) ? $row->started_at_local : '';

            if (strlen($startedAt) < 10) {
                continue;
            }

            $date = substr($startedAt, 0, 10);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            $year = (int) substr($date, 0, 4);

            if ($year < 1970 || $year > 2100) {
                continue;
            }

            $yearMap[$year] = true;

            if (! isset($byDate[$date])) {
                $byDate[$date] = [
                    'activities' => 0,
                    'distance_meters' => 0.0,
                    'elevation_gain' => 0.0,
                    'duration_seconds' => 0.0,
                    'calories' => 0,
                    'training_load' => 0.0,
                    'items' => [],
                ];
            }

            $distance = is_numeric($row->distance_meters) ? max(0.0, (float) $row->distance_meters) : 0.0;
            $elevation = is_numeric($row->elevation_gain) ? max(0.0, (float) $row->elevation_gain) : 0.0;
            $duration = is_numeric($row->duration_seconds) ? max(0.0, (float) $row->duration_seconds) : 0.0;
            $calories = is_numeric($row->calories) ? max(0, (int) round((float) $row->calories)) : 0;
            $tss = is_numeric($row->tss) ? (float) $row->tss : null;
            $trainingLoad = is_numeric($row->training_load) ? (float) $row->training_load : null;
            $load = max(0.0, $tss ?? $trainingLoad ?? 0.0);

            $disciplineOverride = is_string($row->discipline_override) ? $row->discipline_override : null;
            $sportDiscipline = is_string($row->sport_discipline) ? $row->sport_discipline : null;
            $effectiveDiscipline = $disciplineOverride ?? $sportDiscipline;

            $byDate[$date]['activities']++;
            $byDate[$date]['distance_meters'] += $distance;
            $byDate[$date]['elevation_gain'] += $elevation;
            $byDate[$date]['duration_seconds'] += $duration;
            $byDate[$date]['calories'] += $calories;
            $byDate[$date]['training_load'] += $load;
            $byDate[$date]['items'][] = [
                'id' => (int) $row->id,
                'name' => is_string($row->name) && $row->name !== '' ? $row->name : 'Activity',
                'sport_category' => is_string($row->sport_category) ? $row->sport_category : 'other',
                'effective_discipline' => $effectiveDiscipline,
                'distance_meters' => round($distance, 1),
                'duration_seconds' => round($duration, 1),
            ];
        }

        ksort($byDate);
        krsort($yearMap);

        $daily = [];

        foreach ($byDate as $date => $bucket) {
            $daily[] = [
                'date' => $date,
                'activities' => $bucket['activities'],
                'distance_meters' => round($bucket['distance_meters'], 1),
                'elevation_gain' => round($bucket['elevation_gain'], 1),
                'duration_seconds' => round($bucket['duration_seconds'], 1),
                'calories' => $bucket['calories'],
                'training_load' => round($bucket['training_load'], 1),
                'items' => $bucket['items'],
            ];
        }

        return [
            'today' => $todayStr,
            'years' => array_keys($yearMap),
            'daily' => $daily,
        ];
    }
}
