<?php

namespace App\Services\Routes;

use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\RouteCluster;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Fingerprints outdoor GNSS activity polylines and clusters repeated routes into canonical RouteClusters.
 *
 * @phpstan-type MatchRunSummary array{
 *     scanned: int,
 *     matched: int,
 *     clusters_created: int,
 *     matched_routes: int
 * }
 */
class RouteMatchingService
{
    public const float MIN_DISTANCE_METERS = 500.0;

    public const int MIN_POLYLINE_POINTS = 10;

    public const int WAYPOINT_COUNT = 20;

    public const int PREVIEW_MAX_POINTS = 80;

    public const float LOOP_THRESHOLD_METERS = 300.0;

    public const float CANDIDATE_DISTANCE_TOLERANCE = 0.15;

    public const float CANDIDATE_BBOX_PADDING_DEG = 0.005;

    public const float ENDPOINT_MAX_METERS = 300.0;

    public const float MAX_RELATIVE_DISTANCE_DIFF = 0.12;

    private const float EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Generic auto-generated provider titles to deprioritize when deriving a cluster's auto-name.
     *
     * @var array<string, true>
     */
    private const array GENERIC_ACTIVITY_NAMES = [
        'running' => true,
        'cycling' => true,
        'biking' => true,
        'road cycling' => true,
        'mountain biking' => true,
        'gravel cycling' => true,
        'hiking' => true,
        'walking' => true,
        'morning run' => true,
        'afternoon run' => true,
        'evening run' => true,
        'lunch run' => true,
        'night run' => true,
        'morning ride' => true,
        'afternoon ride' => true,
        'evening ride' => true,
        'lunch ride' => true,
        'morning walk' => true,
        'afternoon walk' => true,
        'evening walk' => true,
        'morning hike' => true,
        'afternoon hike' => true,
        'trail run' => true,
        'trail ride' => true,
        'easy run' => true,
        'workout' => true,
        'other' => true,
    ];

