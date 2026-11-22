<?php

namespace App\Services\Health;

use App\Models\DailyMetric;
use App\Models\HrvRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class HealthStatusService
{
    /**
     * Evaluate whether core overnight & resting physiological metrics are within personal baselines.
     *
     * @return array{
     *     status: ?string,
     *     tone: string,
     *     summary: string,
     *     inRangeCount: int,
     *     checkedCount: int,
     *     metrics: list<array{key: string, label: string, inRange: bool, detail: string}>
     * }
     */
    public function forDate(
        CarbonImmutable $selectedDate,
        ?DailyMetric $dailyMetric,
        ?HrvRecord $hrvRecord,
    ): array {
        $baselineStart = $selectedDate->subDays(28)->format('Y-m-d');
        $baselineEnd = $selectedDate->subDay()->format('Y-m-d');

        $history = DailyMetric::query()
            ->whereBetween('calendar_date', [$baselineStart, $baselineEnd])
            ->get(['resting_hr', 'avg_respiration', 'avg_spo2']);

        $metrics = [];

        // 1. HRV Status
        $effectiveHrv = $hrvRecord;

        if ($effectiveHrv === null || ($effectiveHrv->status === null && $effectiveHrv->last_night_avg === null)) {
            $effectiveHrv = HrvRecord::query()
                ->whereBetween('calendar_date', [
                    $selectedDate->subDays(2)->format('Y-m-d'),
                    $selectedDate->format('Y-m-d'),
                ])
                ->orderByDesc('calendar_date')
                ->first();
        }

        if ($effectiveHrv !== null && ($effectiveHrv->status !== null || $effectiveHrv->last_night_avg !== null)) {
            $statusUpper = strtoupper((string) ($effectiveHrv->status ?? ''));

            if ($statusUpper !== '') {
                $inRange = $statusUpper === 'BALANCED';
                $detail = ucfirst(strtolower($statusUpper));
            } elseif (
                $effectiveHrv->last_night_avg !== null
                && $effectiveHrv->baseline_balanced_low !== null
                && $effectiveHrv->baseline_balanced_upper !== null
            ) {
                $inRange = $effectiveHrv->last_night_avg >= $effectiveHrv->baseline_balanced_low
                    && $effectiveHrv->last_night_avg <= $effectiveHrv->baseline_balanced_upper;
                $detail = "{$effectiveHrv->last_night_avg} ms";
            } else {
                $inRange = true;
                $detail = "{$effectiveHrv->last_night_avg} ms";
            }

            $metrics[] = [
                'key' => 'hrv',
                'label' => 'HRV',
                'inRange' => $inRange,
                'detail' => $detail,
            ];
        }

        // 2. Resting Heart Rate vs 28d personal baseline (or 7d average fallback)
        if ($dailyMetric?->resting_hr !== null) {
            $rhrHistory = $this->extractNumericColumn($history, 'resting_hr');
            $rhr = (float) $dailyMetric->resting_hr;

            if (count($rhrHistory) >= 5) {
                [$mean, $std] = $this->meanAndStdDev($rhrHistory);
                $tolerance = max(3.5, $std * 1.75);
                $inRange = abs($rhr - $mean) <= $tolerance;
            } elseif ($dailyMetric->seven_day_avg_rhr !== null) {
                $inRange = abs($rhr - $dailyMetric->seven_day_avg_rhr) <= 4.0;
            } else {
                $inRange = $rhr >= 35.0 && $rhr <= 85.0;
            }

            $metrics[] = [
                'key' => 'rhr',
                'label' => 'Resting HR',
                'inRange' => $inRange,
                'detail' => "{$dailyMetric->resting_hr} bpm",
            ];
        }

        // 3. Respiration Rate vs 28d personal baseline
        if ($dailyMetric?->avg_respiration !== null) {
            $respHistory = $this->extractNumericColumn($history, 'avg_respiration');
            $resp = (float) $dailyMetric->avg_respiration;

            if (count($respHistory) >= 5) {
                [$mean, $std] = $this->meanAndStdDev($respHistory);
                $tolerance = max(2.0, $std * 1.75);
                $inRange = abs($resp - $mean) <= $tolerance;
            } else {
                $inRange = $resp >= 10.0 && $resp <= 22.0;
            }

            $metrics[] = [
                'key' => 'respiration',
                'label' => 'Respiration',
                'inRange' => $inRange,
                'detail' => number_format($resp, 1).' brpm',
            ];
        }

        // 4. Blood Oxygen (SpO2)
        if ($dailyMetric?->avg_spo2 !== null) {
            $spo2History = $this->extractNumericColumn($history, 'avg_spo2');
            $spo2 = (float) $dailyMetric->avg_spo2;
            $floor = 90.0;

            if (count($spo2History) >= 5) {
                [$mean] = $this->meanAndStdDev($spo2History);
                $floor = max(90.0, $mean - 3.5);
            }

            $inRange = $spo2 >= $floor;

            $metrics[] = [
                'key' => 'spo2',
                'label' => 'SpO2',
                'inRange' => $inRange,
                'detail' => round($spo2).'%',
            ];
        }

        $checkedCount = count($metrics);

        if ($checkedCount === 0) {
            return [
                'status' => null,
                'tone' => 'neutral',
                'summary' => 'No baseline readings yet.',
                'inRangeCount' => 0,
                'checkedCount' => 0,
                'metrics' => [],
            ];
        }

        $outOfRange = array_values(array_filter($metrics, static fn (array $m): bool => ! $m['inRange']));
        $inRangeCount = $checkedCount - count($outOfRange);

        if ($outOfRange === []) {
            return [
                'status' => 'In Range',
                'tone' => 'positive',
                'summary' => 'All your metrics are in range.',
                'inRangeCount' => $inRangeCount,
                'checkedCount' => $checkedCount,
                'metrics' => $metrics,
            ];
        }

        $flaggedNames = implode(', ', array_map(static fn (array $m): string => $m['label'], $outOfRange));

        return [
            'status' => count($outOfRange) === 1 ? 'Attention' : 'Out of Range',
            'tone' => count($outOfRange) === 1 ? 'moderate' : 'negative',
            'summary' => "Outside baseline: {$flaggedNames}.",
            'inRangeCount' => $inRangeCount,
            'checkedCount' => $checkedCount,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  Collection<int, DailyMetric>  $records
     * @return list<float>
     */
    private function extractNumericColumn($records, string $attribute): array
    {
        $values = [];

        foreach ($records as $record) {
            $raw = $record->getAttribute($attribute);

            if (is_numeric($raw)) {
                $values[] = (float) $raw;
            }
        }

        return $values;
    }

    /**
     * @param  non-empty-list<float>  $values
     * @return array{0: float, 1: float}
     */
    private function meanAndStdDev(array $values): array
    {
        $count = count($values);
        $mean = array_sum($values) / $count;
        $sumSq = 0.0;

        foreach ($values as $value) {
            $diff = $value - $mean;
            $sumSq += $diff * $diff;
        }

        $stdDev = sqrt($sumSq / $count);

        return [$mean, $stdDev];
    }
}
