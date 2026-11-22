<?php

namespace App\Services\Activities;

use App\Models\Activity;
use App\Models\BestEffort;
use App\Services\Garmin\BestEffortCalculator;

/**
 * Computes Running Pace Distribution across 6 zones (Z1 Recovery – Z6 Anaerobic)
 * for both Grade-Adjusted Pace (GAP) and raw pace, auto-calibrated from the athlete's
 * fastest recent 12-month running effort via the Riegel formula.
 *
 * @phpstan-type PaceZoneBucket array{
 *     zone: int,
 *     label: string,
 *     min_pace_sec: ?int,
 *     max_pace_sec: ?int,
 *     seconds: float,
 *     pct: float
 * }
 * @phpstan-type PaceDistributionPayload array{
 *     anchor_label: string,
 *     anchor_5k_seconds: int,
 *     anchor_source: string,
 *     anchor_date: ?string,
 *     gap_zones: list<PaceZoneBucket>,
 *     pace_zones: list<PaceZoneBucket>
 * }
 */
class PaceDistributionService
{
    private const float RIEGEL_EXPONENT = 1.06;

    /**
     * @var list<string>
     */
    private const array QUALIFYING_KEYS = ['5k', '10k', 'half_marathon', '2mi', '1mi', '15k', '10mi'];

    /**
     * @var array<int, string>
     */
    private const array ZONE_LABELS = [
        1 => 'Recovery',
        2 => 'Endurance',
        3 => 'Tempo',
        4 => 'Threshold',
        5 => 'VO2 Max',
        6 => 'Anaerobic',
    ];