    /**
     * Compute or update an activity's route fingerprint and match it into an existing or new RouteCluster.
     */
    public function matchActivity(
        Activity $activity,
        ?ActivityStream $stream = null,
        bool $forceRecompute = false,
    ): ?ActivityRouteFingerprint {
        if ($activity->is_indoor || $activity->distance_meters < self::MIN_DISTANCE_METERS) {
            return null;
        }

        $existing = ActivityRouteFingerprint::query()
            ->where('activity_id', $activity->id)
            ->first();

        if ($existing !== null && $existing->is_unlinked && ! $forceRecompute) {
            return $existing;
        }

        $resolvedStream = $stream ?? $activity->stream()->first();

        if ($resolvedStream === null) {
            return null;
        }

        $points = $this->normalizePolyline($resolvedStream->polyline);

        if (count($points) < self::MIN_POLYLINE_POINTS) {
            return null;
        }

        $cumulativeDistances = $this->computeCumulativeDistances($points);
        $totalPolyDistance = $cumulativeDistances[count($cumulativeDistances) - 1];

        if ($totalPolyDistance <= 0.0) {
            return null;
        }

        $waypoints = $this->resampleWaypoints($points, $cumulativeDistances, self::WAYPOINT_COUNT);
        $previewPoints = $this->simplifyForPreview($points, self::PREVIEW_MAX_POINTS);
        $encodedPreview = HeatmapService::encodePolyline($previewPoints);

        $start = $points[0];
        $end = $points[count($points) - 1];
        $startToEndMeters = round(self::haversineMeters($start[0], $start[1], $end[0], $end[1]), 1);
        $isLoop = $startToEndMeters <= self::LOOP_THRESHOLD_METERS;
        [$bboxMinLat, $bboxMaxLat, $bboxMinLng, $bboxMaxLng] = $this->computeBoundingBox($points);

        $fingerprintData = [
            'start_lat' => $start[0],
            'start_lng' => $start[1],
            'end_lat' => $end[0],
            'end_lng' => $end[1],
            'bbox_min_lat' => $bboxMinLat,
            'bbox_max_lat' => $bboxMaxLat,
            'bbox_min_lng' => $bboxMinLng,
            'bbox_max_lng' => $bboxMaxLng,
            'distance_meters' => round($activity->distance_meters, 1),
            'start_to_end_meters' => $startToEndMeters,
            'is_loop' => $isLoop,
            'waypoints' => $waypoints,
            'encoded_preview_polyline' => $encodedPreview,
            'is_unlinked' => false,
            'computed_at' => CarbonImmutable::now('UTC'),
        ];

        if ($existing !== null && $existing->route_cluster_id !== null && ! $forceRecompute) {
            $existing->update($fingerprintData);
            $cluster = RouteCluster::query()->find($existing->route_cluster_id);

            if ($cluster !== null) {
                $this->refreshCluster($cluster);

                return $existing->fresh() ?? $existing;
            }
        }

        $bestCluster = $this->findBestMatchingCluster(
            sportCategory: $activity->sport_category,
            distanceMeters: $activity->distance_meters,
            startLat: $start[0],
            startLng: $start[1],
            endLat: $end[0],
            endLng: $end[1],
            bboxMinLat: $bboxMinLat,
            bboxMaxLat: $bboxMaxLat,
            bboxMinLng: $bboxMinLng,
            bboxMaxLng: $bboxMaxLng,
            waypoints: $waypoints,
        );

        if ($bestCluster !== null) {
            $fingerprint = ActivityRouteFingerprint::query()->updateOrCreate(
                ['activity_id' => $activity->id],
                [
                    ...$fingerprintData,
                    'route_cluster_id' => $bestCluster->id,
                ],
            );

            $this->refreshCluster($bestCluster);

            return $fingerprint;
        }

        $cluster = RouteCluster::query()->create([
            'name' => $this->deriveClusterName([$activity], $isLoop, $activity->distance_meters),
            'name_override' => null,
            'sport_category' => $activity->sport_category,
            'representative_activity_id' => $activity->id,
            'is_loop' => $isLoop,
            'distance_meters' => round($activity->distance_meters, 1),
            'elevation_gain' => $activity->elevation_gain !== null ? round($activity->elevation_gain, 1) : null,
            'start_lat' => $start[0],
            'start_lng' => $start[1],
            'end_lat' => $end[0],
            'end_lng' => $end[1],
            'bbox_min_lat' => $bboxMinLat,
            'bbox_max_lat' => $bboxMaxLat,
            'bbox_min_lng' => $bboxMinLng,
            'bbox_max_lng' => $bboxMaxLng,
            'waypoints' => $waypoints,
            'encoded_polyline' => $encodedPreview,
            'activity_count' => 1,
            'last_activity_at' => $activity->started_at_local,
        ]);

        return ActivityRouteFingerprint::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                ...$fingerprintData,
                'route_cluster_id' => $cluster->id,
            ],
        );
    }

    /**
     * Recompute aggregate metadata, representative activity, and auto-name for a RouteCluster.
     * Deletes the cluster when zero linked activities remain.
     */
    public function refreshCluster(RouteCluster $cluster): ?RouteCluster
    {
        $fingerprints = ActivityRouteFingerprint::query()
            ->with('activity')
            ->where('route_cluster_id', $cluster->id)
            ->where('is_unlinked', false)
            ->get()
            ->filter(static fn (ActivityRouteFingerprint $fp): bool => $fp->getRelation('activity') instanceof Activity)
            ->values();

        if ($fingerprints->isEmpty()) {
            $cluster->delete();

            return null;
        }

        /** @var list<Activity> $activities */
        $activities = [];
        /** @var list<float> $distances */
        $distances = [];
        /** @var list<float> $elevations */
        $elevations = [];
        $lastActivityAt = null;
        $loopVotes = 0;

        foreach ($fingerprints as $fp) {
            $activity = $fp->activity;
            $activities[] = $activity;
            $distances[] = $activity->distance_meters;

            if ($activity->elevation_gain !== null) {
                $elevations[] = $activity->elevation_gain;
            }

            if ($fp->is_loop) {
                $loopVotes++;
            }

            if ($lastActivityAt === null || $activity->started_at_local->greaterThan($lastActivityAt)) {
                $lastActivityAt = $activity->started_at_local;
            }
        }

        $medianDistance = $this->median($distances);
        $medianElevation = $elevations !== [] ? $this->median($elevations) : null;

        /** @var ActivityRouteFingerprint $representative */
        $representative = $fingerprints->first();
        $bestDistanceDiff = abs($representative->activity->distance_meters - $medianDistance);

        foreach ($fingerprints as $fp) {
            $diff = abs($fp->activity->distance_meters - $medianDistance);

            if (
                $diff < $bestDistanceDiff
                || (
                    abs($diff - $bestDistanceDiff) < 0.01
                    && $fp->activity->started_at_local->greaterThan($representative->activity->started_at_local)
                )
            ) {
                $bestDistanceDiff = $diff;
                $representative = $fp;
            }
        }

        $isLoop = $loopVotes * 2 >= $fingerprints->count();

        $updates = [
            'representative_activity_id' => $representative->activity_id,
            'is_loop' => $isLoop,
            'distance_meters' => round($medianDistance, 1),
            'elevation_gain' => $medianElevation !== null ? round($medianElevation, 1) : null,
            'start_lat' => $representative->start_lat,
            'start_lng' => $representative->start_lng,
            'end_lat' => $representative->end_lat,
            'end_lng' => $representative->end_lng,
            'bbox_min_lat' => $representative->bbox_min_lat,
            'bbox_max_lat' => $representative->bbox_max_lat,
            'bbox_min_lng' => $representative->bbox_min_lng,
            'bbox_max_lng' => $representative->bbox_max_lng,
            'waypoints' => $representative->waypoints,
            'encoded_polyline' => $representative->encoded_preview_polyline,
            'activity_count' => $fingerprints->count(),
            'last_activity_at' => $lastActivityAt,
        ];

        if ($cluster->name_override === null || trim($cluster->name_override) === '') {
            $updates['name'] = $this->deriveClusterName($activities, $isLoop, $medianDistance);
        }

        $cluster->update($updates);

        return $cluster->fresh() ?? $cluster;
    }

    /**
     * Permanently unlink an activity from a route cluster so it will not be re-clustered automatically.
     */
    public function unlinkActivity(RouteCluster $cluster, Activity $activity): bool
    {
        $fingerprint = ActivityRouteFingerprint::query()
            ->where('activity_id', $activity->id)
            ->where('route_cluster_id', $cluster->id)
            ->first();

        if ($fingerprint === null) {
            return false;
        }

        $fingerprint->update([
            'route_cluster_id' => null,
            'is_unlinked' => true,
        ]);

        $this->refreshCluster($cluster);

        return true;
    }

    /**
     * Count eligible outdoor streamed activities that have not yet been fingerprinted.
     */
    public function unfingerprintedCount(): int
    {
        return Activity::query()
            ->where('is_indoor', false)
            ->where('distance_meters', '>=', self::MIN_DISTANCE_METERS)
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.polyline')
                    ->where('activity_streams.polyline', '!=', '[]')
                    ->whereRaw('LENGTH(activity_streams.polyline) >= 50');
            })
            ->whereNotExists(function (QueryBuilder $fpSub): void {
                $fpSub
                    ->selectRaw('1')
                    ->from('activity_route_fingerprints')
                    ->whereColumn('activity_route_fingerprints.activity_id', 'activities.id');
            })
            ->count();
    }

    /**
     * Scan and fingerprint all eligible outdoor streamed activities that do not yet have a fingerprint.
     *
     * @return MatchRunSummary
     */
    public function scanUnfingerprinted(int $chunkSize = 100): array
    {
        $chunkSize = max(1, $chunkSize);
        $clustersBefore = RouteCluster::query()->count();
        $scanned = 0;
        $matched = 0;

        $ids = Activity::query()
            ->where('is_indoor', false)
            ->where('distance_meters', '>=', self::MIN_DISTANCE_METERS)
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.polyline')
                    ->where('activity_streams.polyline', '!=', '[]')
                    ->whereRaw('LENGTH(activity_streams.polyline) >= 50');
            })
            ->whereNotExists(function (QueryBuilder $fpSub): void {
                $fpSub
                    ->selectRaw('1')
                    ->from('activity_route_fingerprints')
                    ->whereColumn('activity_route_fingerprints.activity_id', 'activities.id');
            })
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $scanned++;
                $fingerprint = $this->matchActivity($activity, $activity->stream);

                if ($fingerprint !== null) {
                    $matched++;
                }
            }
        }

        $clustersAfter = RouteCluster::query()->count();

        return [
            'scanned' => $scanned,
            'matched' => $matched,
            'clusters_created' => max(0, $clustersAfter - $clustersBefore),
            'matched_routes' => RouteCluster::query()->matched()->count(),
        ];
    }

    /**
     * Recompute all route fingerprints and rebuild clusters from scratch while preserving
     * user-defined custom route names (`name_override`) and manually unlinked activities (`is_unlinked`).
     *
     * @return MatchRunSummary
     */
    public function rebuildAll(int $chunkSize = 100): array
    {
        $chunkSize = max(1, $chunkSize);

        /** @var array<int, string> $preservedOverridesByActivityId */
        $preservedOverridesByActivityId = [];

        $overriddenClusters = RouteCluster::query()
            ->whereNotNull('name_override')
            ->where('name_override', '!=', '')
            ->with('fingerprints:id,activity_id,route_cluster_id,is_unlinked')
            ->get();

        foreach ($overriddenClusters as $cluster) {
            $override = is_string($cluster->name_override) ? trim($cluster->name_override) : '';

            if ($override === '') {
                continue;
            }

            if ($cluster->representative_activity_id !== null) {
                $preservedOverridesByActivityId[$cluster->representative_activity_id] = $override;
            }

            foreach ($cluster->fingerprints as $fp) {
                if (! $fp->is_unlinked) {
                    $preservedOverridesByActivityId[$fp->activity_id] = $override;
                }
            }
        }

        /** @var array<int, true> $unlinkedActivityIds */
        $unlinkedActivityIds = array_fill_keys(
            array_map(
                'intval',
                ActivityRouteFingerprint::query()
                    ->where('is_unlinked', true)
                    ->pluck('activity_id')
                    ->all(),
            ),
            true,
        );

        DB::transaction(function (): void {
            ActivityRouteFingerprint::query()
                ->where('is_unlinked', false)
                ->delete();

            ActivityRouteFingerprint::query()
                ->where('is_unlinked', true)
                ->update(['route_cluster_id' => null]);

            RouteCluster::query()->delete();
        });

        $ids = Activity::query()
            ->where('is_indoor', false)
            ->where('distance_meters', '>=', self::MIN_DISTANCE_METERS)
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.polyline');
            })
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $scanned = 0;
        $matched = 0;

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $scanned++;

                if (isset($unlinkedActivityIds[$activity->id])) {
                    continue;
                }

                $fingerprint = $this->matchActivity($activity, $activity->stream, forceRecompute: true);

                if ($fingerprint !== null) {
                    $matched++;
                }
            }
        }

        if ($preservedOverridesByActivityId !== []) {
            $this->restorePreservedOverrides($preservedOverridesByActivityId);
        }

        $totalClusters = RouteCluster::query()->count();

        return [
            'scanned' => $scanned,
            'matched' => $matched,
            'clusters_created' => $totalClusters,
            'matched_routes' => RouteCluster::query()->matched()->count(),
        ];
    }

    /**
     * Compute spherical Haversine distance in meters between two latitude/longitude coordinates.
     */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lng2 - $lng1);

        $sinHalfPhi = sin($dPhi / 2.0);
        $sinHalfLambda = sin($dLambda / 2.0);

        $a = ($sinHalfPhi * $sinHalfPhi)
            + (cos($phi1) * cos($phi2) * $sinHalfLambda * $sinHalfLambda);

        $clampedA = min(1.0, max(0.0, $a));

        return 2.0 * self::EARTH_RADIUS_METERS * atan2(sqrt($clampedA), sqrt(1.0 - $clampedA));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $waypoints
     */
    private function findBestMatchingCluster(
        string $sportCategory,
        float $distanceMeters,
        float $startLat,
        float $startLng,
        float $endLat,
        float $endLng,
        float $bboxMinLat,
        float $bboxMaxLat,
        float $bboxMinLng,
        float $bboxMaxLng,
        array $waypoints,
    ): ?RouteCluster {
        $minDistance = $distanceMeters * (1.0 - self::CANDIDATE_DISTANCE_TOLERANCE);
        $maxDistance = $distanceMeters * (1.0 + self::CANDIDATE_DISTANCE_TOLERANCE);

        $candidates = RouteCluster::query()
            ->where('sport_category', $sportCategory)
            ->whereBetween('distance_meters', [$minDistance, $maxDistance])
            ->where('bbox_max_lat', '>=', $bboxMinLat - self::CANDIDATE_BBOX_PADDING_DEG)
            ->where('bbox_min_lat', '<=', $bboxMaxLat + self::CANDIDATE_BBOX_PADDING_DEG)
            ->where('bbox_max_lng', '>=', $bboxMinLng - self::CANDIDATE_BBOX_PADDING_DEG)
            ->where('bbox_min_lng', '<=', $bboxMaxLng + self::CANDIDATE_BBOX_PADDING_DEG)
            ->orderBy('id')
            ->get();

        $meanLimit = min(120.0, max(45.0, $distanceMeters * 0.04));
        $maxLimit = min(450.0, max(150.0, $distanceMeters * 0.12));

        $bestCluster = null;
        $bestMeanDistance = INF;

        foreach ($candidates as $candidate) {
            $startDist = self::haversineMeters($startLat, $startLng, $candidate->start_lat, $candidate->start_lng);

            if ($startDist > self::ENDPOINT_MAX_METERS) {
                continue;
            }

            $endDist = self::haversineMeters($endLat, $endLng, $candidate->end_lat, $candidate->end_lng);

            if ($endDist > self::ENDPOINT_MAX_METERS) {
                continue;
            }

            $maxDistPair = max($distanceMeters, $candidate->distance_meters);

            if ($maxDistPair <= 0.0) {
                continue;
            }

            $relativeDiff = abs($distanceMeters - $candidate->distance_meters) / $maxDistPair;

            if ($relativeDiff > self::MAX_RELATIVE_DISTANCE_DIFF) {
                continue;
            }

            $candidateWaypoints = $candidate->waypoints;

            if (count($candidateWaypoints) !== self::WAYPOINT_COUNT) {
                continue;
            }

            $sumDist = 0.0;
            $maxWaypointDist = 0.0;

            for ($i = 0; $i < self::WAYPOINT_COUNT; $i++) {
                $d = self::haversineMeters(
                    $waypoints[$i][0],
                    $waypoints[$i][1],
                    $candidateWaypoints[$i][0],
                    $candidateWaypoints[$i][1],
                );
                $sumDist += $d;

                if ($d > $maxWaypointDist) {
                    $maxWaypointDist = $d;
                }
            }

            $meanDist = $sumDist / self::WAYPOINT_COUNT;

            if ($meanDist <= $meanLimit && $maxWaypointDist <= $maxLimit && $meanDist < $bestMeanDistance) {
                $bestMeanDistance = $meanDist;
                $bestCluster = $candidate;
            }
        }

        return $bestCluster;
    }

    /**
     * @param  array<int, string>  $preservedOverridesByActivityId
     */
    private function restorePreservedOverrides(array $preservedOverridesByActivityId): void
    {
        $clusters = RouteCluster::query()
            ->with('fingerprints:id,activity_id,route_cluster_id,is_unlinked')
            ->get();

        foreach ($clusters as $cluster) {
            /** @var array<string, int> $votes */
            $votes = [];

            foreach ($cluster->fingerprints as $fp) {
                if ($fp->is_unlinked) {
                    continue;
                }

                $override = $preservedOverridesByActivityId[$fp->activity_id] ?? null;

                if ($override !== null) {
                    $votes[$override] = ($votes[$override] ?? 0) + 1;
                }
            }

            if ($votes === []) {
                continue;
            }

            arsort($votes);
            $winningOverride = (string) array_key_first($votes);

            if ($winningOverride !== '') {
                $cluster->update(['name_override' => $winningOverride]);
            }
        }
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function normalizePolyline(mixed $rawPolyline): array
    {
        if (! is_array($rawPolyline)) {
            return [];
        }

        $points = [];

        foreach ($rawPolyline as $point) {
            if (! is_array($point) || ! array_key_exists(0, $point) || ! array_key_exists(1, $point)) {
                continue;
            }

            $rawLat = $point[0];
            $rawLng = $point[1];

            if (! is_numeric($rawLat) || ! is_numeric($rawLng)) {
                continue;
            }

            $lat = (float) $rawLat;
            $lng = (float) $rawLng;

            if (! is_finite($lat) || ! is_finite($lng)) {
                continue;
            }

            if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                continue;
            }

            $points[] = [round($lat, 5), round($lng, 5)];
        }

        return $points;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<float>
     */
    private function computeCumulativeDistances(array $points): array
    {
        $cumulative = [0.0];
        $running = 0.0;
        $count = count($points);

        for ($i = 1; $i < $count; $i++) {
            $running += self::haversineMeters(
                $points[$i - 1][0],
                $points[$i - 1][1],
                $points[$i][0],
                $points[$i][1],
            );
            $cumulative[] = $running;
        }

        return $cumulative;
    }

    /**
     * Resample a polyline into N evenly spaced [lat, lng] waypoints by cumulative Haversine distance.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @param  list<float>  $cumulativeDistances
     * @return list<array{0: float, 1: float}>
     */
    private function resampleWaypoints(array $points, array $cumulativeDistances, int $count): array
    {
        $pointCount = count($points);
        $totalDistance = $cumulativeDistances[$pointCount - 1];
        $waypoints = [];
        $segmentIdx = 0;

        for ($k = 0; $k < $count; $k++) {
            $target = $count > 1 ? ($k / ($count - 1)) * $totalDistance : 0.0;

            while ($segmentIdx < $pointCount - 2 && $cumulativeDistances[$segmentIdx + 1] < $target) {
                $segmentIdx++;
            }

            $d0 = $cumulativeDistances[$segmentIdx];
            $d1 = $cumulativeDistances[$segmentIdx + 1];
            $span = $d1 - $d0;
            $fraction = $span > 0.0 ? min(1.0, max(0.0, ($target - $d0) / $span)) : 0.0;

            $lat = $points[$segmentIdx][0] + ($points[$segmentIdx + 1][0] - $points[$segmentIdx][0]) * $fraction;
            $lng = $points[$segmentIdx][1] + ($points[$segmentIdx + 1][1] - $points[$segmentIdx][1]) * $fraction;

            $waypoints[] = [round($lat, 5), round($lng, 5)];
        }

        return $waypoints;
    }

    /**
     * Downsample a polyline to at most `$maxPoints` points while preserving exact endpoints.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private function simplifyForPreview(array $points, int $maxPoints): array
    {
        $count = count($points);

        if ($count <= $maxPoints) {
            return $points;
        }

        $result = [];
        $step = ($count - 1) / ($maxPoints - 1);

        for ($i = 0; $i < $maxPoints; $i++) {
            $idx = (int) round($i * $step);
            $idx = min($count - 1, max(0, $idx));
            $result[] = $points[$idx];
        }

        return $result;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function computeBoundingBox(array $points): array
    {
        $minLat = $points[0][0];
        $maxLat = $points[0][0];
        $minLng = $points[0][1];
        $maxLng = $points[0][1];

        foreach ($points as [$lat, $lng]) {
            if ($lat < $minLat) {
                $minLat = $lat;
            }

            if ($lat > $maxLat) {
                $maxLat = $lat;
            }

            if ($lng < $minLng) {
                $minLng = $lng;
            }

            if ($lng > $maxLng) {
                $maxLng = $lng;
            }
        }

        return [$minLat, $maxLat, $minLng, $maxLng];
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    /**
     * Derive a human-friendly route name from cluster member activities.
     *
     * @param  list<Activity>  $activities
     */
    private function deriveClusterName(array $activities, bool $isLoop, float $distanceMeters): string
    {
        /** @var array<string, int> $specificNameVotes */
        $specificNameVotes = [];
        /** @var array<string, int> $locationVotes */
        $locationVotes = [];
        $fallbackName = null;

        foreach ($activities as $activity) {
            $name = trim($activity->name);

            if ($name !== '') {
                $fallbackName ??= $name;

                if (! isset(self::GENERIC_ACTIVITY_NAMES[mb_strtolower($name)])) {
                    $specificNameVotes[$name] = ($specificNameVotes[$name] ?? 0) + 1;
                }
            }

            $location = is_string($activity->location_name) ? trim($activity->location_name) : '';

            if ($location !== '') {
                $locationVotes[$location] = ($locationVotes[$location] ?? 0) + 1;
            }
        }

        if ($specificNameVotes !== []) {
            arsort($specificNameVotes);
            $topName = (string) array_key_first($specificNameVotes);

            return mb_substr($topName, 0, 160);
        }

        if ($locationVotes !== []) {
            arsort($locationVotes);
            $topLocation = (string) array_key_first($locationVotes);
            $km = number_format($distanceMeters / 1000.0, 1);
            $suffix = $isLoop ? 'Loop' : 'Route';

            return mb_substr("{$topLocation} {$km} km {$suffix}", 0, 160);
        }

        if ($fallbackName !== null) {
            return mb_substr($fallbackName, 0, 160);
        }

        $km = number_format($distanceMeters / 1000.0, 1);

        return $isLoop ? "{$km} km Loop" : "{$km} km Route";
    }
}
