<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Services\Heatmap\ExplorerTileService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

/**
 * Inverse Web Mercator: resolve the (lat, lng) of continuous z14 tile coordinates.
 *
 * @return array{0: float, 1: float}
 */
function explorerTilePoint(float $fx, float $fy, int $zoom = 14): array
{
    $n = (float) (1 << $zoom);

    return [
        rad2deg(atan(sinh(M_PI * (1.0 - (2.0 * $fy) / $n)))),
        ($fx / $n) * 360.0 - 180.0,
    ];
}

/**
 * @return array{0: float, 1: float}
 */
function explorerTileCenter(int $x, int $y): array
{
    return explorerTilePoint($x + 0.5, $y + 0.5);
}

/**
 * @param  array{tiles: list<array{0: int, 1: int, 2: int, 3: int}>}  $summary
 * @return array<string, int>
 */
function explorerVisitsByTile(array $summary): array
{
    $visits = [];

    foreach ($summary['tiles'] as [$x, $y, $count]) {
        $visits["{$x}:{$y}"] = $count;
    }

    ksort($visits);

    return $visits;
}

/**
 * @param  list<array{0: float, 1: float}>  $polyline
 */
function createOutdoorTrack(array $polyline, array $attributes = []): Activity
{
    $activity = Activity::factory()->create([
        'sport_category' => 'running',
        'is_indoor' => false,
        'streams_synced_at' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        ...$attributes,
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'polyline' => $polyline,
    ]);

    return $activity;
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

it('clamps polar latitudes and the antimeridian to the edge tiles of the Mercator grid', function () {
    $service = app(ExplorerTileService::class);

    expect($service->latLngToTile(90.0, 180.0))->toBe([16383, 0])
        ->and($service->latLngToTile(89.9, -180.0))->toBe([0, 0])
        ->and($service->latLngToTile(-90.0, 0.0))->toBe([8192, 16383])
        ->and($service->latLngToTile(-85.2, 179.9999))->toBe([16383, 16383]);
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
        ],
        'tiles' => [],
    ]);
});

it('fills skipped tiles between nearby samples and counts each track once per tile', function () {
    // ~837 m wide tiles near 70°N, so a 2-tile hop stays under the 2 km gap limit.
    $summary = app(ExplorerTileService::class)->fromPolylines([
        [explorerTilePoint(8700.5, 3667.5), explorerTilePoint(8702.5, 3667.5)],
        [explorerTilePoint(8700.5, 3667.5), explorerTilePoint(8701.5, 3667.5), explorerTilePoint(8700.6, 3667.5)],
    ]);

    expect($summary['total_tiles'])->toBe(3)
        ->and(explorerVisitsByTile($summary))->toBe([
            '8700:3667' => 2,
            '8701:3667' => 2,
            '8702:3667' => 1,
        ]);
});

it('credits a tile whose corner is clipped by a sparse diagonal segment', function () {
    // The segment enters tile (8701, 3667) for only ~0.1 tile before crossing into (8701, 3666).
    $summary = app(ExplorerTileService::class)->fromPolylines([
        [explorerTilePoint(8700.5, 3667.6), explorerTilePoint(8701.6, 3666.5)],
    ]);

    expect(explorerVisitsByTile($summary))->toBe([
        '8700:3667' => 1,
        '8701:3666' => 1,
        '8701:3667' => 1,
    ]);
});

it('does not credit tiles across GPS gaps longer than the interpolation limit', function () {
    // 5 tiles at ~837 m each is ~4.2 km between consecutive samples.
    $summary = app(ExplorerTileService::class)->fromPolylines([
        [explorerTilePoint(8700.5, 3667.5), explorerTilePoint(8705.5, 3667.5)],
    ]);

    expect(explorerVisitsByTile($summary))->toBe([
        '8700:3667' => 1,
        '8705:3667' => 1,
    ]);
});

it('does not fill the whole grid when a track crosses the antimeridian', function () {
    $summary = app(ExplorerTileService::class)->fromPolylines([
        [[-16.5, 179.999], [-16.5, -179.999]],
    ]);

    expect($summary['total_tiles'])->toBe(2);
});

it('detects 2x2 and 3x3 max squares accurately', function () {
    $service = app(ExplorerTileService::class);

    $twoByTwoPoints = [];

    for ($x = 500; $x <= 501; $x++) {
        for ($y = 600; $y <= 601; $y++) {
            $twoByTwoPoints[] = explorerTileCenter($x, $y);
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
            $threeByThreePoints[] = explorerTileCenter($x, $y);
        }
    }

    $threeByThreeSummary = $service->fromPolylines([
        $twoByTwoPoints,
        $threeByThreePoints,
    ]);

    $clusterTiles = array_values(array_filter(
        $threeByThreeSummary['tiles'],
        fn (array $tile): bool => $tile[3] === 1,
    ));

    expect($threeByThreeSummary['total_tiles'])->toBe(13)
        ->and($threeByThreeSummary['max_square'])->toBe([
            'size' => 3,
            'min_x' => 700,
            'min_y' => 800,
        ])
        ->and($threeByThreeSummary['max_cluster'])->toBe(['size' => 1])
        ->and($clusterTiles)->toBe([[701, 801, 1, 1]]);
});

