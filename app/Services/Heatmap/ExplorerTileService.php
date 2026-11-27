<?php

namespace App\Services\Heatmap;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-type ExplorerMaxSquare array{
 *     size: int,
 *     min_x: int,
 *     min_y: int
 * }
 * @phpstan-type ExplorerMaxCluster array{
 *     size: int
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
 *     tiles: list<array{0: int, 1: int, 2: int, 3: int}>
 * }
 */
class ExplorerTileService
{
    public const int DEFAULT_ZOOM = 14;

    public const int MAX_RETURNED_TILES = 15000;

    public const string LIFETIME_CACHE_KEY = 'heatmap:explorer:lifetime';

    /**
     * Bump whenever the tile algorithm or payload shape changes so cached summaries are rebuilt.
     */
    private const int LIFETIME_CACHE_VERSION = 2;

    private const int LIFETIME_CHUNK_SIZE = 250;

    private const float MAX_MERCATOR_LAT = 85.0511;

    /**
     * Consecutive GPS samples further apart than this are treated as a recording gap
     * (tunnel, ferry, signal loss, bad first fix), so no tiles are credited between them.
     */
    private const float MAX_INTERPOLATION_METERS = 2000.0;

    private const float EARTH_RADIUS_METERS = 6371008.8;

    private const int KEY_SHIFT = 20;

    private const int KEY_MASK = (1 << self::KEY_SHIFT) - 1;

