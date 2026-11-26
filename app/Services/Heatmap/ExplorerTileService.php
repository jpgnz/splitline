<?php

namespace App\Services\Heatmap;

/**
 * @phpstan-type ExplorerMaxSquare array{
 *     size: int,
 *     min_x: int,
 *     min_y: int
 * }
 * @phpstan-type ExplorerMaxCluster array{
 *     size: int,
 *     tiles: list<array{0: int, 1: int}>
 * }
 * @phpstan-type ExplorerTileBounds array{
 *     north: float,
 *     south: float,
 *     west: float,
 *     east: float
 * }
 * @phpstan-type ExplorerSummary array{
 *     zoom: int,
 *     total_tiles: int,
 *     max_square: ExplorerMaxSquare,
 *     max_cluster: ExplorerMaxCluster,
 *     tiles: list<array{0: int, 1: int, 2: int}>
 * }
 */
class ExplorerTileService
{
    public const int DEFAULT_ZOOM = 14;

    public const int MAX_RETURNED_TILES = 15000;

    private const float MAX_MERCATOR_LAT = 85.0511;

    private const int MAX_INTERPOLATION_TILES = 128;

    private const int KEY_SHIFT = 20;

    private const int KEY_MASK = (1 << self::KEY_SHIFT) - 1;

    /**
     * Project a WGS84 latitude/longitude coordinate to an OSM Slippy Tile (x, y) index.
     *
     * @return array{0: int, 1: int}
     */
    public function latLngToTile(float $lat, float $lng, int $zoom = self::DEFAULT_ZOOM): array
    {
        $maxCoord = (1 << $zoom) - 1;
        [$fx, $fy] = $this->latLngToTileFloat($lat, $lng, $zoom);

        return [
            max(0, min($maxCoord, (int) floor($fx))),
            max(0, min($maxCoord, (int) floor($fy))),
        ];
    }

    /**
     * Resolve the WGS84 geographic bounding box for an OSM Slippy Tile (x, y) at the given zoom.
     *
     * @return ExplorerTileBounds
     */
    public function tileBounds(int $x, int $y, int $zoom = self::DEFAULT_ZOOM): array
    {
        $n = (float) (1 << $zoom);
        $west = ($x / $n) * 360.0 - 180.0;
        $east = (($x + 1) / $n) * 360.0 - 180.0;
        $north = rad2deg(atan(sinh(M_PI * (1.0 - (2.0 * $y) / $n))));
        $south = rad2deg(atan(sinh(M_PI * (1.0 - (2.0 * ($y + 1)) / $n))));

        return [
            'north' => $north,
            'south' => $south,
            'west' => $west,
            'east' => $east,
        ];
    }

    /**
     * Record every visited tile along a single activity polyline into the shared tile visit map.
     * Each track increments a visited tile's count at most once, interpolating intermediate tiles
     * when consecutive GPS samples cross tile boundaries.
     *
     * @param  list<array{0: float, 1: float}>  $polyline
     * @param  array<int, int>  $tileVisits
     */
    public function recordPolyline(array $polyline, array &$tileVisits, int $zoom = self::DEFAULT_ZOOM): void
    {
        if ($polyline === []) {
            return;
        }

        $maxCoord = (1 << $zoom) - 1;

        /** @var array<int, true> $trackTiles */
        $trackTiles = [];
        $hasPrev = false;
        $prevFx = 0.0;
        $prevFy = 0.0;
        $prevX = 0;
        $prevY = 0;

        foreach ($polyline as [$lat, $lng]) {
            [$fx, $fy] = $this->latLngToTileFloat($lat, $lng, $zoom);
            $x = max(0, min($maxCoord, (int) floor($fx)));
            $y = max(0, min($maxCoord, (int) floor($fy)));

            $trackTiles[self::packKey($x, $y)] = true;

            if ($hasPrev && ($x !== $prevX || $y !== $prevY)) {
                $manhattan = abs($x - $prevX) + abs($y - $prevY);

                if ($manhattan <= self::MAX_INTERPOLATION_TILES) {
                    $steps = max(2, (int) ceil(max(abs($fx - $prevFx), abs($fy - $prevFy)) * 2.0));

                    for ($step = 1; $step < $steps; $step++) {
                        $t = $step / $steps;
                        $ix = max(0, min($maxCoord, (int) floor($prevFx + ($fx - $prevFx) * $t)));
                        $iy = max(0, min($maxCoord, (int) floor($prevFy + ($fy - $prevFy) * $t)));
                        $trackTiles[self::packKey($ix, $iy)] = true;
                    }
                }
            }

            $prevFx = $fx;
            $prevFy = $fy;
            $prevX = $x;
            $prevY = $y;
            $hasPrev = true;
        }

        foreach (array_keys($trackTiles) as $key) {
            $tileVisits[$key] = ($tileVisits[$key] ?? 0) + 1;
        }
    }