it('computes the largest 4-connected cluster of interior cluster tiles', function () {
    $service = app(ExplorerTileService::class);

    // 3x3 block: a single interior tile at (101, 101).
    $smallBlock = [];

    for ($x = 100; $x <= 102; $x++) {
        for ($y = 100; $y <= 102; $y++) {
            $smallBlock[] = explorerTileCenter($x, $y);
        }
    }

    // 5x3 block: three connected interior tiles at (201..203, 201).
    $largeBlock = [];

    for ($x = 200; $x <= 204; $x++) {
        for ($y = 200; $y <= 202; $y++) {
            $largeBlock[] = explorerTileCenter($x, $y);
        }
    }

    $summary = $service->fromPolylines([$smallBlock, $largeBlock]);

    $clusterCoords = array_map(
        fn (array $tile): string => "{$tile[0]}:{$tile[1]}",
        array_values(array_filter($summary['tiles'], fn (array $tile): bool => $tile[3] === 1)),
    );
    sort($clusterCoords);

    expect($summary['max_cluster'])->toBe(['size' => 3])
        ->and($clusterCoords)->toBe(['201:201', '202:201', '203:201']);
});

it('keeps every max square and max cluster tile when the returned tile list is capped', function () {
    $pack = fn (int $x, int $y): int => ($x << 20) | $y;

    /** @var array<int, int> $tileVisits */
    $tileVisits = [];

    // A visited-once 5x5 block: max square 5, max cluster 3x3 = 9.
    for ($x = 1000; $x < 1005; $x++) {
        for ($y = 1000; $y < 1005; $y++) {
            $tileVisits[$pack($x, $y)] = 1;
        }
    }

    // More isolated, more-visited tiles than the cap, which would otherwise crowd the block out.
    $isolatedCount = ExplorerTileService::MAX_RETURNED_TILES;

    for ($i = 0; $i < $isolatedCount; $i++) {
        $tileVisits[$pack(4000 + 2 * ($i % 500), 4000 + 2 * intdiv($i, 500))] = 2;
    }

    $summary = app(ExplorerTileService::class)->summarizeTileVisits($tileVisits);

    $returnedBlockTiles = array_values(array_filter(
        $summary['tiles'],
        fn (array $tile): bool => $tile[0] >= 1000 && $tile[0] < 1005 && $tile[1] >= 1000 && $tile[1] < 1005,
    ));

    expect($summary['total_tiles'])->toBe(25 + $isolatedCount)
        ->and($summary['tiles'])->toHaveCount(ExplorerTileService::MAX_RETURNED_TILES)
        ->and($summary['max_square'])->toBe(['size' => 5, 'min_x' => 1000, 'min_y' => 1000])
        ->and($summary['max_cluster'])->toBe(['size' => 9])
        ->and($returnedBlockTiles)->toHaveCount(25)
        ->and(array_sum(array_column($returnedBlockTiles, 3)))->toBe(9);
});

it('builds lifetime explorer tiles from every outdoor track regardless of age or sport', function () {
    createOutdoorTrack(
        [explorerTileCenter(16140, 9990), explorerTileCenter(16141, 9990)],
        ['started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00')],
    );
    createOutdoorTrack(
        [explorerTileCenter(16150, 9995), explorerTileCenter(16150, 9996)],
        [
            'sport_category' => 'biking',
            'started_at_local' => CarbonImmutable::parse('2015-01-10 08:00:00'),
        ],
    );

    $summary = app(ExplorerTileService::class)->lifetimeSummary();

    expect(explorerVisitsByTile($summary))->toBe([
        '16140:9990' => 1,
        '16141:9990' => 1,
        '16150:9995' => 1,
        '16150:9996' => 1,
    ]);
});

it('excludes indoor activities and activities without a polyline from lifetime explorer tiles', function () {
    createOutdoorTrack([explorerTileCenter(16140, 9990), explorerTileCenter(16141, 9990)]);
    createOutdoorTrack(
        [explorerTileCenter(16200, 9900), explorerTileCenter(16201, 9900)],
        ['is_indoor' => true],
    );

    $withoutPolyline = Activity::factory()->create(['is_indoor' => false]);
    ActivityStream::factory()->create([
        'activity_id' => $withoutPolyline->id,
        'polyline' => null,
    ]);

    $summary = app(ExplorerTileService::class)->lifetimeSummary();

    expect(explorerVisitsByTile($summary))->toBe([
        '16140:9990' => 1,
        '16141:9990' => 1,
    ]);
});

it('reuses the cached lifetime summary until the outdoor stream set changes', function () {
    $service = app(ExplorerTileService::class);

    $first = createOutdoorTrack([explorerTileCenter(16140, 9990), explorerTileCenter(16141, 9990)]);

    expect($service->lifetimeSummary()['total_tiles'])->toBe(2);

    DB::enableQueryLog();
    $cached = $service->lifetimeSummary();
    $queriesWhileCached = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($cached['total_tiles'])->toBe(2)
        ->and($queriesWhileCached)->toBe(1);

    createOutdoorTrack([explorerTileCenter(16150, 9995)]);

    expect($service->lifetimeSummary()['total_tiles'])->toBe(3);

    $first->update(['is_indoor' => true]);

    expect($service->lifetimeSummary()['total_tiles'])->toBe(1);
});
