<?php

use App\Services\Segments\ClimbDetector;

/**
 * Generate a synthetic straight-north GNSS + elevation stream from piecewise [distanceMeters, altitudeMeters] segments.
 *
 * @param  list<array{distance: float, gain: float, jitter?: float}>  $sections
 * @return list<array{time: float, distance: float, altitude: float, lat: float, lng: float}>
 */
function buildSyntheticElevationSeries(array $sections, float $stepMeters = 20.0, float $startAlt = 50.0): array
{
    $series = [];
    $dist = 0.0;
    $alt = $startAlt;
    $time = 0.0;
    $baseLat = -36.8800;
    $baseLng = 174.7600;
    $pointIdx = 0;

    foreach ($sections as $section) {
        $steps = max(1, (int) round($section['distance'] / $stepMeters));
        $dStep = $section['distance'] / $steps;
        $aStep = $section['gain'] / $steps;
        $jitter = $section['jitter'] ?? 0.0;

        for ($s = 0; $s < $steps; $s++) {
            $noise = $jitter > 0.0 ? (($pointIdx % 2 === 0 ? 1.0 : -1.0) * $jitter) : 0.0;
            $series[] = [
                'time' => $time,
                'distance' => round($dist, 2),
                'altitude' => round($alt + $noise, 2),
                'lat' => round($baseLat + ($dist / 111320.0), 6),
                'lng' => $baseLng,
            ];

            $dist += $dStep;
            $alt += $aStep;
            $time += 5.0;
            $pointIdx++;
        }
    }

    $series[] = [
        'time' => $time,
        'distance' => round($dist, 2),
        'altitude' => round($alt, 2),
        'lat' => round($baseLat + ($dist / 111320.0), 6),
        'lng' => $baseLng,
    ];

    return $series;
}

it('detects realistic climbs across Cat 4 to HC categories and trims flat leading and trailing tails', function () {
    $detector = new ClimbDetector;

    // 400m flat approach + 2000m @ 6% (+120m gain, score = 12000 => cat4) + 400m flat plateau
    $cat4Series = buildSyntheticElevationSeries([
        ['distance' => 400.0, 'gain' => 0.0],
        ['distance' => 2000.0, 'gain' => 120.0],
        ['distance' => 400.0, 'gain' => 0.0],
    ]);

    $cat4Climbs = $detector->detectFromSeries($cat4Series, 'biking');

    expect($cat4Climbs)->toHaveCount(1)
        ->and($cat4Climbs[0]['climb_category'])->toBe('cat4')
        ->and($cat4Climbs[0]['distance_meters'])->toBeGreaterThanOrEqual(1900.0)
        ->and($cat4Climbs[0]['distance_meters'])->toBeLessThanOrEqual(2100.0)
        ->and($cat4Climbs[0]['elevation_gain'])->toBeGreaterThanOrEqual(115.0)
        ->and($cat4Climbs[0]['avg_grade_pct'])->toBeGreaterThanOrEqual(5.5)
        ->and($cat4Climbs[0]['fiets_score'])->toBeGreaterThan(0.6)
        // Trimmed flat tails: start_index should be near index 20 (400m / 20m), end_index near 120 (2400m / 20m)
        ->and($cat4Climbs[0]['start_index'])->toBeGreaterThanOrEqual(17)
        ->and($cat4Climbs[0]['end_index'])->toBeLessThanOrEqual(123);

    // 10,000m @ 8.5% (+850m gain, score = 85000 => hc)
    $hcSeries = buildSyntheticElevationSeries([
        ['distance' => 200.0, 'gain' => 0.0],
        ['distance' => 10000.0, 'gain' => 850.0],
        ['distance' => 200.0, 'gain' => 0.0],
    ], stepMeters: 50.0);

    $hcClimbs = $detector->detectFromSeries($hcSeries, 'biking');

    expect($hcClimbs)->toHaveCount(1)
        ->and($hcClimbs[0]['climb_category'])->toBe('hc')
        ->and($hcClimbs[0]['fiets_score'])->toBeGreaterThan(6.5);
});