    /**
     * Convenience wrapper to compute an ExplorerSummary from an iterable of polylines.
     *
     * @param  iterable<list<array{0: float, 1: float}>>  $polylines
     * @return ExplorerSummary
     */
    public function fromPolylines(iterable $polylines, int $zoom = self::DEFAULT_ZOOM): array
    {
        /** @var array<int, int> $tileVisits */
        $tileVisits = [];

        foreach ($polylines as $polyline) {
            $this->recordPolyline($polyline, $tileVisits, $zoom);
        }

        return $this->summarizeTileVisits($tileVisits, $zoom);
    }

    /**
     * Compute Explorer Grid metrics (Total Tiles, Max Square, Max Cluster) from accumulated tile visits.
     *
     * @param  array<int, int>  $tileVisits
     * @return ExplorerSummary
     */
    public function summarizeTileVisits(array $tileVisits, int $zoom = self::DEFAULT_ZOOM): array
    {
        if ($tileVisits === []) {
            return [
                'zoom' => $zoom,
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
            ];
        }

        /** @var list<int> $keys */
        $keys = array_keys($tileVisits);
        sort($keys, SORT_NUMERIC);

        $maxSquare = $this->computeMaxSquare($keys);
        $maxCluster = $this->computeMaxCluster($keys, $tileVisits, $zoom);
        $tiles = $this->buildReturnedTiles($keys, $tileVisits, $maxSquare);

        return [
            'zoom' => $zoom,
            'total_tiles' => count($tileVisits),
            'max_square' => $maxSquare,
            'max_cluster' => $maxCluster,
            'tiles' => $tiles,
        ];
    }

    /**
     * Compute the largest solid k x k square of visited tiles in O(N) using dynamic programming.
     *
     * @param  list<int>  $sortedKeys
     * @return ExplorerMaxSquare
     */
    private function computeMaxSquare(array $sortedKeys): array
    {
        /** @var array<int, int> $dp */
        $dp = [];
        $bestSize = 0;
        $bestMaxX = 0;
        $bestMaxY = 0;

        foreach ($sortedKeys as $key) {
            $x = $key >> self::KEY_SHIFT;
            $y = $key & self::KEY_MASK;

            $left = $x > 0 ? ($dp[self::packKey($x - 1, $y)] ?? 0) : 0;
            $up = $y > 0 ? ($dp[self::packKey($x, $y - 1)] ?? 0) : 0;
            $diag = ($x > 0 && $y > 0) ? ($dp[self::packKey($x - 1, $y - 1)] ?? 0) : 0;

            $size = 1 + min($left, $up, $diag);
            $dp[$key] = $size;

            if ($size > $bestSize) {
                $bestSize = $size;
                $bestMaxX = $x;
                $bestMaxY = $y;
            }
        }

        return [
            'size' => $bestSize,
            'min_x' => $bestSize > 0 ? $bestMaxX - $bestSize + 1 : 0,
            'min_y' => $bestSize > 0 ? $bestMaxY - $bestSize + 1 : 0,
        ];
    }