    /**
     * Resolve the lifetime Explorer summary across every outdoor activity with a GNSS polyline.
     * The result is cached and transparently rebuilt whenever the set of synced outdoor streams changes.
     *
     * @return ExplorerSummary
     */
    public function lifetimeSummary(): array
    {
        $fingerprint = $this->lifetimeFingerprint();
        $cached = Cache::get(self::LIFETIME_CACHE_KEY);

        if (
            is_array($cached)
            && ($cached['fingerprint'] ?? null) === $fingerprint
            && is_array($cached['summary'] ?? null)
        ) {
            /** @var ExplorerSummary */
            return $cached['summary'];
        }

        $summary = $this->computeLifetimeSummary();

        Cache::forever(self::LIFETIME_CACHE_KEY, [
            'fingerprint' => $fingerprint,
            'summary' => $summary,
        ]);

        return $summary;
    }

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
     * Each track increments a visited tile's count at most once. When consecutive GPS samples skip
     * over tiles, every tile the straight segment passes through is credited, unless the samples are
     * further apart than MAX_INTERPOLATION_METERS or the segment wraps across the antimeridian.
     *
     * @param  list<array{0: float, 1: float}>  $polyline
     * @param  array<int, int>  $tileVisits
     */
    public function recordPolyline(array $polyline, array &$tileVisits, int $zoom = self::DEFAULT_ZOOM): void
    {
        if ($polyline === []) {
            return;
        }

        $n = (float) (1 << $zoom);
        $maxCoord = (1 << $zoom) - 1;

        /** @var array<int, true> $trackTiles */
        $trackTiles = [];
        $hasPrev = false;
        $prevLat = 0.0;
        $prevLng = 0.0;
        $prevFx = 0.0;
        $prevFy = 0.0;
        $prevX = 0;
        $prevY = 0;

        foreach ($polyline as [$lat, $lng]) {
            [$fx, $fy] = $this->latLngToTileFloat($lat, $lng, $zoom);
            $x = max(0, min($maxCoord, (int) floor($fx)));
            $y = max(0, min($maxCoord, (int) floor($fy)));

            $trackTiles[self::packKey($x, $y)] = true;

            if (
                $hasPrev
                && abs($x - $prevX) + abs($y - $prevY) > 1
                && abs($fx - $prevFx) <= $n / 2.0
                && $this->haversineMeters($prevLat, $prevLng, $lat, $lng) <= self::MAX_INTERPOLATION_METERS
            ) {
                $this->walkGrid($prevFx, $prevFy, $fx, $fy, $maxCoord, $trackTiles);
            }

            $prevLat = $lat;
            $prevLng = $lng;
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
     * Each returned tile is [x, y, visits, is_cluster (0|1)].
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
                ],
                'tiles' => [],
            ];
        }

        /** @var list<int> $keys */
        $keys = array_keys($tileVisits);
        sort($keys, SORT_NUMERIC);

        $maxSquare = $this->computeMaxSquare($keys);
        $maxClusterKeys = $this->computeMaxClusterKeys($keys, $tileVisits, $zoom);
        $tiles = $this->buildReturnedTiles($keys, $tileVisits, $maxSquare, $maxClusterKeys);

        return [
            'zoom' => $zoom,
            'total_tiles' => count($tileVisits),
            'max_square' => $maxSquare,
            'max_cluster' => [
                'size' => count($maxClusterKeys),
            ],
            'tiles' => $tiles,
        ];
    }

    /**
     * Build a cheap fingerprint of the outdoor GNSS stream set so cached summaries are rebuilt
     * when streams are synced, re-synced, deleted, or an activity is toggled indoor/outdoor.
     */
    private function lifetimeFingerprint(): string
    {
        $row = $this->lifetimeTracksQuery()
            ->toBase()
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('COALESCE(SUM(activities.id), 0) as activity_id_sum')
            ->selectRaw('MAX(activity_streams.updated_at) as latest_stream_update')
            ->first();

        return implode('|', [
            self::LIFETIME_CACHE_VERSION,
            (string) ($row->track_count ?? 0),
            (string) ($row->activity_id_sum ?? 0),
            (string) ($row->latest_stream_update ?? ''),
        ]);
    }

    /**
     * @return ExplorerSummary
     */
    private function computeLifetimeSummary(): array
    {
        /** @var array<int, int> $tileVisits */
        $tileVisits = [];

        $this->lifetimeTracksQuery()
            ->toBase()
            ->select([
                'activity_streams.id as stream_id',
                'activity_streams.polyline',
            ])
            ->chunkById(
                self::LIFETIME_CHUNK_SIZE,
                function (Collection $rows) use (&$tileVisits): void {
                    foreach ($rows as $row) {
                        $rawPolyline = is_string($row->polyline)
                            ? json_decode($row->polyline, true)
                            : $row->polyline;

                        $this->recordPolyline(HeatmapService::normalizePolyline($rawPolyline), $tileVisits);
                    }
                },
                'activity_streams.id',
                'stream_id',
            );

        return $this->summarizeTileVisits($tileVisits);
    }

    /**
     * @return Builder<Activity>
     */
    private function lifetimeTracksQuery(): Builder
    {
        return Activity::query()
            ->join('activity_streams', 'activity_streams.activity_id', '=', 'activities.id')
            ->where('activities.is_indoor', false)
            ->whereNotNull('activity_streams.polyline');
    }

    /**
     * Credit every tile a straight segment passes through (Amanatides–Woo grid traversal),
     * stepping exactly one tile per boundary crossing between the start and end tiles.
     *
     * @param  array<int, true>  $trackTiles
     */
    private function walkGrid(
        float $startFx,
        float $startFy,
        float $endFx,
        float $endFy,
        int $maxCoord,
        array &$trackTiles,
    ): void {
        $x = (int) floor($startFx);
        $y = (int) floor($startFy);
        $endX = (int) floor($endFx);
        $endY = (int) floor($endFy);

        $dx = $endFx - $startFx;
        $dy = $endFy - $startFy;
        $stepX = $endX <=> $x;
        $stepY = $endY <=> $y;

        $tDeltaX = $stepX !== 0 ? abs(1.0 / $dx) : INF;
        $tDeltaY = $stepY !== 0 ? abs(1.0 / $dy) : INF;
        $tMaxX = match ($stepX) {
            1 => ($x + 1 - $startFx) / $dx,
            -1 => ($startFx - $x) / -$dx,
            default => INF,
        };
        $tMaxY = match ($stepY) {
            1 => ($y + 1 - $startFy) / $dy,
            -1 => ($startFy - $y) / -$dy,
            default => INF,
        };

        $remainingSteps = abs($endX - $x) + abs($endY - $y);

        for ($i = 0; $i < $remainingSteps; $i++) {
            $advanceX = $y === $endY || ($x !== $endX && $tMaxX < $tMaxY);

            if ($advanceX) {
                $x += $stepX;
                $tMaxX += $tDeltaX;
            } else {
                $y += $stepY;
                $tMaxY += $tDeltaY;
            }

            $trackTiles[self::packKey(
                max(0, min($maxCoord, $x)),
                max(0, min($maxCoord, $y)),
            )] = true;
        }
    }

    private function haversineMeters(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $a = sin($dLat / 2.0) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2.0) ** 2;

        return 2.0 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
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
     * Find the largest 4-connected cluster of interior cluster tiles (tiles with all 4 orthogonal neighbors visited).
     *
     * @param  list<int>  $sortedKeys
     * @param  array<int, int>  $tileVisits
     * @return list<int>
     */
    private function computeMaxClusterKeys(array $sortedKeys, array $tileVisits, int $zoom): array
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

        return $bestClusterKeys;
    }

    /**
     * Order visited tiles for browser rendering, capping at MAX_RETURNED_TILES. Max Square and
     * Max Cluster tiles always come first so their overlays are never truncated, followed by the
     * remaining tiles by visit count descending and proximity to the max square center.
     *
     * @param  list<int>  $sortedKeys
     * @param  array<int, int>  $tileVisits
     * @param  ExplorerMaxSquare  $maxSquare
     * @param  list<int>  $maxClusterKeys
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function buildReturnedTiles(
        array $sortedKeys,
        array $tileVisits,
        array $maxSquare,
        array $maxClusterKeys,
    ): array {
        /** @var array<int, true> $clusterSet */
        $clusterSet = array_fill_keys($maxClusterKeys, true);

        /** @var array<int, true> $prioritySet */
        $prioritySet = $clusterSet;

        for ($dx = 0; $dx < $maxSquare['size']; $dx++) {
            for ($dy = 0; $dy < $maxSquare['size']; $dy++) {
                $prioritySet[self::packKey($maxSquare['min_x'] + $dx, $maxSquare['min_y'] + $dy)] = true;
            }
        }

        $centerX = $maxSquare['min_x'] + max(0, $maxSquare['size'] - 1) / 2.0;
        $centerY = $maxSquare['min_y'] + max(0, $maxSquare['size'] - 1) / 2.0;

        $orderedKeys = $sortedKeys;

        usort($orderedKeys, static function (int $a, int $b) use ($tileVisits, $prioritySet, $centerX, $centerY): int {
            $priorityCmp = isset($prioritySet[$b]) <=> isset($prioritySet[$a]);

            if ($priorityCmp !== 0) {
                return $priorityCmp;
            }

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
                isset($clusterSet[$key]) ? 1 : 0,
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