    /**
     * @param  list<array<string, mixed>>  $series
     * @return PaceDistributionPayload|null
     */
    public function forActivity(Activity $activity, array $series): ?array
    {
        if ($activity->sport_category !== 'running' || count($series) < 2) {
            return null;
        }

        $anchor = $this->resolve5kAnchor($activity);

        if ($anchor === null) {
            return null;
        }

        $fiveKPaceSec = $anchor['five_k_seconds'] / 5.0;

        // Zone boundaries in seconds/km (lower seconds = faster pace)
        $z6Max = (int) round($fiveKPaceSec * 0.95); // Anaerobic: < z6Max
        $z5Max = (int) round($fiveKPaceSec * 1.03); // VO2 Max: z6Max – z5Max
        $z4Max = (int) round($fiveKPaceSec * 1.10); // Threshold: z5Max – z4Max
        $z3Max = (int) round($fiveKPaceSec * 1.22); // Tempo: z4Max – z3Max
        $z2Max = (int) round($fiveKPaceSec * 1.38); // Endurance: z3Max – z2Max; Recovery: > z2Max

        $bounds = [
            1 => ['min' => $z2Max, 'max' => null],
            2 => ['min' => $z3Max, 'max' => $z2Max],
            3 => ['min' => $z4Max, 'max' => $z3Max],
            4 => ['min' => $z5Max, 'max' => $z4Max],
            5 => ['min' => $z6Max, 'max' => $z5Max],
            6 => ['min' => null, 'max' => $z6Max],
        ];

        $gapSeconds = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0];
        $paceSeconds = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0];

        $count = count($series);

        for ($i = 1; $i < $count; $i++) {
            $prevTime = isset($series[$i - 1]['time']) && is_numeric($series[$i - 1]['time'])
                ? (float) $series[$i - 1]['time']
                : null;
            $currTime = isset($series[$i]['time']) && is_numeric($series[$i]['time'])
                ? (float) $series[$i]['time']
                : null;

            if ($prevTime === null || $currTime === null) {
                continue;
            }

            $dt = $currTime - $prevTime;

            if ($dt <= 0.0 || $dt > 60.0) {
                continue;
            }

            $rawSpeed = isset($series[$i]['speed']) && is_numeric($series[$i]['speed'])
                ? (float) $series[$i]['speed']
                : null;
            $gapSpeed = isset($series[$i]['gap_speed']) && is_numeric($series[$i]['gap_speed'])
                ? (float) $series[$i]['gap_speed']
                : $rawSpeed;

            if ($rawSpeed !== null && $rawSpeed > 0.5) {
                $rawPaceSec = 1000.0 / $rawSpeed;
                $zone = $this->classifyPace($rawPaceSec, $z6Max, $z5Max, $z4Max, $z3Max, $z2Max);
                $paceSeconds[$zone] += $dt;
            }

            if ($gapSpeed !== null && $gapSpeed > 0.5) {
                $gapPaceSec = 1000.0 / $gapSpeed;
                $zone = $this->classifyPace($gapPaceSec, $z6Max, $z5Max, $z4Max, $z3Max, $z2Max);
                $gapSeconds[$zone] += $dt;
            }
        }

        if (array_sum($gapSeconds) <= 0.0 && array_sum($paceSeconds) <= 0.0) {
            return null;
        }

        return [
            'anchor_label' => $anchor['label'],
            'anchor_5k_seconds' => (int) round($anchor['five_k_seconds']),
            'anchor_source' => $anchor['source'],
            'anchor_date' => $anchor['date'],
            'gap_zones' => $this->formatZones($gapSeconds, $bounds),
            'pace_zones' => $this->formatZones($paceSeconds, $bounds),
        ];
    }

    /**
     * Resolve an equivalent 5K race time (in seconds) from the athlete's best recent 12-month effort.
     *
     * @return ?array{five_k_seconds: float, label: string, source: string, date: ?string}
     */
    private function resolve5kAnchor(Activity $activity): ?array
    {
        $referenceDate = $activity->started_at_local;
        $windowStart = $referenceDate->subMonths(12)->format('Y-m-d').' 00:00:00';
        $windowEnd = $referenceDate->format('Y-m-d').' 23:59:59';

        $recentEfforts = BestEffort::query()
            ->where('sport', 'running')
            ->where('is_excluded', false)
            ->whereIn('distance_key', self::QUALIFYING_KEYS)
            ->whereBetween('achieved_at', [$windowStart, $windowEnd])
            ->get();

        $best = $this->pickFastestEquivalent5k(array_values($recentEfforts->all()));

        if ($best !== null) {
            return [
                'five_k_seconds' => $best['five_k_seconds'],
                'label' => 'Recent 12-month best',
                'source' => strtoupper(str_replace('_', ' ', $best['effort']->distance_key)),
                'date' => $best['effort']->achieved_at->format('Y-m-d'),
            ];
        }

        $allTimeEfforts = BestEffort::query()
            ->where('sport', 'running')
            ->where('is_excluded', false)
            ->whereIn('distance_key', self::QUALIFYING_KEYS)
            ->get();

        $bestAllTime = $this->pickFastestEquivalent5k(array_values($allTimeEfforts->all()));

        if ($bestAllTime !== null) {
            return [
                'five_k_seconds' => $bestAllTime['five_k_seconds'],
                'label' => 'All-time best effort',
                'source' => strtoupper(str_replace('_', ' ', $bestAllTime['effort']->distance_key)),
                'date' => $bestAllTime['effort']->achieved_at->format('Y-m-d'),
            ];
        }

        $fallbackSpeed = $activity->avg_grade_adjusted_speed ?? $activity->avg_speed;

        if ($fallbackSpeed !== null && $fallbackSpeed > 0.5) {
            // Treat an easy/base run avg speed as roughly ~1.22x 5K pace
            $estimated5kSeconds = (5000.0 / $fallbackSpeed) / 1.22;

            return [
                'five_k_seconds' => $estimated5kSeconds,
                'label' => 'Estimated from activity pace',
                'source' => 'Activity',
                'date' => $activity->started_at_local->format('Y-m-d'),
            ];
        }

        return null;
    }

    /**
     * @param  list<BestEffort>  $efforts
     * @return ?array{effort: BestEffort, five_k_seconds: float}
     */
    private function pickFastestEquivalent5k(array $efforts): ?array
    {
        $bestEffort = null;
        $best5k = null;

        foreach ($efforts as $effort) {
            $seconds = $effort->effectiveSeconds();
            $meters = BestEffortCalculator::RUNNING_DISTANCES[$effort->distance_key] ?? $effort->distance_meters;

            if ($seconds <= 0.0 || $meters <= 0.0) {
                continue;
            }

            $equivalent5k = $seconds * ((5000.0 / $meters) ** self::RIEGEL_EXPONENT);

            if ($best5k === null || $equivalent5k < $best5k) {
                $best5k = $equivalent5k;
                $bestEffort = $effort;
            }
        }

        if ($bestEffort === null || $best5k === null) {
            return null;
        }

        return [
            'effort' => $bestEffort,
            'five_k_seconds' => $best5k,
        ];
    }

    private function classifyPace(
        float $paceSecPerKm,
        int $z6Max,
        int $z5Max,
        int $z4Max,
        int $z3Max,
        int $z2Max,
    ): int {
        if ($paceSecPerKm < $z6Max) {
            return 6;
        }

        if ($paceSecPerKm < $z5Max) {
            return 5;
        }

        if ($paceSecPerKm < $z4Max) {
            return 4;
        }

        if ($paceSecPerKm < $z3Max) {
            return 3;
        }

        if ($paceSecPerKm < $z2Max) {
            return 2;
        }

        return 1;
    }

    /**
     * @param  array<int, float>  $secondsByZone
     * @param  array<int, array{min: ?int, max: ?int}>  $bounds
     * @return list<PaceZoneBucket>
     */
    private function formatZones(array $secondsByZone, array $bounds): array
    {
        $total = array_sum($secondsByZone);
        $zones = [];

        // Return Z6 (Anaerobic) -> Z1 (Recovery) top-to-bottom like classic pace distribution charts
        for ($z = 6; $z >= 1; $z--) {
            $sec = $secondsByZone[$z] ?? 0.0;
            $zones[] = [
                'zone' => $z,
                'label' => self::ZONE_LABELS[$z],
                'min_pace_sec' => $bounds[$z]['min'],
                'max_pace_sec' => $bounds[$z]['max'],
                'seconds' => round($sec, 1),
                'pct' => $total > 0.0 ? round(($sec / $total) * 100.0, 1) : 0.0,
            ];
        }

        return $zones;
    }
}