    /**
     * Compute the largest 4-connected cluster of interior cluster tiles (tiles with all 4 orthogonal neighbors visited).
     *
     * @param  list<int>  $sortedKeys
     * @param  array<int, int>  $tileVisits
     * @return ExplorerMaxCluster
     */
    private function computeMaxCluster(array $sortedKeys, array $tileVisits, int $zoom): array
    {
        $maxCoord = (1 << $zoom) - 1;

        /** @var array<int, true> $clusterTileSet */
        $clusterTileSet = [];

        foreach ($sortedKeys as $key) {
            $x = $key >> self::KEY_SHIFT;
            $y = $key & self::KEY_MASK;

            if ($x <= 0 || $y <= 0 || $x >= $maxCoord || $y >= $maxCoord) {
                continue;
            }

            if (
                isset($tileVisits[self::packKey($x - 1, $y)])
                && isset($tileVisits[self::packKey($x + 1, $y)])
                && isset($tileVisits[self::packKey($x, $y - 1)])
                && isset($tileVisits[self::packKey($x, $y + 1)])
            ) {
                $clusterTileSet[$key] = true;
            }
        }

        if ($clusterTileSet === []) {
            return [
                'size' => 0,
                'tiles' => [],
            ];
        }

        /** @var array<int, true> $visited */
        $visited = [];
        /** @var list<int> $bestClusterKeys */
        $bestClusterKeys = [];

        foreach (array_keys($clusterTileSet) as $startKey) {
            if (isset($visited[$startKey])) {
                continue;
            }

            $visited[$startKey] = true;
            $queue = [$startKey];
            $head = 0;

            while ($head < count($queue)) {
                $current = $queue[$head];
                $head++;

                $cx = $current >> self::KEY_SHIFT;
                $cy = $current & self::KEY_MASK;

                $neighbors = [
                    self::packKey($cx - 1, $cy),
                    self::packKey($cx + 1, $cy),
                    self::packKey($cx, $cy - 1),
                    self::packKey($cx, $cy + 1),
                ];

                foreach ($neighbors as $neighborKey) {
                    if (isset($clusterTileSet[$neighborKey]) && ! isset($visited[$neighborKey])) {
                        $visited[$neighborKey] = true;
                        $queue[] = $neighborKey;
                    }
                }
            }

            if (count($queue) > count($bestClusterKeys)) {
                $bestClusterKeys = $queue;
            }
        }

        $clusterSize = count($bestClusterKeys);
        sort($bestClusterKeys, SORT_NUMERIC);

        if ($clusterSize > self::MAX_RETURNED_TILES) {
            $bestClusterKeys = array_slice($bestClusterKeys, 0, self::MAX_RETURNED_TILES);
        }

        $tiles = [];

        foreach ($bestClusterKeys as $key) {
            $tiles[] = [
                $key >> self::KEY_SHIFT,
                $key & self::KEY_MASK,
            ];
        }

        return [
            'size' => $clusterSize,
            'tiles' => $tiles,
        ];
    }

    /**
     * Order visited tiles by visit count descending and proximity to the max square center,
     * capping cleanly at MAX_RETURNED_TILES for browser map rendering.
     *
     * @param  list<int>  $sortedKeys
     * @param  array<int, int>  $tileVisits
     * @param  ExplorerMaxSquare  $maxSquare
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function buildReturnedTiles(array $sortedKeys, array $tileVisits, array $maxSquare): array
    {
        $centerX = $maxSquare['min_x'] + max(0, $maxSquare['size'] - 1) / 2.0;
        $centerY = $maxSquare['min_y'] + max(0, $maxSquare['size'] - 1) / 2.0;

        $orderedKeys = $sortedKeys;

        usort($orderedKeys, static function (int $a, int $b) use ($tileVisits, $centerX, $centerY): int {
            $visitCmp = $tileVisits[$b] <=> $tileVisits[$a];

            if ($visitCmp !== 0) {
                return $visitCmp;
            }

            $ax = $a >> self::KEY_SHIFT;
            $ay = $a & self::KEY_MASK;
            $bx = $b >> self::KEY_SHIFT;
            $by = $b & self::KEY_MASK;

            $distCmp = (abs($ax - $centerX) + abs($ay - $centerY))
                <=> (abs($bx - $centerX) + abs($by - $centerY));

            if ($distCmp !== 0) {
                return $distCmp;
            }

            return $a <=> $b;
        });

        if (count($orderedKeys) > self::MAX_RETURNED_TILES) {
            $orderedKeys = array_slice($orderedKeys, 0, self::MAX_RETURNED_TILES);
        }

        $result = [];

        foreach ($orderedKeys as $key) {
            $result[] = [
                $key >> self::KEY_SHIFT,
                $key & self::KEY_MASK,
                $tileVisits[$key],
            ];
        }

        return $result;
    }

    /**
     * Project a latitude/longitude pair into continuous Web Mercator tile coordinates.
     *
     * @return array{0: float, 1: float}
     */
    private function latLngToTileFloat(float $lat, float $lng, int $zoom): array
    {
        $n = (float) (1 << $zoom);
        $clampedLng = max(-180.0, min(180.0, $lng));
        $fx = (($clampedLng + 180.0) / 360.0) * $n;

        $clampedLat = max(-self::MAX_MERCATOR_LAT, min(self::MAX_MERCATOR_LAT, $lat));
        $latRad = deg2rad($clampedLat);
        $fy = (1.0 - log(tan($latRad) + 1.0 / cos($latRad)) / M_PI) / 2.0 * $n;

        return [
            max(0.0, min($n - 1e-9, $fx)),
            max(0.0, min($n - 1e-9, $fy)),
        ];
    }

    private static function packKey(int $x, int $y): int
    {
        return ($x << self::KEY_SHIFT) | $y;
    }
}
