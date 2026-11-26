<?php

namespace App\Services\Routes;

use App\Jobs\GeocodeRouteClusterJob;
use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\RouteCluster;
use App\Services\Heatmap\HeatmapService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @phpstan-type FingerprintData array{
 *     start_lat: float,
 *     start_lng: float,
 *     end_lat: float,
 *     end_lng: float,
 *     bbox_min_lat: float,
 *     bbox_max_lat: float,
 *     bbox_min_lng: float,
 *     bbox_max_lng: float,
 *     distance_meters: float,
 *     start_to_end_meters: float,
 *     is_loop: bool,
 *     waypoints: list<array{0: float, 1: float}>,
 *     encoded_preview_polyline: string,
 *     is_unlinked: bool,
 *     computed_at: CarbonImmutable
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
     * Stream columns required for fingerprinting (avoids hydrating large per-second `series` payloads).
     *
     * @var list<string>
     */
    private const array STREAM_COLUMNS = ['id', 'activity_id', 'polyline'];

    /**
     * Activity columns required for matching and cluster aggregation.
     *
     * @var list<string>
     */
    private const array ACTIVITY_COLUMNS = [
        'id',
        'name',
        'sport_category',
        'is_indoor',
        'location_name',
        'distance_meters',
        'elevation_gain',
        'started_at_local',
        'route_match_skipped_at',
    ];

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
     * Cluster IDs whose aggregates are awaiting a batched refresh, or null when refreshes run immediately.
     *
     * @var array<int, true>|null
     */
    private ?array $deferredClusterIds = null;

    /**
     * Compute or update an activity's route fingerprint and match it into an existing or new RouteCluster.
     *
     * Activities whose polyline cannot be fingerprinted are flagged with `route_match_skipped_at`
     * so background scans stop revisiting them until their stream changes.
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

        $resolvedStream = $stream ?? $activity->stream()->select(self::STREAM_COLUMNS)->first();

        if ($resolvedStream === null) {
            return null;
        }

        $fingerprintData = $this->buildFingerprintData($activity, $resolvedStream->polyline);

        if ($fingerprintData === null) {
            $this->markIneligible($activity, $existing);

            return null;
        }

        return DB::transaction(
            fn (): ActivityRouteFingerprint => $this->assignFingerprint($activity, $existing, $fingerprintData, $forceRecompute),
        );
    }

    /**
     * Recompute aggregate metadata, representative activity, and auto-name for a RouteCluster.
     * Deletes the cluster when zero linked activities remain.
     */
    public function refreshCluster(RouteCluster $cluster): ?RouteCluster
    {
        $fingerprints = ActivityRouteFingerprint::query()
            ->with('activity:'.implode(',', self::ACTIVITY_COLUMNS))
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

        $medianDistance = self::median($distances);
        $medianElevation = $elevations !== [] ? self::median($elevations) : null;

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
            if (
                ! self::hasSpecificActivityName($activities)
                && $cluster->geocoded_at !== null
                && is_string($cluster->place_label)
                && trim($cluster->place_label) !== ''
            ) {
                $shortPlace = trim(explode(' · ', trim($cluster->place_label))[0]);
                $updates['name'] = self::formatGeocodedClusterName($shortPlace, $isLoop, $medianDistance);
            } else {
                $updates['name'] = $this->deriveClusterName($activities, $isLoop, $medianDistance);
            }
        }

        $cluster->update($updates);

        $refreshed = $cluster->fresh() ?? $cluster;

        if (
            $refreshed->activity_count >= 2
            && $refreshed->geocoded_at === null
            && (bool) config('services.nominatim.enabled', true)
            && (bool) config('services.nominatim.auto_sync', true)
        ) {
            GeocodeRouteClusterJob::dispatch($refreshed->id);
        }

        return $refreshed;
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

        DB::transaction(function () use ($fingerprint, $cluster): void {
            $fingerprint->update([
                'route_cluster_id' => null,
                'is_unlinked' => true,
            ]);

            $this->refreshCluster($cluster);
        });

        return true;
    }

    /**
     * Count eligible outdoor streamed activities still awaiting route matching.
     */
    public function unfingerprintedCount(): int
    {
        return $this->pendingActivitiesQuery()->count();
    }

    /**
     * Scan and fingerprint all eligible outdoor streamed activities still awaiting route matching,
     * including activities orphaned by an interrupted rebuild.
     *
     * @return MatchRunSummary
     */
    public function scanUnfingerprinted(int $chunkSize = 100): array
    {
        $clustersBefore = RouteCluster::query()->count();

        $ids = $this->orderedActivityIds($this->pendingActivitiesQuery());

        [$scanned, $matched] = $this->matchActivityIds($ids, $chunkSize, forceRecompute: false);

        $this->restorePreservedOverrides();

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
     * Custom names are stashed on member fingerprints (`preserved_name_override`) before clusters are
     * dropped, so a rebuild that fails part-way can be resumed by a later scan or rebuild without losing them.
     *
     * @return MatchRunSummary
     */
    public function rebuildAll(int $chunkSize = 100): array
    {
        DB::transaction(function (): void {
            $overriddenClusters = RouteCluster::query()
                ->whereNotNull('name_override')
                ->where('name_override', '!=', '')
                ->get(['id', 'name_override']);

            foreach ($overriddenClusters as $cluster) {
                $override = trim((string) $cluster->name_override);

                if ($override === '') {
                    continue;
                }

                ActivityRouteFingerprint::query()
                    ->where('route_cluster_id', $cluster->id)
                    ->where('is_unlinked', false)
                    ->update(['preserved_name_override' => $override]);
            }

            ActivityRouteFingerprint::query()
                ->whereNotNull('route_cluster_id')
                ->update(['route_cluster_id' => null]);

            RouteCluster::query()->delete();
        });

        $ids = $this->orderedActivityIds($this->eligibleActivitiesQuery());

        $unlinkedActivityIds = array_flip(array_map(
            intval(...),
            ActivityRouteFingerprint::query()->where('is_unlinked', true)->pluck('activity_id')->all(),
        ));

        $idsToMatch = array_values(array_filter(
            $ids,
            static fn (int $id): bool => ! isset($unlinkedActivityIds[$id]),
        ));

        [, $matched] = $this->matchActivityIds($idsToMatch, $chunkSize, forceRecompute: true);

        $this->restorePreservedOverrides();

        ActivityRouteFingerprint::query()
            ->whereNull('route_cluster_id')
            ->where('is_unlinked', false)
            ->delete();

        return [
            'scanned' => count($ids),
            'matched' => $matched,
            'clusters_created' => RouteCluster::query()->count(),
            'matched_routes' => RouteCluster::query()->matched()->count(),
        ];
    }

    /**
     * Downsample a polyline to at most `$maxPoints` points while preserving exact endpoints.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    public static function downsamplePolyline(array $points, int $maxPoints): array
    {
        $count = count($points);

        if ($count <= $maxPoints || $maxPoints < 2) {
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
     * @param  list<float>  $values
     */
    public static function median(array $values): float
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
     * Outdoor activities long enough to fingerprint that have a non-empty GNSS polyline stream.
     *
     * @return Builder<Activity>
     */
    private function eligibleActivitiesQuery(): Builder
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
                    ->where('activity_streams.polyline', '!=', '[]');
            });
    }

    /**
     * Eligible activities that are neither flagged as un-fingerprintable nor already linked/unlinked.
     *
     * @return Builder<Activity>
     */
    private function pendingActivitiesQuery(): Builder
    {
        return $this->eligibleActivitiesQuery()
            ->whereNull('route_match_skipped_at')
            ->whereNotExists(function (QueryBuilder $fpSub): void {
                $fpSub
                    ->selectRaw('1')
                    ->from('activity_route_fingerprints')
                    ->whereColumn('activity_route_fingerprints.activity_id', 'activities.id')
                    ->where(function (QueryBuilder $state): void {
                        $state
                            ->whereNotNull('activity_route_fingerprints.route_cluster_id')
                            ->orWhere('activity_route_fingerprints.is_unlinked', true);
                    });
            });
    }

    /**
     * Activity IDs from the given query in chronological matching order.
     *
     * @param  Builder<Activity>  $query
     * @return list<int>
     */
    private function orderedActivityIds(Builder $query): array
    {
        return array_values(array_map(
            intval(...),
            $query->orderBy('started_at_local')->orderBy('id')->pluck('id')->all(),
        ));
    }

    /**
     * Match activities in chunks, batching cluster aggregate refreshes once per chunk.
     *
     * @param  list<int>  $ids
     * @return array{0: int, 1: int}
     */
    private function matchActivityIds(array $ids, int $chunkSize, bool $forceRecompute): array
    {
        $chunkSize = max(1, $chunkSize);
        $scanned = 0;
        $matched = 0;
        $this->deferredClusterIds = [];

        try {
            foreach (array_chunk($ids, $chunkSize) as $idChunk) {
                $activities = Activity::query()
                    ->select(self::ACTIVITY_COLUMNS)
                    ->with('stream:'.implode(',', self::STREAM_COLUMNS))
                    ->whereIn('id', $idChunk)
                    ->orderBy('started_at_local')
                    ->orderBy('id')
                    ->get();

                foreach ($activities as $activity) {
                    $scanned++;

                    if ($this->matchActivity($activity, $activity->stream, $forceRecompute) !== null) {
                        $matched++;
                    }
                }

                $this->flushDeferredClusterRefreshes();
            }
        } finally {
            $pendingClusterIds = array_keys($this->deferredClusterIds ?? []);
            $this->deferredClusterIds = null;
            $this->refreshClustersById($pendingClusterIds);
        }

        return [$scanned, $matched];
    }

    /**
     * @param  FingerprintData  $fingerprintData
     */
    private function assignFingerprint(
        Activity $activity,
        ?ActivityRouteFingerprint $existing,
        array $fingerprintData,
        bool $forceRecompute,
    ): ActivityRouteFingerprint {
        $previousClusterId = $existing?->route_cluster_id;

        if ($existing !== null && $previousClusterId !== null) {
            $hasOtherMembers = ActivityRouteFingerprint::query()
                ->where('route_cluster_id', $previousClusterId)
                ->where('is_unlinked', false)
                ->where('activity_id', '!=', $activity->id)
                ->exists();

            if (! $hasOtherMembers && ! $forceRecompute) {
                // Sole member: update in place so the cluster keeps its identity and custom name.
                $existing->update($fingerprintData);
                $this->refreshClustersById([$previousClusterId]);
                $this->clearSkippedMarker($activity);

                return $existing->fresh() ?? $existing;
            }

            // Detach and refresh the previous cluster first so the re-evaluation is not compared
            // against this activity's own stale geometry as the cluster representative.
            $existing->update([...$fingerprintData, 'route_cluster_id' => null]);
            $this->refreshClustersById([$previousClusterId]);
        }

        $bestCluster = $this->findBestMatchingCluster($activity->sport_category, $activity->distance_meters, $fingerprintData);

        if ($bestCluster === null) {
            $bestCluster = RouteCluster::query()->create([
                'name' => $this->deriveClusterName([$activity], $fingerprintData['is_loop'], $activity->distance_meters),
                'name_override' => null,
                'sport_category' => $activity->sport_category,
                'representative_activity_id' => $activity->id,
                'is_loop' => $fingerprintData['is_loop'],
                'distance_meters' => round($activity->distance_meters, 1),
                'elevation_gain' => $activity->elevation_gain !== null ? round($activity->elevation_gain, 1) : null,
                'start_lat' => $fingerprintData['start_lat'],
                'start_lng' => $fingerprintData['start_lng'],
                'end_lat' => $fingerprintData['end_lat'],
                'end_lng' => $fingerprintData['end_lng'],
                'bbox_min_lat' => $fingerprintData['bbox_min_lat'],
                'bbox_max_lat' => $fingerprintData['bbox_max_lat'],
                'bbox_min_lng' => $fingerprintData['bbox_min_lng'],
                'bbox_max_lng' => $fingerprintData['bbox_max_lng'],
                'waypoints' => $fingerprintData['waypoints'],
                'encoded_polyline' => $fingerprintData['encoded_preview_polyline'],
                'activity_count' => 1,
                'last_activity_at' => $activity->started_at_local,
            ]);
        }

        $fingerprint = ActivityRouteFingerprint::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                ...$fingerprintData,
                'route_cluster_id' => $bestCluster->id,
            ],
        );

        if (! $bestCluster->wasRecentlyCreated) {
            $this->queueClusterRefresh($bestCluster->id);
        }

        $this->clearSkippedMarker($activity);

        return $fingerprint;
    }

    /**
     * @return FingerprintData|null
     */
    private function buildFingerprintData(Activity $activity, mixed $rawPolyline): ?array
    {
        $points = $this->normalizePolyline($rawPolyline);

        if (count($points) < self::MIN_POLYLINE_POINTS) {
            return null;
        }

        $cumulativeDistances = $this->computeCumulativeDistances($points);
        $totalPolyDistance = $cumulativeDistances[count($cumulativeDistances) - 1];

        if ($totalPolyDistance <= 0.0) {
            return null;
        }

        $start = $points[0];
        $end = $points[count($points) - 1];
        $startToEndMeters = round(self::haversineMeters($start[0], $start[1], $end[0], $end[1]), 1);
        [$bboxMinLat, $bboxMaxLat, $bboxMinLng, $bboxMaxLng] = $this->computeBoundingBox($points);

        return [
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
            'is_loop' => $startToEndMeters <= self::LOOP_THRESHOLD_METERS,
            'waypoints' => $this->resampleWaypoints($points, $cumulativeDistances, self::WAYPOINT_COUNT),
            'encoded_preview_polyline' => HeatmapService::encodePolyline(
                self::downsamplePolyline($points, self::PREVIEW_MAX_POINTS),
            ),
            'is_unlinked' => false,
            'computed_at' => CarbonImmutable::now('UTC'),
        ];
    }

    /**
     * Flag an activity whose polyline cannot be fingerprinted and drop any stale linked fingerprint.
     */
    private function markIneligible(Activity $activity, ?ActivityRouteFingerprint $existing): void
    {
        DB::transaction(function () use ($activity, $existing): void {
            if ($existing !== null && ! $existing->is_unlinked) {
                $previousClusterId = $existing->route_cluster_id;
                $existing->delete();

                if ($previousClusterId !== null) {
                    $this->refreshClustersById([$previousClusterId]);
                }
            }

            if ($activity->route_match_skipped_at === null) {
                $activity->update(['route_match_skipped_at' => CarbonImmutable::now('UTC')]);
            }
        });
    }

    private function clearSkippedMarker(Activity $activity): void
    {
        if ($activity->route_match_skipped_at !== null) {
            $activity->update(['route_match_skipped_at' => null]);
        }
    }

    private function queueClusterRefresh(int $clusterId): void
    {
        if ($this->deferredClusterIds !== null) {
            $this->deferredClusterIds[$clusterId] = true;

            return;
        }

        $this->refreshClustersById([$clusterId]);
    }

    private function flushDeferredClusterRefreshes(): void
    {
        if ($this->deferredClusterIds === null || $this->deferredClusterIds === []) {
            return;
        }

        $clusterIds = array_keys($this->deferredClusterIds);
        $this->deferredClusterIds = [];
        $this->refreshClustersById($clusterIds);
    }

    /**
     * @param  list<int>  $clusterIds
     */
    private function refreshClustersById(array $clusterIds): void
    {
        if ($clusterIds === []) {
            return;
        }

        $clusters = RouteCluster::query()->whereIn('id', $clusterIds)->get();

        foreach ($clusters as $cluster) {
            $this->refreshCluster($cluster);
        }
    }

    /**
     * @param  FingerprintData  $fingerprint
     */
    private function findBestMatchingCluster(
        string $sportCategory,
        float $distanceMeters,
        array $fingerprint,
    ): ?RouteCluster {
        [
            'start_lat' => $startLat,
            'start_lng' => $startLng,
            'end_lat' => $endLat,
            'end_lng' => $endLng,
            'bbox_min_lat' => $bboxMinLat,
            'bbox_max_lat' => $bboxMaxLat,
            'bbox_min_lng' => $bboxMinLng,
            'bbox_max_lng' => $bboxMaxLng,
            'waypoints' => $waypoints,
        ] = $fingerprint;

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
     * Re-apply custom names stashed on fingerprints by `rebuildAll()` to the clusters those fingerprints
     * now belong to, then clear the stash for re-clustered fingerprints. Fingerprints not yet re-clustered
     * keep their stash so an interrupted rebuild can still restore them later.
     */
    private function restorePreservedOverrides(): void
    {
        DB::transaction(function (): void {
            $preserved = ActivityRouteFingerprint::query()
                ->whereNotNull('preserved_name_override')
                ->whereNotNull('route_cluster_id')
                ->where('is_unlinked', false)
                ->get(['id', 'route_cluster_id', 'preserved_name_override']);

            if ($preserved->isEmpty()) {
                return;
            }

            /** @var array<int, array<string, int>> $votesByClusterId */
            $votesByClusterId = [];

            foreach ($preserved as $fp) {
                $override = trim((string) $fp->preserved_name_override);

                if ($override === '') {
                    continue;
                }

                $clusterId = (int) $fp->route_cluster_id;
                $votesByClusterId[$clusterId][$override] = ($votesByClusterId[$clusterId][$override] ?? 0) + 1;
            }

            $clusters = RouteCluster::query()
                ->whereIn('id', array_keys($votesByClusterId))
                ->get();

            foreach ($clusters as $cluster) {
                if (is_string($cluster->name_override) && trim($cluster->name_override) !== '') {
                    continue;
                }

                $votes = $votesByClusterId[$cluster->id];
                arsort($votes);

                $cluster->update(['name_override' => (string) array_key_first($votes)]);
            }

            ActivityRouteFingerprint::query()
                ->whereKey($preserved->modelKeys())
                ->update(['preserved_name_override' => null]);
        });
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
     * Determine whether an activity title is a generic provider-generated default rather than a custom name.
     */
    public static function isGenericActivityName(string $name, ?string $locationName = null): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '') {
            return true;
        }

        $lower = mb_strtolower($trimmed);

        if (isset(self::GENERIC_ACTIVITY_NAMES[$lower])) {
            return true;
        }

        if ($locationName !== null && trim($locationName) !== '') {
            $locLower = mb_strtolower(trim($locationName));

            if (str_starts_with($lower, $locLower.' ')) {
                $remainder = trim(mb_substr($lower, mb_strlen($locLower)));

                if (isset(self::GENERIC_ACTIVITY_NAMES[$remainder])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether any linked activity carries a non-generic custom title.
     *
     * @param  list<Activity>  $activities
     */
    public static function hasSpecificActivityName(array $activities): bool
    {
        foreach ($activities as $activity) {
            if (! self::isGenericActivityName($activity->name, $activity->location_name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a cluster's auto-generated name looks like a generic fallback that can be upgraded
     * with a reverse-geocoded OSM place label.
     */
    public static function isGenericClusterName(string $name): bool
    {
        $trimmed = trim($name);

        if ($trimmed === '' || self::isGenericActivityName($trimmed)) {
            return true;
        }

        if (preg_match('/^(?:.+\s+)?\d+(?:\.\d+)?\s*km\s+(?:Loop|Route)$/i', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^.+\s+→\s+.+\s+\(\d+(?:\.\d+)?\s*km\)$/u', $trimmed) === 1;
    }

    /**
     * Check whether a RouteCluster's auto-name should be upgraded using reverse-geocoded place naming.
     * Never allows overwriting `name_override` or custom non-generic activity titles.
     *
     * @param  list<Activity>  $activities
     */
    public static function shouldUpgradeClusterNameWithPlace(RouteCluster $cluster, array $activities = []): bool
    {
        if (is_string($cluster->name_override) && trim($cluster->name_override) !== '') {
            return false;
        }

        if ($activities !== []) {
            return ! self::hasSpecificActivityName($activities);
        }

        return self::isGenericClusterName($cluster->name);
    }

    /**
     * Format a clean auto-name for a geocoded route cluster (e.g. "Cornwall Park 5.2 km Loop"
     * or "Ponsonby → Mission Bay (8.4 km)").
     */
    public static function formatGeocodedClusterName(string $place, bool $isLoop, float $distanceMeters): string
    {
        $cleanPlace = trim($place);
        $km = number_format($distanceMeters / 1000.0, 1);

        if (! $isLoop && str_contains($cleanPlace, '→')) {
            return mb_substr("{$cleanPlace} ({$km} km)", 0, 160);
        }

        $suffix = $isLoop ? 'Loop' : 'Route';

        return mb_substr("{$cleanPlace} {$km} km {$suffix}", 0, 160);
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

                if (! self::isGenericActivityName($name, $activity->location_name)) {
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
