<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Heatmap\ExplorerTileService;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

/**
 * Helper to resolve the geographic center (lat, lng) of an OSM Slippy Tile.
 *
 * @return array{0: float, 1: float}
 */
function tileCenter(ExplorerTileService $service, int $x, int $y, int $zoom = 14): array
{
    $bounds = $service->tileBounds($x, $y, $zoom);

    return [
        ($bounds['north'] + $bounds['south']) / 2.0,
        ($bounds['west'] + $bounds['east']) / 2.0,
    ];
}

it('projects coordinates to standard z14 OSM slippy tiles and computes bounding boxes', function () {
    $service = app(ExplorerTileService::class);

    $origin = $service->latLngToTile(0.0, 0.0);
    $auckland = $service->latLngToTile(-36.8485, 174.7633);
    $bounds = $service->tileBounds($auckland[0], $auckland[1]);

    expect($origin)->toBe([8192, 8192])
        ->and($auckland)->toBe([16145, 9998])
        ->and($bounds['north'])->toBeGreaterThan(-36.8485)
        ->and($bounds['south'])->toBeLessThan(-36.8485)
        ->and($bounds['west'])->toBeLessThan(174.7633)
        ->and($bounds['east'])->toBeGreaterThan(174.7633);
});

it('returns an empty explorer summary when no polylines are recorded', function () {
    $summary = app(ExplorerTileService::class)->fromPolylines([]);

    expect($summary)->toBe([
        'zoom' => 14,
        'total_tiles' => 0,
        'max_square' => [
            'size' => 0,
            'min_x' => 0,
            'min_y' => 0,
        ],
        'max_cluster' => [
            'size' => 0,
            'tiles' => [],
        ],
        'tiles' => [],
    ]);
});

it('interpolates intermediate tiles across tile boundaries and counts distinct track visits', function () {
    $service = app(ExplorerTileService::class);

    $start = tileCenter($service, 8200, 8200);
    $end = tileCenter($service, 8204, 8200);

    $summary = $service->fromPolylines([
        [$start, $end],
        [$start, tileCenter($service, 8201, 8200)],
    ]);

    $visitsByCoord = [];

    foreach ($summary['tiles'] as [$x, $y, $visits]) {
        $visitsByCoord["{$x}:{$y}"] = $visits;
    }

    expect($summary['total_tiles'])->toBe(5)
        ->and($visitsByCoord)->toBe([
            '8200:8200' => 2,
            '8201:8200' => 2,
            '8202:8200' => 1,
            '8203:8200' => 1,
            '8204:8200' => 1,
        ])
        ->and($summary['max_square']['size'])->toBe(1)
        ->and($summary['max_cluster']['size'])->toBe(0);
});

it('detects 2x2 and 3x3 max squares accurately', function () {
    $service = app(ExplorerTileService::class);

    $twoByTwoPoints = [];

    for ($x = 500; $x <= 501; $x++) {
        for ($y = 600; $y <= 601; $y++) {
            $twoByTwoPoints[] = tileCenter($service, $x, $y);
        }
    }

    $twoByTwoSummary = $service->fromPolylines([$twoByTwoPoints]);

    expect($twoByTwoSummary['total_tiles'])->toBe(4)
        ->and($twoByTwoSummary['max_square'])->toBe([
            'size' => 2,
            'min_x' => 500,
            'min_y' => 600,
        ])
        ->and($twoByTwoSummary['max_cluster']['size'])->toBe(0);

    $threeByThreePoints = [];

    for ($x = 700; $x <= 702; $x++) {
        for ($y = 800; $y <= 802; $y++) {
            $threeByThreePoints[] = tileCenter($service, $x, $y);
        }
    }

    $threeByThreeSummary = $service->fromPolylines([
        $twoByTwoPoints,
        $threeByThreePoints,
    ]);

    expect($threeByThreeSummary['total_tiles'])->toBe(13)
        ->and($threeByThreeSummary['max_square'])->toBe([
            'size' => 3,
            'min_x' => 700,
            'min_y' => 800,
        ])
        ->and($threeByThreeSummary['max_cluster'])->toBe([
            'size' => 1,
            'tiles' => [[701, 801]],
        ]);
});

it('computes the largest 4-connected cluster of interior cluster tiles', function () {
    $service = app(ExplorerTileService::class);

    // Smaller disconnected cluster: 3x3 block -> 1 interior tile at (101, 101)
    $smallBlock = [];

    for ($x = 100; $x <= 102; $x++) {
        for ($y = 100; $y <= 102; $y++) {
            $smallBlock[] = tileCenter($service, $x, $y);
        }
    }

    // Larger cluster: 4x3 block (200..203, 200..202) -> 2 connected interior tiles at (201, 201) and (202, 201)
    // plus an L-extension at (202, 203), (201, 203), (203, 203), (202, 204) -> adds interior tile (202, 202) and (202, 203)?
    // Keep it exact: a 5x3 block (200..204, 200..202) has exactly 3 connected interior tiles: (201, 201), (202, 201), (203, 201)
    $largeBlock = [];

    for ($x = 200; $x <= 204; $x++) {
        for ($y = 200; $y <= 202; $y++) {
            $largeBlock[] = tileCenter($service, $x, $y);
        }
    }

    $summary = $service->fromPolylines([$smallBlock, $largeBlock]);

    expect($summary['max_cluster'])->toBe([
        'size' => 3,
        'tiles' => [
            [201, 201],
            [202, 201],
            [203, 201],
        ],
    ]);
});

it('populates dynamically filtered explorer tile summaries inside HeatmapService tracks', function () {
    $tileService = app(ExplorerTileService::class);

    $runPolyline = [
        tileCenter($tileService, 16140, 9990),
        tileCenter($tileService, 16141, 9990),
        tileCenter($tileService, 16141, 9991),
        tileCenter($tileService, 16140, 9991),
    ];

    $run = Activity::factory()->create([
        'name' => 'Square Loop Run',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-20 08:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $run->id,
        'polyline' => $runPolyline,
    ]);

    $ride = Activity::factory()->roadBiking()->create([
        'name' => 'Coastal Bike Ride',
        'started_at_local' => CarbonImmutable::parse('2026-01-10 08:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-01-10 10:00:00'),
    ]);
    ActivityStream::factory()->create([
        'activity_id' => $ride->id,
        'polyline' => [
            tileCenter($tileService, 16150, 9995),
            tileCenter($tileService, 16152, 9995),
        ],
    ]);

    $heatmap = app(HeatmapService::class);

    $all = $heatmap->tracks('all', 'all');
    $runningOnly = $heatmap->tracks('running', '30d');

    expect($all['explorer']['total_tiles'])->toBe(7)
        ->and($all['explorer']['max_square'])->toBe([
            'size' => 2,
            'min_x' => 16140,
            'min_y' => 9990,
        ])
        ->and($runningOnly['explorer']['total_tiles'])->toBe(4)
        ->and($runningOnly['explorer']['max_square']['size'])->toBe(2)
        ->and($runningOnly['explorer']['tiles'])->toHaveCount(4);
});