it('ignores flat routes with GPS/barometer jitter and tiny false bumps below uncategorized thresholds', function () {
    $detector = new ClimbDetector;

    // Flat 3km with +/- 2.5m barometer jitter
    $noisyFlat = buildSyntheticElevationSeries([
        ['distance' => 3000.0, 'gain' => 0.0, 'jitter' => 2.5],
    ]);

    expect($detector->detectFromSeries($noisyFlat, 'biking'))->toBeEmpty();

    // Small 500m bump gaining only 31m (below uncategorized 35m gain threshold)
    $tinyBump = buildSyntheticElevationSeries([
        ['distance' => 300.0, 'gain' => 0.0],
        ['distance' => 500.0, 'gain' => 31.0],
        ['distance' => 300.0, 'gain' => 0.0],
    ]);

    expect($detector->detectFromSeries($tinyBump, 'biking'))->toBeEmpty();

    // Unsupported sport category (swimming) returns empty
    $validClimb = buildSyntheticElevationSeries([
        ['distance' => 1500.0, 'gain' => 120.0],
    ]);

    expect($detector->detectFromSeries($validClimb, 'swimming'))->toBeEmpty();
});

it('tolerates brief mid-climb dips without splitting a sustained climb', function () {
    $detector = new ClimbDetector;

    // 1000m @ 7% (+70m) + 100m dip (-6m) + 1200m @ 7% (+84m) => single sustained Cat 3/Cat 4 climb
    $seriesWithDip = buildSyntheticElevationSeries([
        ['distance' => 200.0, 'gain' => 0.0],
        ['distance' => 1000.0, 'gain' => 70.0],
        ['distance' => 100.0, 'gain' => -6.0],
        ['distance' => 1200.0, 'gain' => 84.0],
        ['distance' => 300.0, 'gain' => -20.0],
    ]);

    $climbs = $detector->detectFromSeries($seriesWithDip, 'biking');

    expect($climbs)->toHaveCount(1)
        ->and($climbs[0]['distance_meters'])->toBeGreaterThanOrEqual(2100.0)
        ->and($climbs[0]['elevation_gain'])->toBeGreaterThanOrEqual(145.0)
        ->and($climbs[0]['elevation_loss'])->toBeGreaterThan(2.0);
});

it('categorizes climb scores accurately across all thresholds', function () {
    expect(ClimbDetector::categorizeClimb(10000.0, 8.0))->toBe('hc')
        ->and(ClimbDetector::categorizeClimb(8000.0, 8.0))->toBe('cat1')
        ->and(ClimbDetector::categorizeClimb(4000.0, 8.0))->toBe('cat2')
        ->and(ClimbDetector::categorizeClimb(2000.0, 8.0))->toBe('cat3')
        ->and(ClimbDetector::categorizeClimb(1000.0, 8.0))->toBe('cat4')
        ->and(ClimbDetector::categorizeClimb(800.0, 5.0))->toBe('uncategorized');
});

it('rejects physically impossible average grades caused by barometer or lift spikes', function () {
    $detector = new ClimbDetector;

    // 500m gaining 250m (50% average grade) -> rejected for biking (cap 25%) and hiking (cap 40%)
    $verticalSpike = buildSyntheticElevationSeries([
        ['distance' => 200.0, 'gain' => 0.0],
        ['distance' => 500.0, 'gain' => 250.0],
        ['distance' => 200.0, 'gain' => 0.0],
    ]);

    expect($detector->detectFromSeries($verticalSpike, 'biking'))->toBeEmpty()
        ->and($detector->detectFromSeries($verticalSpike, 'hiking'))->toBeEmpty();

    // 1000m gaining 300m (30% average grade) -> rejected for biking (>25%), allowed for steep alpine hiking (<=40%)
    $steepRidge = buildSyntheticElevationSeries([
        ['distance' => 100.0, 'gain' => 0.0],
        ['distance' => 1000.0, 'gain' => 300.0],
        ['distance' => 100.0, 'gain' => 0.0],
    ]);

    expect($detector->detectFromSeries($steepRidge, 'biking'))->toBeEmpty()
        ->and($detector->detectFromSeries($steepRidge, 'hiking'))->toHaveCount(1);
});

it('starts a climb at the foot of the steep section instead of a gentle false-flat approach', function () {
    // 2000m @ 2% false flat (+40m) leading straight into 1500m @ 8% (+120m)
    $series = buildSyntheticElevationSeries([
        ['distance' => 2000.0, 'gain' => 40.0],
        ['distance' => 1500.0, 'gain' => 120.0],
        ['distance' => 300.0, 'gain' => 0.0],
    ]);

    $climbs = (new ClimbDetector)->detectFromSeries($series, 'biking');

    expect($climbs)->toHaveCount(1)
        ->and($climbs[0]['start_index'])->toBeGreaterThanOrEqual(90)
        ->and($climbs[0]['distance_meters'])->toBeLessThanOrEqual(1700.0)
        ->and($climbs[0]['avg_grade_pct'])->toBeGreaterThanOrEqual(7.0)
        ->and($climbs[0]['climb_category'])->toBe('cat4');
});
