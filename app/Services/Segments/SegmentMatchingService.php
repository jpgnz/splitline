<?php

namespace App\Services\Segments;

use App\Jobs\GeocodeSegmentJob;
use App\Jobs\MatchSegmentBackfillJob;
use App\Jobs\RefreshSubClimbFlagsJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Models\WeightMeasurement;
use App\Services\Heatmap\HeatmapService;
use App\Services\Routes\RouteMatchingService;
use App\Support\AppSettings;
use App\Support\HealthSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Matches outdoor GNSS telemetry streams against personal segments and auto-detected climbs,
 * manages custom segment creation & historical backfill, and maintains personal effort rankings.
 *
 * @phpstan-type GnssStreamPoint array{
 *     orig_index: int,
 *     time: float,
 *     distance: float,
 *     altitude: ?float,
 *     speed: ?float,
 *     hr: ?int,
 *     power: ?float,
 *     cadence: ?float,
 *     lat: float,
 *     lng: float
 * }
 * @phpstan-type MatchedPass array{
 *     entry_idx: int,
 *     exit_idx: int,
 *     mean_distance_meters: float
 * }
 * @phpstan-type ActivityScanSummary array{
 *     efforts_recorded: int,
 *     climbs_created: int
 * }
 * @phpstan-type SegmentScanRunSummary array{
 *     scanned: int,
 *     efforts_recorded: int,
 *     climbs_created: int,
 *     segments_total: int
 * }
 * @phpstan-type ClimbObservation array{
 *     activity_id: int,
 *     activity_location: ?string,
 *     climb: array{
 *         distance_meters: float,
 *         elevation_gain: float,
 *         elevation_loss: float,
 *         avg_grade_pct: float,
 *         max_grade_pct: float,
 *         fiets_score: float,
 *         climb_category: string
 *     },
 *     start_lat: float,
 *     start_lng: float,
 *     end_lat: float,
 *     end_lng: float,
 *     bbox_min_lat: float,
 *     bbox_max_lat: float,
 *     bbox_min_lng: float,
 *     bbox_max_lng: float,
 *     waypoints: list<array{0: float, 1: float}>,
 *     encoded_polyline: string
 * }
 * @phpstan-type ClimbCluster array{
 *     rep_distance: float,
 *     rep_start_lat: float,
 *     rep_start_lng: float,
 *     rep_end_lat: float,
 *     rep_end_lng: float,
 *     rep_bbox_min_lat: float,
 *     rep_bbox_max_lat: float,
 *     rep_bbox_min_lng: float,
 *     rep_bbox_max_lng: float,
 *     rep_dedup_poly: list<array{0: float, 1: float}>,
 *     rep_dedup_cum: list<float>,
 *     observations: non-empty-list<ClimbObservation>
 * }
 */
class SegmentMatchingService
{
    public const int WAYPOINT_COUNT = 20;

    public const int PREVIEW_MAX_POINTS = 80;

    public const float BBOX_PADDING_DEG = 0.002;

    public const int CLIMB_DEDUP_SAMPLE_POINTS = 40;

    public const float CLIMB_DEDUP_CROSS_TRACK_MAX_METERS = 55.0;

    public const float CLIMB_DEDUP_MIN_BIDIRECTIONAL_OVERLAP = 0.78;

    public const float CLIMB_DEDUP_ENDPOINT_MIN_CAP_METERS = 360.0;

    public const float CLIMB_DEDUP_ENDPOINT_MAX_CAP_METERS = 500.0;

    public const float CLIMB_DEDUP_ENDPOINT_DISTANCE_RATIO = 0.12;

    public const float CLIMB_DEDUP_FORWARD_MIN_RATIO = 0.75;

    public const float CLIMB_SUBCLIMB_MIN_CONTAINMENT = 0.85;

    public const float CLIMB_SUBCLIMB_PARENT_DISTANCE_RATIO = 1.15;

    public const float CLIMB_VERIFY_MIN_GAIN_METERS = 21.0;

    public const float CLIMB_VERIFY_MIN_GRADE_PCT = 2.1;

    public const float PASS_ENDPOINT_MAX_METERS = 60.0;

    public const float PASS_DISTANCE_TOLERANCE = 0.20;

    public const float PASS_MEAN_WAYPOINT_MAX_METERS = 65.0;

    public const float PASS_MAX_WAYPOINT_MAX_METERS = 160.0;

    public const float PASS_COORD_PREFILTER_DEG = 0.0012;

    public const int CUSTOM_MIN_GNSS_POINTS = 5;

    public const float CUSTOM_MIN_DISTANCE_METERS = 100.0;

    public const int SYNC_BACKFILL_LIMIT = 25;

    public const float MOVING_SPEED_THRESHOLD_MPS = 0.5;

    public function __construct(
        private readonly ClimbDetector $climbDetector = new ClimbDetector,
        private readonly ?HealthSourceRegistry $healthSources = null,
        private readonly ?AppSettings $appSettings = null,
    ) {}

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    private function appSettings(): AppSettings
    {
        return $this->appSettings ?? app(AppSettings::class);
    }

    /**
     * Scan a single activity's stream for auto-detected climbs and passes over all overlapping segments.
     * Idempotent: detections are stored once per activity, efforts for passes that no longer match are
     * removed, and `segments_scanned_at` is always stamped so activities are never re-scanned infinitely.
     * Newly created climbs are backfilled against earlier activities on the queue.
     *
     * @return ActivityScanSummary
     */
    public function scanActivity(Activity $activity, ?ActivityStream $stream = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $resolvedStream = $activity->is_indoor ? null : ($stream ?? $activity->stream()->first());
        $rawSeries = $resolvedStream !== null && is_array($resolvedStream->series) ? $resolvedStream->series : [];
        $points = $rawSeries !== [] ? $this->extractGnssPoints($rawSeries) : [];

        if (count($points) < self::CUSTOM_MIN_GNSS_POINTS) {
            $this->refreshDetectionCounts($this->syncActivityDetections($activity, []));
            $this->refreshRankingsFor($this->pruneUnevaluatedEfforts($activity, []));
            $activity->update(['segments_scanned_at' => $now]);

            return ['efforts_recorded' => 0, 'climbs_created' => 0];
        }

        $climbsCreated = 0;
        /** @var list<int> $newClimbIds */
        $newClimbIds = [];
        /** @var array<int, true> $detectedSegmentIds */
        $detectedSegmentIds = [];
        $detectedClimbs = $this->climbDetector->detect($activity, $rawSeries);

        foreach ($detectedClimbs as $climb) {
            $slicePoints = $this->sliceGnssPointsByOrigIndex($points, $climb['start_index'], $climb['end_index']);

            if (count($slicePoints) < self::CUSTOM_MIN_GNSS_POINTS) {
                continue;
            }

            $coords = $this->pointsToLatLng($slicePoints);
            $cumulative = $this->computeCumulativeDistances($coords);
            $totalPolyDist = $cumulative[count($cumulative) - 1];

            if ($totalPolyDist <= 0.0) {
                continue;
            }

            $waypoints = $this->resampleWaypoints($coords, $cumulative, self::WAYPOINT_COUNT);
            $dedupPoly = $this->buildDedupPolyline($coords, $cumulative);
            [$minLat, $maxLat, $minLng, $maxLng] = $this->computeBoundingBox($coords);
            $start = $coords[0];
            $end = $coords[count($coords) - 1];

            $existingClimb = $this->findMatchingClimbSegment(
                sportCategory: $activity->sport_category,
                distanceMeters: $climb['distance_meters'],
                startLat: $start[0],
                startLng: $start[1],
                endLat: $end[0],
                endLng: $end[1],
                bboxMinLat: $minLat,
                bboxMaxLat: $maxLat,
                bboxMinLng: $minLng,
                bboxMaxLng: $maxLng,
                dedupPoly: $dedupPoly['points'],
                dedupCumDist: $dedupPoly['cum_dist'],
            );

            if ($existingClimb !== null) {
                $detectedSegmentIds[$existingClimb->id] = true;

                continue;
            }

            $previewPoints = $this->simplifyForPreview($coords, self::PREVIEW_MAX_POINTS);
            $encodedPreview = HeatmapService::encodePolyline($previewPoints);

            $created = Segment::query()->create([
                'name' => $this->buildInitialClimbName($activity, $climb['distance_meters'], $climb['avg_grade_pct']),
                'name_override' => null,
                'place_label' => null,
                'kind' => Segment::KIND_CLIMB,
                'sport_category' => $activity->sport_category,
                'source_activity_id' => $activity->id,
                'climb_category' => $climb['climb_category'],
                'distance_meters' => $climb['distance_meters'],
                'elevation_gain' => $climb['elevation_gain'],
                'elevation_loss' => $climb['elevation_loss'],
                'avg_grade_pct' => $climb['avg_grade_pct'],
                'max_grade_pct' => $climb['max_grade_pct'],
                'fiets_score' => $climb['fiets_score'],
                'start_lat' => $start[0],
                'start_lng' => $start[1],
                'end_lat' => $end[0],
                'end_lng' => $end[1],
                'bbox_min_lat' => $minLat,
                'bbox_max_lat' => $maxLat,
                'bbox_min_lng' => $minLng,
                'bbox_max_lng' => $maxLng,
                'waypoints' => $waypoints,
                'encoded_polyline' => $encodedPreview,
                'is_starred' => false,
                'effort_count' => 0,
                'detection_count' => 0,
                'is_sub_climb' => false,
                'is_climb_verified' => true,
                'best_elapsed_seconds' => null,
                'best_effort_id' => null,
                'last_effort_at' => null,
                'geocoded_at' => null,
            ]);

            $detectedSegmentIds[$created->id] = true;
            $newClimbIds[] = $created->id;
            $climbsCreated++;
        }

        $detectionChangedIds = $this->syncActivityDetections($activity, array_keys($detectedSegmentIds));
        $this->refreshDetectionCounts($detectionChangedIds);

        $activityCoords = $this->pointsToLatLng($points);
        [$actMinLat, $actMaxLat, $actMinLng, $actMaxLng] = $this->computeBoundingBox($activityCoords);

        $candidates = Segment::query()
            ->where('sport_category', $activity->sport_category)
            ->where('bbox_max_lat', '>=', $actMinLat - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lat', '<=', $actMaxLat + self::BBOX_PADDING_DEG)
            ->where('bbox_max_lng', '>=', $actMinLng - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lng', '<=', $actMaxLng + self::BBOX_PADDING_DEG)
            ->orderBy('id')
            ->get();

        $weightKg = $this->resolveAthleteWeightKg($activity);
        $effortsRecorded = 0;
        /** @var array<int, true> $touchedSegmentIds */
        $touchedSegmentIds = [];
        /** @var list<int> $evaluatedSegmentIds */
        $evaluatedSegmentIds = [];

        foreach ($candidates as $segment) {
            $evaluatedSegmentIds[] = $segment->id;
            $passes = $this->findPassesInStream($segment, $points);
            $result = $this->persistPasses($segment, $activity, $points, $passes, $weightKg);
            $effortsRecorded += $result['recorded'];

            if ($result['changed']) {
                $touchedSegmentIds[$segment->id] = true;
            }
        }

        foreach ($this->pruneUnevaluatedEfforts($activity, $evaluatedSegmentIds) as $prunedSegmentId) {
            $touchedSegmentIds[$prunedSegmentId] = true;
        }

        $this->refreshRankingsFor(array_keys($touchedSegmentIds));

        if ($detectionChangedIds !== [] || $touchedSegmentIds !== []) {
            RefreshSubClimbFlagsJob::dispatch($activity->sport_category);
        }

        foreach ($newClimbIds as $newClimbId) {
            MatchSegmentBackfillJob::dispatch($newClimbId, excludeActivityId: $activity->id);
        }

        if ($touchedSegmentIds !== []) {
            $refreshedSegments = Segment::query()
                ->whereIn('id', array_keys($touchedSegmentIds))
                ->get();

            foreach ($refreshedSegments as $refreshedSegment) {
                $this->dispatchGeocodingIfEnabled($refreshedSegment);
            }
        }

        $activity->update(['segments_scanned_at' => $now]);

        return [
            'efforts_recorded' => $effortsRecorded,
            'climbs_created' => $climbsCreated,
        ];
    }

    /**
     * Create a user-defined custom segment from a slice of an activity's GNSS stream,
     * immediately record the source effort, and match historical activities in the same sport.
     *
     * @throws InvalidArgumentException
     */
    public function createCustomSegment(
        Activity $activity,
        int $startIndex,
        int $endIndex,
        ?string $name = null,
    ): Segment {
        if ($activity->is_indoor) {
            throw new InvalidArgumentException('Custom segments can only be created from outdoor activities with GPS data.');
        }

        $stream = $activity->relationLoaded('stream')
            ? $activity->stream
            : $activity->stream()->first();

        if ($stream === null || ! is_array($stream->series) || $stream->series === []) {
            throw new InvalidArgumentException('This activity does not have a telemetry stream.');
        }

        $seriesCount = count($stream->series);

        if ($startIndex < 0 || $endIndex <= $startIndex || $endIndex >= $seriesCount) {
            throw new InvalidArgumentException('Selected start and end indices are out of bounds for this activity stream.');
        }

        $allPoints = $this->extractGnssPoints($stream->series);
        $slicePoints = $this->sliceGnssPointsByOrigIndex($allPoints, $startIndex, $endIndex);

        if (count($slicePoints) < self::CUSTOM_MIN_GNSS_POINTS) {
            throw new InvalidArgumentException('Selected range must contain at least '.self::CUSTOM_MIN_GNSS_POINTS.' valid GPS points.');
        }

        $coords = $this->pointsToLatLng($slicePoints);
        $cumulative = $this->computeCumulativeDistances($coords);
        $polyDistance = $cumulative[count($cumulative) - 1];
        $streamDistance = $slicePoints[count($slicePoints) - 1]['distance'] - $slicePoints[0]['distance'];
        $distanceMeters = round($streamDistance > 0.0 ? $streamDistance : $polyDistance, 1);

        if ($distanceMeters < self::CUSTOM_MIN_DISTANCE_METERS || $polyDistance <= 0.0) {
            throw new InvalidArgumentException('Custom segment must be at least '.(int) self::CUSTOM_MIN_DISTANCE_METERS.' meters long.');
        }

        $waypoints = $this->resampleWaypoints($coords, $cumulative, self::WAYPOINT_COUNT);
        $previewPoints = $this->simplifyForPreview($coords, self::PREVIEW_MAX_POINTS);
        $encodedPreview = HeatmapService::encodePolyline($previewPoints);
        [$minLat, $maxLat, $minLng, $maxLng] = $this->computeBoundingBox($coords);

        $elevationMetrics = $this->computeSliceElevationMetrics($slicePoints, $distanceMeters);

        $trimmedName = is_string($name) ? trim($name) : '';
        $hasCustomName = $trimmedName !== '';
        $fallbackName = $this->buildInitialCustomName($activity, $distanceMeters);

        $segment = Segment::query()->create([
            'name' => $hasCustomName ? mb_substr($trimmedName, 0, 160) : $fallbackName,
            'name_override' => $hasCustomName ? mb_substr($trimmedName, 0, 160) : null,
            'place_label' => null,
            'kind' => Segment::KIND_CUSTOM,
            'sport_category' => $activity->sport_category,
            'source_activity_id' => $activity->id,
            'climb_category' => $elevationMetrics['climb_category'],
            'distance_meters' => $distanceMeters,
            'elevation_gain' => $elevationMetrics['elevation_gain'],
            'elevation_loss' => $elevationMetrics['elevation_loss'],
            'avg_grade_pct' => $elevationMetrics['avg_grade_pct'],
            'max_grade_pct' => $elevationMetrics['max_grade_pct'],
            'fiets_score' => $elevationMetrics['fiets_score'],
            'start_lat' => $coords[0][0],
            'start_lng' => $coords[0][1],
            'end_lat' => $coords[count($coords) - 1][0],
            'end_lng' => $coords[count($coords) - 1][1],
            'bbox_min_lat' => $minLat,
            'bbox_max_lat' => $maxLat,
            'bbox_min_lng' => $minLng,
            'bbox_max_lng' => $maxLng,
            'waypoints' => $waypoints,
            'encoded_polyline' => $encodedPreview,
            'is_starred' => false,
            'effort_count' => 0,
            'detection_count' => 0,
            'is_sub_climb' => false,
            'is_climb_verified' => true,
            'best_elapsed_seconds' => null,
            'best_effort_id' => null,
            'last_effort_at' => null,
            'geocoded_at' => null,
        ]);

        // Immediately record the source activity effort for the exact user-selected slice.
        $weightKg = $this->resolveAthleteWeightKg($activity);
        $effortData = $this->computeEffortMetrics($activity, $slicePoints, 0, count($slicePoints) - 1, $weightKg);

        SegmentEffort::query()->updateOrCreate(
            [
                'segment_id' => $segment->id,
                'activity_id' => $activity->id,
                'start_index' => $effortData['start_index'],
            ],
            $effortData,
        );

        $segment = $this->refreshSegmentRankings($segment, dispatchGeocode: false);
        $this->dispatchGeocodingIfEnabled($segment);

        $historicalCandidateCount = $this->historicalCandidateQuery($segment, excludeActivityId: $activity->id)->count();

        if ($historicalCandidateCount > 0) {
            if ($historicalCandidateCount <= self::SYNC_BACKFILL_LIMIT) {
                $this->matchSegmentAgainstHistory($segment, excludeActivityId: $activity->id);
            } else {
                MatchSegmentBackfillJob::dispatch($segment->id, excludeActivityId: $activity->id);
            }
        }

        return $segment->fresh() ?? $segment;
    }

    /**
     * Scan historical activities in the segment's sport_category to record all matching passes, remove efforts
     * whose passes no longer match, refresh rankings, and queue sub-climb flag / geocoding follow-ups.
     * `$scannedBefore` limits the backfill to activities already scanned before a bulk scan run started.
     */
    public function matchSegmentAgainstHistory(
        Segment $segment,
        int $chunkSize = 100,
        ?int $excludeActivityId = null,
        ?CarbonImmutable $scannedBefore = null,
    ): int {
        $chunkSize = max(1, $chunkSize);
        $query = $this->historicalCandidateQuery($segment, $excludeActivityId);

        if ($scannedBefore !== null) {
            $query->where('segments_scanned_at', '<', $scannedBefore);
        }

        $ids = $query
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        /** @var array<int, true> $activityIdsWithEfforts */
        $activityIdsWithEfforts = array_fill_keys(
            SegmentEffort::query()
                ->where('segment_id', $segment->id)
                ->distinct()
                ->pluck('activity_id')
                ->all(),
            true,
        );

        $effortsRecorded = 0;
        $changed = false;

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $stream = $activity->stream;
                $points = $stream !== null && is_array($stream->series) && $stream->series !== []
                    ? $this->extractGnssPoints($stream->series)
                    : [];
                $passes = [];

                if (count($points) >= self::CUSTOM_MIN_GNSS_POINTS) {
                    $coords = $this->pointsToLatLng($points);
                    [$actMinLat, $actMaxLat, $actMinLng, $actMaxLng] = $this->computeBoundingBox($coords);

                    $overlaps = ! (
                        $segment->bbox_max_lat < $actMinLat - self::BBOX_PADDING_DEG
                        || $segment->bbox_min_lat > $actMaxLat + self::BBOX_PADDING_DEG
                        || $segment->bbox_max_lng < $actMinLng - self::BBOX_PADDING_DEG
                        || $segment->bbox_min_lng > $actMaxLng + self::BBOX_PADDING_DEG
                    );

                    if ($overlaps) {
                        $passes = $this->findPassesInStream($segment, $points);
                    }
                }

                if ($passes === [] && ! isset($activityIdsWithEfforts[$activity->id])) {
                    continue;
                }

                $result = $this->persistPasses(
                    $segment,
                    $activity,
                    $points,
                    $passes,
                    $passes !== [] ? $this->resolveAthleteWeightKg($activity) : null,
                );
                $effortsRecorded += $result['recorded'];
                $changed = $changed || $result['changed'];
            }
        }

        $this->refreshSegmentRankings($segment);

        if ($changed && $segment->kind === Segment::KIND_CLIMB) {
            RefreshSubClimbFlagsJob::dispatch($segment->sport_category);
        }

        return $effortsRecorded;
    }

    /**
     * Remove all segment efforts and climb detections belonging to an activity and recompute affected segment stats.
     */
    public function removeActivity(Activity $activity): void
    {
        /** @var list<int> $effortSegmentIds */
        $effortSegmentIds = array_values(array_unique(array_map(
            'intval',
            SegmentEffort::query()
                ->where('activity_id', $activity->id)
                ->pluck('segment_id')
                ->all(),
        )));

        /** @var list<int> $detectedSegmentIds */
        $detectedSegmentIds = array_values(array_unique(array_map(
            'intval',
            $activity->detectedSegments()->withTrashed()->pluck('segments.id')->all(),
        )));

        if ($effortSegmentIds === [] && $detectedSegmentIds === []) {
            return;
        }

        DB::transaction(function () use ($activity, $effortSegmentIds, $detectedSegmentIds): void {
            if ($effortSegmentIds !== []) {
                SegmentEffort::query()
                    ->where('activity_id', $activity->id)
                    ->delete();

                $this->refreshRankingsFor($effortSegmentIds);
            }

            if ($detectedSegmentIds !== []) {
                $activity->detectedSegments()->detach();
                $this->refreshDetectionCounts($detectedSegmentIds);
            }

            $allAffectedIds = array_values(array_unique([...$effortSegmentIds, ...$detectedSegmentIds]));

            $hasAffectedClimb = Segment::query()
                ->climbs()
                ->whereIn('id', $allAffectedIds)
                ->exists();

            if ($hasAffectedClimb) {
                $this->refreshSubClimbFlagsForSport($activity->sport_category);
            }
        });
    }

    /**
     * Recompute rankings (1..N by elapsed_seconds ASC), PR flag, climb verification, and aggregate stats on a Segment.
     */
    public function refreshSegmentRankings(Segment $segment, bool $dispatchGeocode = true): Segment
    {
        $efforts = SegmentEffort::query()
            ->where('segment_id', $segment->id)
            ->orderBy('elapsed_seconds')
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->get();

        if ($efforts->isEmpty()) {
            $segment->update([
                'effort_count' => 0,
                'is_climb_verified' => true,
                'best_elapsed_seconds' => null,
                'best_effort_id' => null,
                'last_effort_at' => null,
            ]);

            return $segment->fresh() ?? $segment;
        }

        $rank = 0;
        $bestEffort = null;
        $lastEffortAt = null;
        /** @var list<float> $effortGains */
        $effortGains = [];
        /** @var list<float> $effortGrades */
        $effortGrades = [];

        foreach ($efforts as $effort) {
            $rank++;
            $isPr = $rank === 1;

            if ($isPr) {
                $bestEffort = $effort;
            }

            if ($lastEffortAt === null || $effort->started_at_local->greaterThan($lastEffortAt)) {
                $lastEffortAt = $effort->started_at_local;
            }

            if ($effort->elevation_gain !== null) {
                $effortGains[] = $effort->elevation_gain;
            }

            if ($effort->avg_grade_pct !== null) {
                $effortGrades[] = $effort->avg_grade_pct;
            }

            if ($effort->rank !== $rank || $effort->is_pr !== $isPr) {
                $effort->update([
                    'rank' => $rank,
                    'is_pr' => $isPr,
                ]);
            }
        }

        $isClimbVerified = true;

        if (
            $segment->kind === Segment::KIND_CLIMB
            && count($effortGains) >= 2
            && count($effortGrades) >= 2
        ) {
            $medianGain = $this->medianFloat($effortGains);
            $medianGrade = $this->medianFloat($effortGrades);
            $isClimbVerified = $medianGain >= self::CLIMB_VERIFY_MIN_GAIN_METERS
                && $medianGrade >= self::CLIMB_VERIFY_MIN_GRADE_PCT;
        }

        $segment->update([
            'effort_count' => $efforts->count(),
            'is_climb_verified' => $isClimbVerified,
            'best_elapsed_seconds' => $bestEffort?->elapsed_seconds,
            'best_effort_id' => $bestEffort?->id,
            'last_effort_at' => $lastEffortAt,
        ]);

        $fresh = $segment->fresh() ?? $segment;

        if ($dispatchGeocode) {
            $this->dispatchGeocodingIfEnabled($fresh);
        }

        return $fresh;
    }

    /**
     * Count outdoor streamed activities that have not yet been scanned for climbs & segments.
     */
    public function unscannedCount(): int
    {
        return Activity::query()
            ->where('is_indoor', false)
            ->whereNull('segments_scanned_at')
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.series')
                    ->where('activity_streams.series', '!=', '[]')
                    ->whereRaw('LENGTH(activity_streams.series) >= 50');
            })
            ->count();
    }

    /**
     * Scan all outdoor streamed activities that have not yet been scanned for climbs and segments
     * using a two-pass consensus pipeline:
     *   Pass 1: Detect & cluster climbs across all unscanned activities with directional polyline dedup,
     *           selecting median-distance canonical geometry for newly discovered climbs.
     *   Pass 2: Match all unscanned activities against all candidate segments so every climb receives
     *           its complete effort history, verify climb telemetry, flag nested sub-climbs, and queue
     *           geocoding only for segments that satisfy the visibility threshold.
     *
     * @return SegmentScanRunSummary
     */
    public function scanUnscanned(int $chunkSize = 50): array
    {
        $chunkSize = max(1, $chunkSize);
        $ids = Activity::query()
            ->where('is_indoor', false)
            ->whereNull('segments_scanned_at')
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.series')
                    ->where('activity_streams.series', '!=', '[]')
                    ->whereRaw('LENGTH(activity_streams.series) >= 50');
            })
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [
                'scanned' => 0,
                'efforts_recorded' => 0,
                'climbs_created' => 0,
                'segments_total' => Segment::query()->count(),
            ];
        }

        /**
         * Preload existing climb segments into an in-memory spatial index by sport_category.
         *
         * @var array<string, list<array{
         *     segment: Segment,
         *     distance_meters: float,
         *     start_lat: float,
         *     start_lng: float,
         *     end_lat: float,
         *     end_lng: float,
         *     bbox_min_lat: float,
         *     bbox_max_lat: float,
         *     bbox_min_lng: float,
         *     bbox_max_lng: float,
         *     dedup_poly: list<array{0: float, 1: float}>,
         *     dedup_cum: list<float>
         * }>> $existingBySport
         */
        $existingBySport = [];

        // Dismissed (soft-deleted) climbs still take part in dedup so they are never re-created.
        foreach (Segment::withTrashed()->climbs()->orderBy('id')->get() as $existingClimb) {
            $dedup = $this->segmentDedupPolyline($existingClimb);

            $existingBySport[$existingClimb->sport_category][] = [
                'segment' => $existingClimb,
                'distance_meters' => $existingClimb->distance_meters,
                'start_lat' => $existingClimb->start_lat,
                'start_lng' => $existingClimb->start_lng,
                'end_lat' => $existingClimb->end_lat,
                'end_lng' => $existingClimb->end_lng,
                'bbox_min_lat' => $existingClimb->bbox_min_lat,
                'bbox_max_lat' => $existingClimb->bbox_max_lat,
                'bbox_min_lng' => $existingClimb->bbox_min_lng,
                'bbox_max_lng' => $existingClimb->bbox_max_lng,
                'dedup_poly' => $dedup['points'],
                'dedup_cum' => $dedup['cum_dist'],
            ];
        }

        /**
         * Newly discovered climb clusters accumulated during Pass 1 before choosing consensus geometry.
         *
         * @var array<string, list<ClimbCluster>> $newClustersBySport
         */
        $newClustersBySport = [];
        /** @var array<string, true> $sportsTouched */
        $sportsTouched = [];
        /** @var array<int, array<int, true>> $activityDetections */
        $activityDetections = [];
        $hasPreviouslyScannedActivities = Activity::query()->whereNotNull('segments_scanned_at')->exists();

        // Pass 1: Detect and cluster climbs across all unscanned activities.
        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $stream = $activity->stream;

                if ($stream === null || ! is_array($stream->series) || $stream->series === []) {
                    continue;
                }

                $rawSeries = $stream->series;
                $points = $this->extractGnssPoints($rawSeries);

                if (count($points) < self::CUSTOM_MIN_GNSS_POINTS) {
                    continue;
                }

                $sport = $activity->sport_category;
                $sportsTouched[$sport] = true;
                $activityDetections[$activity->id] = [];
                $detectedClimbs = $this->climbDetector->detect($activity, $rawSeries);

                if ($detectedClimbs === []) {
                    continue;
                }

                foreach ($detectedClimbs as $climb) {
                    $slicePoints = $this->sliceGnssPointsByOrigIndex($points, $climb['start_index'], $climb['end_index']);

                    if (count($slicePoints) < self::CUSTOM_MIN_GNSS_POINTS) {
                        continue;
                    }

                    $coords = $this->pointsToLatLng($slicePoints);
                    $cumulative = $this->computeCumulativeDistances($coords);
                    $totalPolyDist = $cumulative[count($cumulative) - 1];

                    if ($totalPolyDist <= 0.0) {
                        continue;
                    }

                    $dedup = $this->buildDedupPolyline($coords, $cumulative);
                    [$minLat, $maxLat, $minLng, $maxLng] = $this->computeBoundingBox($coords);
                    $start = $coords[0];
                    $end = $coords[count($coords) - 1];

                    // Check existing persisted climbs first.
                    $bestExistingIdx = null;
                    $bestExistingScore = -1.0;

                    foreach ($existingBySport[$sport] ?? [] as $idx => $existingEntry) {
                        if (
                            $existingEntry['bbox_max_lat'] < $minLat - self::BBOX_PADDING_DEG
                            || $existingEntry['bbox_min_lat'] > $maxLat + self::BBOX_PADDING_DEG
                            || $existingEntry['bbox_max_lng'] < $minLng - self::BBOX_PADDING_DEG
                            || $existingEntry['bbox_min_lng'] > $maxLng + self::BBOX_PADDING_DEG
                        ) {
                            continue;
                        }

                        $score = $this->climbOverlapScore(
                            distanceA: $climb['distance_meters'],
                            startLatA: $start[0],
                            startLngA: $start[1],
                            endLatA: $end[0],
                            endLngA: $end[1],
                            polyA: $dedup['points'],
                            cumA: $dedup['cum_dist'],
                            distanceB: $existingEntry['distance_meters'],
                            startLatB: $existingEntry['start_lat'],
                            startLngB: $existingEntry['start_lng'],
                            endLatB: $existingEntry['end_lat'],
                            endLngB: $existingEntry['end_lng'],
                            polyB: $existingEntry['dedup_poly'],
                            cumB: $existingEntry['dedup_cum'],
                        );

                        if ($score !== null && $score > $bestExistingScore) {
                            $bestExistingScore = $score;
                            $bestExistingIdx = $idx;
                        }
                    }

                    if ($bestExistingIdx !== null) {
                        $activityDetections[$activity->id][$existingBySport[$sport][$bestExistingIdx]['segment']->id] = true;

                        continue;
                    }

                    // Check newly discovered in-batch clusters next.
                    $waypoints = $this->resampleWaypoints($coords, $cumulative, self::WAYPOINT_COUNT);
                    $previewPoints = $this->simplifyForPreview($coords, self::PREVIEW_MAX_POINTS);
                    $encodedPreview = HeatmapService::encodePolyline($previewPoints);

                    $observation = [
                        'activity_id' => $activity->id,
                        'activity_location' => $activity->location_name,
                        'climb' => [
                            'distance_meters' => $climb['distance_meters'],
                            'elevation_gain' => $climb['elevation_gain'],
                            'elevation_loss' => $climb['elevation_loss'],
                            'avg_grade_pct' => $climb['avg_grade_pct'],
                            'max_grade_pct' => $climb['max_grade_pct'],
                            'fiets_score' => $climb['fiets_score'],
                            'climb_category' => $climb['climb_category'],
                        ],
                        'start_lat' => $start[0],
                        'start_lng' => $start[1],
                        'end_lat' => $end[0],
                        'end_lng' => $end[1],
                        'bbox_min_lat' => $minLat,
                        'bbox_max_lat' => $maxLat,
                        'bbox_min_lng' => $minLng,
                        'bbox_max_lng' => $maxLng,
                        'waypoints' => $waypoints,
                        'encoded_polyline' => $encodedPreview,
                    ];

                    $bestNewIdx = null;
                    $bestNewScore = -1.0;

                    foreach ($newClustersBySport[$sport] ?? [] as $idx => $cluster) {
                        if (
                            $cluster['rep_bbox_max_lat'] < $minLat - self::BBOX_PADDING_DEG
                            || $cluster['rep_bbox_min_lat'] > $maxLat + self::BBOX_PADDING_DEG
                            || $cluster['rep_bbox_max_lng'] < $minLng - self::BBOX_PADDING_DEG
                            || $cluster['rep_bbox_min_lng'] > $maxLng + self::BBOX_PADDING_DEG
                        ) {
                            continue;
                        }

                        $score = $this->climbOverlapScore(
                            distanceA: $climb['distance_meters'],
                            startLatA: $start[0],
                            startLngA: $start[1],
                            endLatA: $end[0],
                            endLngA: $end[1],
                            polyA: $dedup['points'],
                            cumA: $dedup['cum_dist'],
                            distanceB: $cluster['rep_distance'],
                            startLatB: $cluster['rep_start_lat'],
                            startLngB: $cluster['rep_start_lng'],
                            endLatB: $cluster['rep_end_lat'],
                            endLngB: $cluster['rep_end_lng'],
                            polyB: $cluster['rep_dedup_poly'],
                            cumB: $cluster['rep_dedup_cum'],
                        );

                        if ($score !== null && $score > $bestNewScore) {
                            $bestNewScore = $score;
                            $bestNewIdx = $idx;
                        }
                    }

                    if ($bestNewIdx !== null) {
                        $matchedCluster = $newClustersBySport[$sport][$bestNewIdx];
                        $matchedCluster['observations'][] = $observation;
                        $newClustersBySport[$sport][$bestNewIdx] = $matchedCluster;

                        continue;
                    }

                    $newClustersBySport[$sport][] = [
                        'rep_distance' => $climb['distance_meters'],
                        'rep_start_lat' => $start[0],
                        'rep_start_lng' => $start[1],
                        'rep_end_lat' => $end[0],
                        'rep_end_lng' => $end[1],
                        'rep_bbox_min_lat' => $minLat,
                        'rep_bbox_max_lat' => $maxLat,
                        'rep_bbox_min_lng' => $minLng,
                        'rep_bbox_max_lng' => $maxLng,
                        'rep_dedup_poly' => $dedup['points'],
                        'rep_dedup_cum' => $dedup['cum_dist'],
                        'observations' => [$observation],
                    ];
                }
            }
        }

        // Consolidate newly discovered clusters after medoid selection so seed-order variance
        // never leaves two overlapping canonical climbs in the same sport.
        foreach ($newClustersBySport as $sport => $clusters) {
            if (count($clusters) <= 1) {
                continue;
            }

            $newClustersBySport[$sport] = $this->consolidateNewClusters($clusters);
        }

        // Persist newly discovered climb clusters using medoid-endpoint consensus geometry.
        $climbsCreated = 0;
        /** @var list<int> $newClimbIds */
        $newClimbIds = [];

        foreach ($newClustersBySport as $sport => $clusters) {
            foreach ($clusters as $cluster) {
                $observations = $cluster['observations'];
                $canonical = $this->selectConsensusObservation($observations);
                $climb = $canonical['climb'];

                $created = Segment::query()->create([
                    'name' => $this->buildInitialClimbNameFromLocation(
                        $canonical['activity_location'],
                        $climb['distance_meters'],
                        $climb['avg_grade_pct'],
                    ),
                    'name_override' => null,
                    'place_label' => null,
                    'kind' => Segment::KIND_CLIMB,
                    'sport_category' => $sport,
                    'source_activity_id' => $canonical['activity_id'],
                    'climb_category' => $climb['climb_category'],
                    'distance_meters' => $climb['distance_meters'],
                    'elevation_gain' => $climb['elevation_gain'],
                    'elevation_loss' => $climb['elevation_loss'],
                    'avg_grade_pct' => $climb['avg_grade_pct'],
                    'max_grade_pct' => $climb['max_grade_pct'],
                    'fiets_score' => $climb['fiets_score'],
                    'start_lat' => $canonical['start_lat'],
                    'start_lng' => $canonical['start_lng'],
                    'end_lat' => $canonical['end_lat'],
                    'end_lng' => $canonical['end_lng'],
                    'bbox_min_lat' => $canonical['bbox_min_lat'],
                    'bbox_max_lat' => $canonical['bbox_max_lat'],
                    'bbox_min_lng' => $canonical['bbox_min_lng'],
                    'bbox_max_lng' => $canonical['bbox_max_lng'],
                    'waypoints' => $canonical['waypoints'],
                    'encoded_polyline' => $canonical['encoded_polyline'],
                    'is_starred' => false,
                    'effort_count' => 0,
                    'detection_count' => 0,
                    'is_sub_climb' => false,
                    'is_climb_verified' => true,
                    'best_elapsed_seconds' => null,
                    'best_effort_id' => null,
                    'last_effort_at' => null,
                    'geocoded_at' => null,
                ]);

                foreach ($observations as $observation) {
                    $activityDetections[$observation['activity_id']][$created->id] = true;
                }

                $newClimbIds[] = $created->id;
                $climbsCreated++;
            }
        }

        /** @var array<int, true> $detectionChangedIds */
        $detectionChangedIds = [];

        if ($activityDetections !== []) {
            $detectionActivities = Activity::query()
                ->whereIn('id', array_keys($activityDetections))
                ->get();

            foreach ($detectionActivities as $detectionActivity) {
                $segmentIds = array_keys($activityDetections[$detectionActivity->id]);

                foreach ($this->syncActivityDetections($detectionActivity, $segmentIds) as $changedId) {
                    $detectionChangedIds[$changedId] = true;
                }
            }
        }

        $this->refreshDetectionCounts(array_keys($detectionChangedIds));

        // Pass 2: Match all unscanned activities against all segments so every climb gets complete history.
        $allSegmentsBySport = Segment::query()->orderBy('id')->get()->groupBy('sport_category');
        $now = CarbonImmutable::now('UTC');
        $scanned = 0;
        $effortsRecorded = 0;
        /** @var array<int, true> $touchedSegmentIds */
        $touchedSegmentIds = [];

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            // Skip activities another scan stamped since this run started.
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->whereNull('segments_scanned_at')
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $scanned++;
                $stream = $activity->stream;

                if ($stream === null || ! is_array($stream->series) || $stream->series === []) {
                    $activity->update(['segments_scanned_at' => $now]);

                    continue;
                }

                $points = $this->extractGnssPoints($stream->series);

                if (count($points) < self::CUSTOM_MIN_GNSS_POINTS) {
                    $activity->update(['segments_scanned_at' => $now]);

                    continue;
                }

                $sportSegments = $allSegmentsBySport->get($activity->sport_category);
                /** @var list<int> $evaluatedSegmentIds */
                $evaluatedSegmentIds = [];

                if ($sportSegments !== null && $sportSegments->isNotEmpty()) {
                    $activityCoords = $this->pointsToLatLng($points);
                    [$actMinLat, $actMaxLat, $actMinLng, $actMaxLng] = $this->computeBoundingBox($activityCoords);
                    $weightKg = $this->resolveAthleteWeightKg($activity);

                    foreach ($sportSegments as $segment) {
                        if (
                            $segment->bbox_max_lat < $actMinLat - self::BBOX_PADDING_DEG
                            || $segment->bbox_min_lat > $actMaxLat + self::BBOX_PADDING_DEG
                            || $segment->bbox_max_lng < $actMinLng - self::BBOX_PADDING_DEG
                            || $segment->bbox_min_lng > $actMaxLng + self::BBOX_PADDING_DEG
                        ) {
                            continue;
                        }

                        $evaluatedSegmentIds[] = $segment->id;
                        $passes = $this->findPassesInStream($segment, $points);
                        $result = $this->persistPasses($segment, $activity, $points, $passes, $weightKg);
                        $effortsRecorded += $result['recorded'];

                        if ($result['changed']) {
                            $touchedSegmentIds[$segment->id] = true;
                            $sportsTouched[$segment->sport_category] = true;
                        }
                    }
                }

                foreach ($this->pruneUnevaluatedEfforts($activity, $evaluatedSegmentIds) as $prunedSegmentId) {
                    $touchedSegmentIds[$prunedSegmentId] = true;
                }

                $activity->update(['segments_scanned_at' => $now]);
            }
        }

        // Refresh rankings and climb verification on all touched segments.
        $touchedSegments = $touchedSegmentIds !== []
            ? Segment::query()->whereIn('id', array_keys($touchedSegmentIds))->orderBy('id')->get()
            : collect();

        foreach ($touchedSegments as $segment) {
            $sportsTouched[$segment->sport_category] = true;
            $this->refreshSegmentRankings($segment, dispatchGeocode: false);
        }

        // Update sub-climb flags once effort_count and is_climb_verified are final.
        foreach (array_keys($sportsTouched) as $sport) {
            RefreshSubClimbFlagsJob::dispatch($sport);
        }

        // Climbs discovered in this run still need efforts from activities scanned before it started.
        if ($hasPreviouslyScannedActivities) {
            foreach ($newClimbIds as $newClimbId) {
                MatchSegmentBackfillJob::dispatch($newClimbId, scannedBefore: $now);
            }
        }

        // Dispatch geocoding only for touched segments that now satisfy visibility rules.
        if ($touchedSegmentIds !== []) {
            $visibleReady = Segment::query()
                ->whereIn('id', array_keys($touchedSegmentIds))
                ->whereNull('geocoded_at')
                ->orderBy('id')
                ->get();

            foreach ($visibleReady as $segment) {
                $this->dispatchGeocodingIfEnabled($segment);
            }
        }

        return [
            'scanned' => $scanned,
            'efforts_recorded' => $effortsRecorded,
            'climbs_created' => $climbsCreated,
            'segments_total' => Segment::query()->count(),
        ];
    }

    /**
     * Rebuild all auto-detected climbs and segment efforts from scratch while preserving custom segments
     * and user-supplied `name_override` / `is_starred` flags on climbs.
     *
     * @return SegmentScanRunSummary
     */
    public function rebuildAll(int $chunkSize = 50): array
    {
        /** @var list<array{
         *     sport_category: string,
         *     distance_meters: float,
         *     start_lat: float,
         *     start_lng: float,
         *     end_lat: float,
         *     end_lng: float,
         *     dedup_poly: list<array{0: float, 1: float}>,
         *     dedup_cum: list<float>,
         *     name_override: ?string,
         *     is_starred: bool
         * }> $preservedClimbs
         */
        $preservedClimbs = [];

        $existingClimbs = Segment::query()
            ->climbs()
            ->where(function ($query): void {
                $query
                    ->where(function ($sub): void {
                        $sub
                            ->whereNotNull('name_override')
                            ->where('name_override', '!=', '');
                    })
                    ->orWhere('is_starred', true);
            })
            ->get();

        foreach ($existingClimbs as $climb) {
            $override = is_string($climb->name_override) && trim($climb->name_override) !== ''
                ? trim($climb->name_override)
                : null;
            $dedup = $this->segmentDedupPolyline($climb);

            $preservedClimbs[] = [
                'sport_category' => $climb->sport_category,
                'distance_meters' => $climb->distance_meters,
                'start_lat' => $climb->start_lat,
                'start_lng' => $climb->start_lng,
                'end_lat' => $climb->end_lat,
                'end_lng' => $climb->end_lng,
                'dedup_poly' => $dedup['points'],
                'dedup_cum' => $dedup['cum_dist'],
                'name_override' => $override,
                'is_starred' => $climb->is_starred,
            ];
        }

        DB::transaction(function (): void {
            SegmentEffort::query()->delete();
            // Dismissed (soft-deleted) climbs survive so the rebuild cannot resurrect them. Builder::forceDelete()
            // bypasses global scopes, so the trashed filter must be explicit.
            Segment::query()->climbs()->whereNull('deleted_at')->forceDelete();
            Segment::query()->custom()->update([
                'effort_count' => 0,
                'best_elapsed_seconds' => null,
                'best_effort_id' => null,
                'last_effort_at' => null,
            ]);
            Activity::query()->update(['segments_scanned_at' => null]);
        });

        $result = $this->scanUnscanned($chunkSize);

        if ($preservedClimbs !== []) {
            $this->restorePreservedClimbMetadata($preservedClimbs);
        }

        return $result;
    }

    /**
     * Find all non-overlapping passes over a segment inside an activity's GNSS points (supports hill repeats).
     *
     * @param  list<GnssStreamPoint>  $points
     * @return list<MatchedPass>
     */
    public function findPassesInStream(Segment $segment, array $points): array
    {
        $pointCount = count($points);

        if ($pointCount < self::CUSTOM_MIN_GNSS_POINTS) {
            return [];
        }

        $segmentWaypoints = $segment->waypoints;

        if (count($segmentWaypoints) !== self::WAYPOINT_COUNT) {
            return [];
        }

        $segDist = $segment->distance_meters;

        if ($segDist <= 0.0) {
            return [];
        }

        $minPassDist = $segDist * (1.0 - self::PASS_DISTANCE_TOLERANCE);
        $maxPassDist = $segDist * (1.0 + self::PASS_DISTANCE_TOLERANCE);
        $searchCeilingDist = $segDist * 1.25;

        $startLat = $segment->start_lat;
        $startLng = $segment->start_lng;
        $endLat = $segment->end_lat;
        $endLng = $segment->end_lng;

        $passes = [];
        $cursor = 0;

        while ($cursor < $pointCount - 1) {
            // Find next entry point within 60m of segment start.
            if (! $this->withinPrefilter($points[$cursor]['lat'], $points[$cursor]['lng'], $startLat, $startLng)) {
                $cursor++;

                continue;
            }

            $entryStartDist = RouteMatchingService::haversineMeters(
                $points[$cursor]['lat'],
                $points[$cursor]['lng'],
                $startLat,
                $startLng,
            );

            if ($entryStartDist > self::PASS_ENDPOINT_MAX_METERS) {
                $cursor++;

                continue;
            }

            // Walk the contiguous entry run and pick the closest point to segment start (preferring later point on tie).
            $runEnd = $cursor;
            $bestEntryIdx = $cursor;
            $bestEntryDist = $entryStartDist;

            while ($runEnd + 1 < $pointCount) {
                $nextIdx = $runEnd + 1;

                if (! $this->withinPrefilter($points[$nextIdx]['lat'], $points[$nextIdx]['lng'], $startLat, $startLng)) {
                    break;
                }

                $d = RouteMatchingService::haversineMeters(
                    $points[$nextIdx]['lat'],
                    $points[$nextIdx]['lng'],
                    $startLat,
                    $startLng,
                );

                if ($d > self::PASS_ENDPOINT_MAX_METERS) {
                    break;
                }

                if ($d <= $bestEntryDist) {
                    $bestEntryDist = $d;
                    $bestEntryIdx = $nextIdx;
                }

                $runEnd = $nextIdx;
            }

            // Look ahead for valid exit runs within 60m of segment end.
            $bestMatch = null;
            $j = $bestEntryIdx + 1;

            while ($j < $pointCount) {
                $passDist = $points[$j]['distance'] - $points[$bestEntryIdx]['distance'];

                if ($passDist > $searchCeilingDist) {
                    break;
                }

                if (
                    $passDist < $minPassDist
                    || ! $this->withinPrefilter($points[$j]['lat'], $points[$j]['lng'], $endLat, $endLng)
                ) {
                    $j++;

                    continue;
                }

                $exitDist = RouteMatchingService::haversineMeters(
                    $points[$j]['lat'],
                    $points[$j]['lng'],
                    $endLat,
                    $endLng,
                );

                if ($exitDist > self::PASS_ENDPOINT_MAX_METERS) {
                    $j++;

                    continue;
                }

                // Walk contiguous exit run and pick closest point to segment end within valid pass distance.
                $exitRunEnd = $j;
                $bestExitIdx = $j;
                $bestExitDist = $exitDist;

                while ($exitRunEnd + 1 < $pointCount) {
                    $nextExit = $exitRunEnd + 1;
                    $nextPassDist = $points[$nextExit]['distance'] - $points[$bestEntryIdx]['distance'];

                    if ($nextPassDist > $maxPassDist) {
                        break;
                    }

                    if (! $this->withinPrefilter($points[$nextExit]['lat'], $points[$nextExit]['lng'], $endLat, $endLng)) {
                        break;
                    }

                    $ed = RouteMatchingService::haversineMeters(
                        $points[$nextExit]['lat'],
                        $points[$nextExit]['lng'],
                        $endLat,
                        $endLng,
                    );

                    if ($ed > self::PASS_ENDPOINT_MAX_METERS) {
                        break;
                    }

                    if ($ed < $bestExitDist) {
                        $bestExitDist = $ed;
                        $bestExitIdx = $nextExit;
                    }

                    $exitRunEnd = $nextExit;
                }

                $finalPassDist = $points[$bestExitIdx]['distance'] - $points[$bestEntryIdx]['distance'];

                if ($finalPassDist >= $minPassDist && $finalPassDist <= $maxPassDist && ($bestExitIdx - $bestEntryIdx) >= 2) {
                    $sliceCoords = [];

                    for ($k = $bestEntryIdx; $k <= $bestExitIdx; $k++) {
                        $sliceCoords[] = [$points[$k]['lat'], $points[$k]['lng']];
                    }

                    $cumulative = $this->computeCumulativeDistances($sliceCoords);

                    if ($cumulative[count($cumulative) - 1] > 0.0) {
                        $passWaypoints = $this->resampleWaypoints($sliceCoords, $cumulative, self::WAYPOINT_COUNT);
                        [$meanDist, $maxDist] = $this->compareWaypoints($passWaypoints, $segmentWaypoints);

                        if (
                            $meanDist <= self::PASS_MEAN_WAYPOINT_MAX_METERS
                            && $maxDist <= self::PASS_MAX_WAYPOINT_MAX_METERS
                            && ($bestMatch === null || $meanDist < $bestMatch['mean_distance_meters'])
                        ) {
                            $bestMatch = [
                                'entry_idx' => $bestEntryIdx,
                                'exit_idx' => $bestExitIdx,
                                'mean_distance_meters' => $meanDist,
                            ];
                        }
                    }
                }

                $j = $exitRunEnd + 1;
            }

            if ($bestMatch !== null) {
                $passes[] = $bestMatch;
                $cursor = $bestMatch['exit_idx'] + 1;
            } else {
                $cursor = $runEnd + 1;
            }
        }

        return $passes;
    }

    /**
     * Compute cumulative Haversine distances along a list of [lat, lng] coordinates.
     *
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<float>
     */
    public function computeCumulativeDistances(array $points): array
    {
        $cumulative = [0.0];
        $running = 0.0;
        $count = count($points);

        for ($i = 1; $i < $count; $i++) {
            $running += RouteMatchingService::haversineMeters(
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
    public function resampleWaypoints(array $points, array $cumulativeDistances, int $count = self::WAYPOINT_COUNT): array
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
    public function simplifyForPreview(array $points, int $maxPoints = self::PREVIEW_MAX_POINTS): array
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
    public function computeBoundingBox(array $points): array
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
     * Extract normalized GNSS stream points retaining original stream indices and backfilling missing distance.
     *
     * @param  array<int|string, mixed>  $series
     * @return list<GnssStreamPoint>
     */
    public function extractGnssPoints(array $series): array
    {
        $points = [];
        $cumulativeFallback = 0.0;
        $prevLat = null;
        $prevLng = null;

        foreach ($series as $idx => $raw) {
            if (! is_array($raw) || ! is_int($idx)) {
                continue;
            }

            $rawLat = $raw['lat'] ?? null;
            $rawLng = $raw['lng'] ?? null;

            if (! is_numeric($rawLat) || ! is_numeric($rawLng)) {
                continue;
            }

            $lat = (float) $rawLat;
            $lng = (float) $rawLng;

            if (
                ! is_finite($lat)
                || ! is_finite($lng)
                || $lat < -90.0
                || $lat > 90.0
                || $lng < -180.0
                || $lng > 180.0
            ) {
                continue;
            }

            if ($prevLat !== null && $prevLng !== null) {
                $cumulativeFallback += RouteMatchingService::haversineMeters($prevLat, $prevLng, $lat, $lng);
            }

            $prevLat = $lat;
            $prevLng = $lng;

            $distance = isset($raw['distance']) && is_numeric($raw['distance'])
                ? (float) $raw['distance']
                : $cumulativeFallback;

            $time = isset($raw['time']) && is_numeric($raw['time'])
                ? (float) $raw['time']
                : (float) $idx;

            $points[] = [
                'orig_index' => $idx,
                'time' => $time,
                'distance' => $distance,
                'altitude' => isset($raw['altitude']) && is_numeric($raw['altitude']) ? (float) $raw['altitude'] : null,
                'speed' => isset($raw['speed']) && is_numeric($raw['speed']) ? (float) $raw['speed'] : null,
                'hr' => isset($raw['hr']) && is_numeric($raw['hr']) ? (int) round((float) $raw['hr']) : null,
                'power' => isset($raw['power']) && is_numeric($raw['power']) ? (float) $raw['power'] : null,
                'cadence' => isset($raw['cadence']) && is_numeric($raw['cadence']) ? (float) $raw['cadence'] : null,
                'lat' => round($lat, 5),
                'lng' => round($lng, 5),
            ];
        }

        return $points;
    }

    /**
     * Find the closest matching persisted climb segment (including dismissed climbs) using directional
     * distance-weighted polyline overlap.
     *
     * @param  list<array{0: float, 1: float}>  $dedupPoly
     * @param  list<float>  $dedupCumDist
     */
    private function findMatchingClimbSegment(
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
        array $dedupPoly,
        array $dedupCumDist,
    ): ?Segment {
        $candidates = Segment::withTrashed()
            ->climbs()
            ->where('sport_category', $sportCategory)
            ->where('bbox_max_lat', '>=', $bboxMinLat - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lat', '<=', $bboxMaxLat + self::BBOX_PADDING_DEG)
            ->where('bbox_max_lng', '>=', $bboxMinLng - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lng', '<=', $bboxMaxLng + self::BBOX_PADDING_DEG)
            ->orderBy('id')
            ->get();

        $bestMatch = null;
        $bestScore = -1.0;

        foreach ($candidates as $candidate) {
            $candDedup = $this->segmentDedupPolyline($candidate);

            $score = $this->climbOverlapScore(
                distanceA: $distanceMeters,
                startLatA: $startLat,
                startLngA: $startLng,
                endLatA: $endLat,
                endLngA: $endLng,
                polyA: $dedupPoly,
                cumA: $dedupCumDist,
                distanceB: $candidate->distance_meters,
                startLatB: $candidate->start_lat,
                startLngB: $candidate->start_lng,
                endLatB: $candidate->end_lat,
                endLngB: $candidate->end_lng,
                polyB: $candDedup['points'],
                cumB: $candDedup['cum_dist'],
            );

            if ($score !== null && $score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    /**
     * Score whether two climbs represent the same hill using proportional endpoint caps
     * and bidirectional directional polyline overlap. Returns null when distinct.
     *
     * @param  list<array{0: float, 1: float}>  $polyA
     * @param  list<float>  $cumA
     * @param  list<array{0: float, 1: float}>  $polyB
     * @param  list<float>  $cumB
     */
    private function climbOverlapScore(
        float $distanceA,
        float $startLatA,
        float $startLngA,
        float $endLatA,
        float $endLngA,
        array $polyA,
        array $cumA,
        float $distanceB,
        float $startLatB,
        float $startLngB,
        float $endLatB,
        float $endLngB,
        array $polyB,
        array $cumB,
    ): ?float {
        $maxDist = max($distanceA, $distanceB);

        if ($maxDist <= 0.0 || count($polyA) < 2 || count($polyB) < 2) {
            return null;
        }

        $endpointCap = min(
            self::CLIMB_DEDUP_ENDPOINT_MAX_CAP_METERS,
            max(
                self::CLIMB_DEDUP_ENDPOINT_MIN_CAP_METERS,
                $maxDist * self::CLIMB_DEDUP_ENDPOINT_DISTANCE_RATIO,
            ),
        );

        $startDist = RouteMatchingService::haversineMeters($startLatA, $startLngA, $startLatB, $startLngB);

        if ($startDist > $endpointCap) {
            return null;
        }

        $endDist = RouteMatchingService::haversineMeters($endLatA, $endLngA, $endLatB, $endLngB);

        if ($endDist > $endpointCap) {
            return null;
        }

        $overlap = $this->computeDirectionalPolylineOverlap($polyA, $cumA, $polyB, $cumB);

        if ($overlap['min'] < self::CLIMB_DEDUP_MIN_BIDIRECTIONAL_OVERLAP) {
            return null;
        }

        return $overlap['min'];
    }

    /**
     * Resample a polyline into evenly-spaced points by cumulative distance for directional overlap checks.
     *
     * @param  list<array{0: float, 1: float}>  $coords
     * @param  list<float>  $cumulativeDistances
     * @return array{points: list<array{0: float, 1: float}>, cum_dist: list<float>}
     */
    private function buildDedupPolyline(array $coords, array $cumulativeDistances): array
    {
        if (count($coords) < 2 || $cumulativeDistances[count($cumulativeDistances) - 1] <= 0.0) {
            return ['points' => $coords, 'cum_dist' => $cumulativeDistances];
        }

        $resampled = $this->resampleWaypoints($coords, $cumulativeDistances, self::CLIMB_DEDUP_SAMPLE_POINTS);

        return [
            'points' => $resampled,
            'cum_dist' => $this->computeCumulativeDistances($resampled),
        ];
    }

    /**
     * Build the evenly-spaced dedup polyline for a persisted Segment (preferring dense encoded_polyline over 20 waypoints).
     *
     * @return array{points: list<array{0: float, 1: float}>, cum_dist: list<float>}
     */
    private function segmentDedupPolyline(Segment $segment): array
    {
        $coords = [];

        if (is_string($segment->encoded_polyline) && $segment->encoded_polyline !== '') {
            $decoded = HeatmapService::decodePolyline($segment->encoded_polyline);

            if (count($decoded) >= 2) {
                $coords = $decoded;
            }
        }

        if (count($coords) < 2) {
            $coords = $segment->waypoints;
        }

        if (count($coords) < 2) {
            return ['points' => [], 'cum_dist' => []];
        }

        $cum = $this->computeCumulativeDistances($coords);

        return $this->buildDedupPolyline($coords, $cum);
    }

    /**
     * Compute directional point-to-segment polyline coverage in both directions.
     * Rejects opposite-direction passes (e.g. descent vs ascent on the same road).
     *
     * @param  list<array{0: float, 1: float}>  $polyA
     * @param  list<float>  $cumA
     * @param  list<array{0: float, 1: float}>  $polyB
     * @param  list<float>  $cumB
     * @return array{a_on_b: float, b_on_a: float, min: float, max: float}
     */
    public function computeDirectionalPolylineOverlap(
        array $polyA,
        array $cumA,
        array $polyB,
        array $cumB,
        float $toleranceMeters = self::CLIMB_DEDUP_CROSS_TRACK_MAX_METERS,
    ): array {
        $countA = count($polyA);
        $countB = count($polyB);

        if ($countA < 2 || $countB < 2) {
            return ['a_on_b' => 0.0, 'b_on_a' => 0.0, 'min' => 0.0, 'max' => 0.0];
        }

        $covA = $this->directionalCoverage($polyA, $polyB, $cumB, $toleranceMeters);

        if ($covA <= 0.0) {
            return ['a_on_b' => 0.0, 'b_on_a' => 0.0, 'min' => 0.0, 'max' => 0.0];
        }

        $covB = $this->directionalCoverage($polyB, $polyA, $cumA, $toleranceMeters);

        if ($covB <= 0.0) {
            return ['a_on_b' => 0.0, 'b_on_a' => 0.0, 'min' => 0.0, 'max' => 0.0];
        }

        return [
            'a_on_b' => $covA,
            'b_on_a' => $covB,
            'min' => min($covA, $covB),
            'max' => max($covA, $covB),
        ];
    }

    /**
     * Fraction of `$source` points lying within `$toleranceMeters` of a same-direction segment of `$target`.
     * Uses tangent dot-product alignment so tight MTB switchbacks match cleanly while opposite-direction
     * descents on the same road are rejected.
     *
     * @param  list<array{0: float, 1: float}>  $source
     * @param  list<array{0: float, 1: float}>  $target
     * @param  list<float>  $targetCumDist
     */
    private function directionalCoverage(
        array $source,
        array $target,
        array $targetCumDist,
        float $toleranceMeters,
    ): float {
        $sourceCount = count($source);
        $targetSegCount = count($target) - 1;

        if ($sourceCount < 2 || $targetSegCount < 1) {
            return 0.0;
        }

        $refLat = $source[0][0];
        $mPerDegLat = 111132.0;
        $mPerDegLng = 111320.0 * cos(deg2rad($refLat));

        /** @var list<float> $sx */
        $sx = [];
        /** @var list<float> $sy */
        $sy = [];

        foreach ($source as $pt) {
            $sx[] = $pt[1] * $mPerDegLng;
            $sy[] = $pt[0] * $mPerDegLat;
        }

        /** @var list<float> $tx */
        $tx = [];
        /** @var list<float> $ty */
        $ty = [];

        foreach ($target as $pt) {
            $tx[] = $pt[1] * $mPerDegLng;
            $ty[] = $pt[0] * $mPerDegLat;
        }

        $tolSq = $toleranceMeters * $toleranceMeters;
        $matchedCount = 0;

        for ($s = 0; $s < $sourceCount; $s++) {
            $px = $sx[$s];
            $py = $sy[$s];
            $prevIdx = max(0, $s - 1);
            $nextIdx = min($sourceCount - 1, $s + 1);
            $sdx = $sx[$nextIdx] - $sx[$prevIdx];
            $sdy = $sy[$nextIdx] - $sy[$prevIdx];
            $hasTangent = (($sdx * $sdx) + ($sdy * $sdy)) > 1e-6;

            $bestDistSq = INF;

            for ($i = 0; $i < $targetSegCount; $i++) {
                $ax = $tx[$i];
                $ay = $ty[$i];
                $dx = $tx[$i + 1] - $ax;
                $dy = $ty[$i + 1] - $ay;

                if ($hasTangent && (($sdx * $dx) + ($sdy * $dy)) <= 0.0) {
                    continue;
                }

                $lenSq = ($dx * $dx) + ($dy * $dy);
                $t = $lenSq > 0.0
                    ? ((($px - $ax) * $dx) + (($py - $ay) * $dy)) / $lenSq
                    : 0.0;

                if ($t < 0.0) {
                    $t = 0.0;
                } elseif ($t > 1.0) {
                    $t = 1.0;
                }

                $cx = $ax + ($t * $dx);
                $cy = $ay + ($t * $dy);
                $ex = $px - $cx;
                $ey = $py - $cy;
                $distSq = ($ex * $ex) + ($ey * $ey);

                if ($distSq < $bestDistSq) {
                    $bestDistSq = $distSq;
                }
            }

            if ($bestDistSq <= $tolSq) {
                $matchedCount++;
            }
        }

        if ($matchedCount < 2) {
            return 0.0;
        }

        return $matchedCount / $sourceCount;
    }

    /**
     * Pick the consensus observation in a climb cluster whose start/end endpoints are most central
     * (medoid) across all observations, breaking ties by proximity to median distance.
     *
     * @param  non-empty-list<ClimbObservation>  $observations
     * @return ClimbObservation
     */
    private function selectConsensusObservation(array $observations): array
    {
        $count = count($observations);

        if ($count === 1) {
            return $observations[0];
        }

        $distances = array_map(
            static fn (array $obs): float => $obs['climb']['distance_meters'],
            $observations,
        );
        $medianDist = $this->medianFloat($distances);

        // Downsample candidate pool to at most 40 evenly-spaced entries for large clusters.
        if ($count > 40) {
            $sample = [];
            $step = ($count - 1) / 39.0;

            for ($i = 0; $i < 40; $i++) {
                $sample[] = $observations[(int) round($i * $step)];
            }
        } else {
            $sample = $observations;
        }

        $sampleCount = count($sample);
        $bestIdx = 0;
        $bestScore = INF;

        for ($i = 0; $i < $sampleCount; $i++) {
            $endpointSum = 0.0;

            for ($j = 0; $j < $sampleCount; $j++) {
                if ($i === $j) {
                    continue;
                }

                $endpointSum += RouteMatchingService::haversineMeters(
                    $sample[$i]['start_lat'],
                    $sample[$i]['start_lng'],
                    $sample[$j]['start_lat'],
                    $sample[$j]['start_lng'],
                );
                $endpointSum += RouteMatchingService::haversineMeters(
                    $sample[$i]['end_lat'],
                    $sample[$i]['end_lng'],
                    $sample[$j]['end_lat'],
                    $sample[$j]['end_lng'],
                );
            }

            $avgEndpointDist = $endpointSum / max(1, $sampleCount - 1);
            $distPenalty = abs($sample[$i]['climb']['distance_meters'] - $medianDist) * 0.25;
            $score = $avgEndpointDist + $distPenalty;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestIdx = $i;
            }
        }

        return $sample[$bestIdx];
    }

    /**
     * Merge any in-batch climb clusters whose medoid canonical geometries overlap above threshold.
     *
     * @param  list<ClimbCluster>  $clusters
     * @return list<ClimbCluster>
     */
    private function consolidateNewClusters(array $clusters): array
    {
        // Sort larger clusters first so dominant canonical climbs absorb smaller seed-drifted variants.
        usort(
            $clusters,
            static fn (array $a, array $b): int => count($b['observations']) <=> count($a['observations']),
        );

        /** @var list<array{
         *     cluster: ClimbCluster,
         *     distance: float,
         *     start_lat: float,
         *     start_lng: float,
         *     end_lat: float,
         *     end_lng: float,
         *     bbox_min_lat: float,
         *     bbox_max_lat: float,
         *     bbox_min_lng: float,
         *     bbox_max_lng: float,
         *     dedup_poly: list<array{0: float, 1: float}>,
         *     dedup_cum: list<float>
         * }> $merged
         */
        $merged = [];

        foreach ($clusters as $cluster) {
            $canonical = $this->selectConsensusObservation($cluster['observations']);
            $decoded = HeatmapService::decodePolyline($canonical['encoded_polyline']);
            $polyCoords = count($decoded) >= 2 ? $decoded : $canonical['waypoints'];
            $cum = $this->computeCumulativeDistances($polyCoords);
            $dedup = $this->buildDedupPolyline($polyCoords, $cum);

            $bestTargetIdx = null;
            $bestScore = -1.0;

            foreach ($merged as $idx => $target) {
                if (
                    $target['bbox_max_lat'] < $canonical['bbox_min_lat'] - self::BBOX_PADDING_DEG
                    || $target['bbox_min_lat'] > $canonical['bbox_max_lat'] + self::BBOX_PADDING_DEG
                    || $target['bbox_max_lng'] < $canonical['bbox_min_lng'] - self::BBOX_PADDING_DEG
                    || $target['bbox_min_lng'] > $canonical['bbox_max_lng'] + self::BBOX_PADDING_DEG
                ) {
                    continue;
                }

                $score = $this->climbOverlapScore(
                    distanceA: $canonical['climb']['distance_meters'],
                    startLatA: $canonical['start_lat'],
                    startLngA: $canonical['start_lng'],
                    endLatA: $canonical['end_lat'],
                    endLngA: $canonical['end_lng'],
                    polyA: $dedup['points'],
                    cumA: $dedup['cum_dist'],
                    distanceB: $target['distance'],
                    startLatB: $target['start_lat'],
                    startLngB: $target['start_lng'],
                    endLatB: $target['end_lat'],
                    endLngB: $target['end_lng'],
                    polyB: $target['dedup_poly'],
                    cumB: $target['dedup_cum'],
                );

                if ($score !== null && $score > $bestScore) {
                    $bestScore = $score;
                    $bestTargetIdx = $idx;
                }
            }

            if ($bestTargetIdx !== null) {
                $target = $merged[$bestTargetIdx];
                $target['cluster']['observations'] = [
                    ...$target['cluster']['observations'],
                    ...$cluster['observations'],
                ];
                $merged[$bestTargetIdx] = $target;

                continue;
            }

            $merged[] = [
                'cluster' => $cluster,
                'distance' => $canonical['climb']['distance_meters'],
                'start_lat' => $canonical['start_lat'],
                'start_lng' => $canonical['start_lng'],
                'end_lat' => $canonical['end_lat'],
                'end_lng' => $canonical['end_lng'],
                'bbox_min_lat' => $canonical['bbox_min_lat'],
                'bbox_max_lat' => $canonical['bbox_max_lat'],
                'bbox_min_lng' => $canonical['bbox_min_lng'],
                'bbox_max_lng' => $canonical['bbox_max_lng'],
                'dedup_poly' => $dedup['points'],
                'dedup_cum' => $dedup['cum_dist'],
            ];
        }

        return array_map(
            static fn (array $entry): array => $entry['cluster'],
            $merged,
        );
    }

    /**
     * Recompute `is_sub_climb` for all climb segments within a sport_category.
     * A climb is flagged as a sub-climb when >= 85% of its polyline lies along a longer,
     * verified, and at least as significant climb in the same direction.
     */
    public function refreshSubClimbFlagsForSport(string $sportCategory): void
    {
        $climbs = Segment::query()
            ->climbs()
            ->where('sport_category', $sportCategory)
            ->orderBy('id')
            ->get();

        if ($climbs->isEmpty()) {
            return;
        }

        $minEfforts = $this->appSettings()->segmentsMinEfforts();

        /** @var array<int, array{segment: Segment, poly: list<array{0: float, 1: float}>, cum: list<float>}> $entries */
        $entries = [];

        foreach ($climbs as $climb) {
            $dedup = $this->segmentDedupPolyline($climb);
            $entries[$climb->id] = [
                'segment' => $climb,
                'poly' => $dedup['points'],
                'cum' => $dedup['cum_dist'],
            ];
        }

        foreach ($entries as $entryA) {
            $segA = $entryA['segment'];
            $isSubClimb = false;

            if ($segA->is_climb_verified && count($entryA['poly']) >= 2) {
                foreach ($entries as $entryB) {
                    $segB = $entryB['segment'];

                    if ($segB->id === $segA->id || ! $segB->is_climb_verified) {
                        continue;
                    }

                    if ($segB->distance_meters <= ($segA->distance_meters * self::CLIMB_SUBCLIMB_PARENT_DISTANCE_RATIO)) {
                        continue;
                    }

                    if (
                        $segB->detection_count <= $segA->detection_count
                        && $segB->effort_count < $minEfforts
                        && $segB->detection_count < $minEfforts
                    ) {
                        continue;
                    }

                    if (
                        $segB->bbox_max_lat < $segA->bbox_min_lat - self::BBOX_PADDING_DEG
                        || $segB->bbox_min_lat > $segA->bbox_max_lat + self::BBOX_PADDING_DEG
                        || $segB->bbox_max_lng < $segA->bbox_min_lng - self::BBOX_PADDING_DEG
                        || $segB->bbox_min_lng > $segA->bbox_max_lng + self::BBOX_PADDING_DEG
                    ) {
                        continue;
                    }

                    $covAOnB = $this->directionalCoverage(
                        $entryA['poly'],
                        $entryB['poly'],
                        $entryB['cum'],
                        self::CLIMB_DEDUP_CROSS_TRACK_MAX_METERS,
                    );

                    if ($covAOnB >= self::CLIMB_SUBCLIMB_MIN_CONTAINMENT) {
                        $isSubClimb = true;
                        break;
                    }
                }
            }

            if ($segA->is_sub_climb !== $isSubClimb) {
                $segA->update(['is_sub_climb' => $isSubClimb]);

                if (! $isSubClimb) {
                    $this->dispatchGeocodingIfEnabled($segA);
                }
            }
        }
    }

    /**
     * @param  list<array{0: float, 1: float}>  $a
     * @param  list<array{0: float, 1: float}>  $b
     * @return array{0: float, 1: float}
     */
    private function compareWaypoints(array $a, array $b): array
    {
        $count = min(count($a), count($b));

        if ($count === 0) {
            return [INF, INF];
        }

        $sum = 0.0;
        $max = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $d = RouteMatchingService::haversineMeters($a[$i][0], $a[$i][1], $b[$i][0], $b[$i][1]);
            $sum += $d;

            if ($d > $max) {
                $max = $d;
            }
        }

        return [$sum / $count, $max];
    }

    /**
     * Compute effort performance metrics for a matched pass slice.
     *
     * @param  list<GnssStreamPoint>  $points
     * @return array{
     *     started_at_local: CarbonImmutable,
     *     start_index: int,
     *     end_index: int,
     *     start_distance_meters: float,
     *     end_distance_meters: float,
     *     distance_meters: float,
     *     elapsed_seconds: float,
     *     moving_seconds: float,
     *     avg_speed: ?float,
     *     elevation_gain: ?float,
     *     avg_grade_pct: ?float,
     *     vam: ?float,
     *     avg_hr: ?int,
     *     max_hr: ?int,
     *     avg_power: ?float,
     *     w_per_kg: ?float,
     *     avg_cadence: ?float
     * }
     */
    private function computeEffortMetrics(
        Activity $activity,
        array $points,
        int $entryIdx,
        int $exitIdx,
        ?float $weightKg,
    ): array {
        $startPt = $points[$entryIdx];
        $endPt = $points[$exitIdx];

        $startDistance = round($startPt['distance'], 1);
        $endDistance = round($endPt['distance'], 1);
        $distanceMeters = round(max(0.0, $endDistance - $startDistance), 1);

        if ($distanceMeters <= 0.0) {
            $sliceCoords = [];

            for ($k = $entryIdx; $k <= $exitIdx; $k++) {
                $sliceCoords[] = [$points[$k]['lat'], $points[$k]['lng']];
            }

            $cum = $this->computeCumulativeDistances($sliceCoords);
            $distanceMeters = round($cum[count($cum) - 1], 1);
            $endDistance = round($startDistance + $distanceMeters, 1);
        }

        $elapsedSeconds = round(max(1.0, $endPt['time'] - $startPt['time']), 1);
        $movingSeconds = 0.0;

        $hrSum = 0;
        $hrCount = 0;
        $maxHr = null;

        $powerSum = 0.0;
        $powerCount = 0;

        $cadenceSum = 0.0;
        $cadenceCount = 0;

        /** @var list<float> $altitudes */
        $altitudes = [];

        for ($k = $entryIdx; $k <= $exitIdx; $k++) {
            $pt = $points[$k];

            if ($k > $entryIdx) {
                $prev = $points[$k - 1];
                $dt = $pt['time'] - $prev['time'];

                if ($dt > 0.0) {
                    $ds = max(0.0, $pt['distance'] - $prev['distance']);
                    $instSpeed = $pt['speed'] ?? ($ds / $dt);

                    if ($instSpeed >= self::MOVING_SPEED_THRESHOLD_MPS) {
                        $movingSeconds += $dt;
                    }
                }
            }

            if ($pt['hr'] !== null && $pt['hr'] > 0) {
                $hrSum += $pt['hr'];
                $hrCount++;

                if ($maxHr === null || $pt['hr'] > $maxHr) {
                    $maxHr = $pt['hr'];
                }
            }

            if ($pt['power'] !== null && $pt['power'] >= 0.0) {
                $powerSum += $pt['power'];
                $powerCount++;
            }

            if ($pt['cadence'] !== null && $pt['cadence'] > 0.0) {
                $cadenceSum += $pt['cadence'];
                $cadenceCount++;
            }

            if ($pt['altitude'] !== null) {
                $altitudes[] = $pt['altitude'];
            }
        }

        $resolvedMoving = $movingSeconds > 0.0
            ? round(min($elapsedSeconds, $movingSeconds), 1)
            : $elapsedSeconds;

        $avgSpeed = $elapsedSeconds > 0.0 && $distanceMeters > 0.0
            ? round($distanceMeters / $elapsedSeconds, 3)
            : null;

        $elevationGain = null;
        $avgGradePct = null;
        $vam = null;

        if (count($altitudes) >= 2) {
            $smoothed = $this->smoothFloatSeries($altitudes, ClimbDetector::SMOOTHING_WINDOW);
            $gain = 0.0;

            for ($i = 1; $i < count($smoothed); $i++) {
                $delta = $smoothed[$i] - $smoothed[$i - 1];

                if ($delta > ClimbDetector::ALTITUDE_GAIN_NOISE_THRESHOLD) {
                    $gain += $delta;
                }
            }

            $netDelta = $smoothed[count($smoothed) - 1] - $smoothed[0];
            $elevationGain = round(max($gain, max(0.0, $netDelta)), 1);

            if ($distanceMeters > 0.0) {
                $avgGradePct = round(($netDelta / $distanceMeters) * 100.0, 2);
            }

            if ($elevationGain > 0.0 && $elapsedSeconds > 0.0) {
                $vam = round(($elevationGain / $elapsedSeconds) * 3600.0, 1);
            }
        }

        $avgHr = $hrCount > 0 ? (int) round($hrSum / $hrCount) : null;
        $avgPower = $powerCount > 0 ? round($powerSum / $powerCount, 1) : null;
        $wPerKg = ($avgPower !== null && $weightKg !== null && $weightKg > 0.0)
            ? round($avgPower / $weightKg, 2)
            : null;
        $avgCadence = $cadenceCount > 0 ? round($cadenceSum / $cadenceCount, 1) : null;

        return [
            'started_at_local' => $activity->started_at_local->addSeconds((int) round(max(0.0, $startPt['time']))),
            'start_index' => $startPt['orig_index'],
            'end_index' => $endPt['orig_index'],
            'start_distance_meters' => $startDistance,
            'end_distance_meters' => $endDistance,
            'distance_meters' => $distanceMeters,
            'elapsed_seconds' => $elapsedSeconds,
            'moving_seconds' => $resolvedMoving,
            'avg_speed' => $avgSpeed,
            'elevation_gain' => $elevationGain,
            'avg_grade_pct' => $avgGradePct,
            'vam' => $vam,
            'avg_hr' => $avgHr,
            'max_hr' => $maxHr,
            'avg_power' => $avgPower,
            'w_per_kg' => $wPerKg,
            'avg_cadence' => $avgCadence,
        ];
    }

    /**
     * Compute elevation gain/loss, average/max grade, FIETS score, and optional climb category for a custom slice.
     *
     * @param  list<GnssStreamPoint>  $slicePoints
     * @return array{
     *     elevation_gain: ?float,
     *     elevation_loss: ?float,
     *     avg_grade_pct: ?float,
     *     max_grade_pct: ?float,
     *     fiets_score: ?float,
     *     climb_category: ?string
     * }
     */
    private function computeSliceElevationMetrics(array $slicePoints, float $distanceMeters): array
    {
        $altPoints = [];

        foreach ($slicePoints as $pt) {
            if ($pt['altitude'] !== null) {
                $altPoints[] = [
                    'orig_index' => $pt['orig_index'],
                    'time' => $pt['time'],
                    'distance' => $pt['distance'],
                    'altitude' => $pt['altitude'],
                    'lat' => $pt['lat'],
                    'lng' => $pt['lng'],
                ];
            }
        }

        if (count($altPoints) < 2 || $distanceMeters <= 0.0) {
            return [
                'elevation_gain' => null,
                'elevation_loss' => null,
                'avg_grade_pct' => null,
                'max_grade_pct' => null,
                'fiets_score' => null,
                'climb_category' => null,
            ];
        }

        $rawAlt = array_column($altPoints, 'altitude');
        $smoothed = $this->smoothFloatSeries($rawAlt, ClimbDetector::SMOOTHING_WINDOW);

        $gain = 0.0;
        $loss = 0.0;

        for ($i = 1; $i < count($smoothed); $i++) {
            $delta = $smoothed[$i] - $smoothed[$i - 1];

            if ($delta > ClimbDetector::ALTITUDE_GAIN_NOISE_THRESHOLD) {
                $gain += $delta;
            } elseif ($delta < -ClimbDetector::ALTITUDE_GAIN_NOISE_THRESHOLD) {
                $loss += abs($delta);
            }
        }

        $netGain = $smoothed[count($smoothed) - 1] - $smoothed[0];
        $effectiveGain = round(max($gain, max(0.0, $netGain)), 1);
        $roundedLoss = round($loss, 1);
        $avgGrade = round(($netGain / $distanceMeters) * 100.0, 2);
        $maxGrade = $this->climbDetector->computeMaxGrade($altPoints, $smoothed, 0, count($altPoints) - 1);

        $fietsScore = $effectiveGain > 0.0 ? ClimbDetector::computeFietsScore($effectiveGain, $distanceMeters) : null;
        $climbCategory = ($netGain >= ClimbDetector::MIN_ELEVATION_GAIN_METERS && $avgGrade >= ClimbDetector::MIN_AVG_GRADE_PCT)
            ? ClimbDetector::categorizeClimb($distanceMeters, $avgGrade)
            : null;

        return [
            'elevation_gain' => $effectiveGain,
            'elevation_loss' => $roundedLoss,
            'avg_grade_pct' => $avgGrade,
            'max_grade_pct' => $maxGrade,
            'fiets_score' => $fietsScore,
            'climb_category' => $climbCategory,
        ];
    }

    /**
     * Resolve the athlete's latest weight in kg on or before the activity's local date via HealthSourceRegistry.
     */
    private function resolveAthleteWeightKg(Activity $activity): ?float
    {
        $activityDate = $activity->started_at_local->format('Y-m-d');

        $query = $this->healthSources()->applyScope(WeightMeasurement::query(), 'weight');

        /** @var WeightMeasurement|null $measurement */
        $measurement = $query
            ->where('calendar_date', '<=', $activityDate)
            ->orderByDesc('calendar_date')
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first();

        if ($measurement === null || $measurement->weight_grams <= 0) {
            return null;
        }

        return round($measurement->weight_grams / 1000.0, 2);
    }

    /**
     * Upsert the matched passes of a segment within one activity and delete that activity's efforts on the
     * segment whose passes were not found again (an empty `$passes` removes every effort for the pair).
     *
     * @param  list<GnssStreamPoint>  $points
     * @param  list<MatchedPass>  $passes
     * @return array{recorded: int, changed: bool}
     */
    private function persistPasses(
        Segment $segment,
        Activity $activity,
        array $points,
        array $passes,
        ?float $weightKg,
    ): array {
        /** @var list<int> $matchedStartIndices */
        $matchedStartIndices = [];

        foreach ($passes as $pass) {
            $effortData = $this->computeEffortMetrics($activity, $points, $pass['entry_idx'], $pass['exit_idx'], $weightKg);
            $matchedStartIndices[] = $effortData['start_index'];

            SegmentEffort::query()->updateOrCreate(
                [
                    'segment_id' => $segment->id,
                    'activity_id' => $activity->id,
                    'start_index' => $effortData['start_index'],
                ],
                $effortData,
            );
        }

        $deleted = SegmentEffort::query()
            ->where('segment_id', $segment->id)
            ->where('activity_id', $activity->id)
            ->whereNotIn('start_index', $matchedStartIndices)
            ->delete();

        return [
            'recorded' => count($passes),
            'changed' => $passes !== [] || $deleted > 0,
        ];
    }

    /**
     * Delete an activity's efforts on segments that were not evaluated in the current scan
     * (sport changed, segment bbox no longer overlaps, or the stream lost its GNSS data).
     *
     * @param  list<int>  $evaluatedSegmentIds
     * @return list<int> Segment ids whose efforts were removed.
     */
    private function pruneUnevaluatedEfforts(Activity $activity, array $evaluatedSegmentIds): array
    {
        $staleQuery = SegmentEffort::query()
            ->where('activity_id', $activity->id)
            ->whereNotIn('segment_id', $evaluatedSegmentIds);

        /** @var list<int> $staleSegmentIds */
        $staleSegmentIds = array_values(array_unique(array_map(
            'intval',
            (clone $staleQuery)->pluck('segment_id')->all(),
        )));

        if ($staleSegmentIds !== []) {
            $staleQuery->delete();
        }

        return $staleSegmentIds;
    }

    /**
     * @param  list<int>  $segmentIds
     */
    private function refreshRankingsFor(array $segmentIds): void
    {
        if ($segmentIds === []) {
            return;
        }

        $segments = Segment::query()
            ->whereIn('id', $segmentIds)
            ->orderBy('id')
            ->get();

        foreach ($segments as $segment) {
            $this->refreshSegmentRankings($segment, dispatchGeocode: false);
        }
    }

    /**
     * Replace the set of climbs detected in an activity so re-scans never double count a detection.
     *
     * @param  list<int>  $segmentIds
     * @return list<int> Segment ids whose detections were attached or detached.
     */
    private function syncActivityDetections(Activity $activity, array $segmentIds): array
    {
        $changes = $activity->detectedSegments()->sync(array_values(array_unique($segmentIds)));

        return array_values(array_unique(array_map(
            'intval',
            [...$changes['attached'], ...$changes['detached']],
        )));
    }

    /**
     * Recompute `detection_count` as the number of distinct activities in which each climb was detected.
     *
     * @param  list<int>  $segmentIds
     */
    private function refreshDetectionCounts(array $segmentIds): void
    {
        if ($segmentIds === []) {
            return;
        }

        $segments = Segment::withTrashed()
            ->whereIn('id', $segmentIds)
            ->withCount('detectedActivities')
            ->get();

        foreach ($segments as $segment) {
            $count = (int) $segment->getAttribute('detected_activities_count');

            if ($segment->detection_count !== $count) {
                $segment->update(['detection_count' => $count]);
            }
        }
    }

    /**
     * @return Builder<Activity>
     */
    private function historicalCandidateQuery(Segment $segment, ?int $excludeActivityId = null): Builder
    {
        $query = Activity::query()
            ->where('is_indoor', false)
            ->where('sport_category', $segment->sport_category)
            ->whereExists(function (QueryBuilder $streamSub): void {
                $streamSub
                    ->selectRaw('1')
                    ->from('activity_streams')
                    ->whereColumn('activity_streams.activity_id', 'activities.id')
                    ->whereNotNull('activity_streams.series')
                    ->where('activity_streams.series', '!=', '[]')
                    ->whereRaw('LENGTH(activity_streams.series) >= 50');
            });

        if ($excludeActivityId !== null) {
            $query->where('id', '!=', $excludeActivityId);
        }

        // Route fingerprints carry each outdoor activity's bbox; skip activities that cannot overlap the segment.
        $query->where(function (Builder $overlap) use ($segment): void {
            $overlap
                ->whereDoesntHave('routeFingerprint')
                ->orWhereHas('routeFingerprint', function (Builder $fingerprint) use ($segment): void {
                    $fingerprint
                        ->where('bbox_max_lat', '>=', $segment->bbox_min_lat - self::BBOX_PADDING_DEG)
                        ->where('bbox_min_lat', '<=', $segment->bbox_max_lat + self::BBOX_PADDING_DEG)
                        ->where('bbox_max_lng', '>=', $segment->bbox_min_lng - self::BBOX_PADDING_DEG)
                        ->where('bbox_min_lng', '<=', $segment->bbox_max_lng + self::BBOX_PADDING_DEG);
                });
        });

        return $query;
    }

    /**
     * @param  list<array{
     *     sport_category: string,
     *     distance_meters: float,
     *     start_lat: float,
     *     start_lng: float,
     *     end_lat: float,
     *     end_lng: float,
     *     dedup_poly: list<array{0: float, 1: float}>,
     *     dedup_cum: list<float>,
     *     name_override: ?string,
     *     is_starred: bool
     * }>  $preservedClimbs
     */
    private function restorePreservedClimbMetadata(array $preservedClimbs): void
    {
        $newClimbs = Segment::query()->climbs()->get();

        foreach ($newClimbs as $climb) {
            $dedup = $this->segmentDedupPolyline($climb);
            $bestMatch = null;
            $bestScore = -1.0;

            foreach ($preservedClimbs as $preserved) {
                if ($preserved['sport_category'] !== $climb->sport_category) {
                    continue;
                }

                $score = $this->climbOverlapScore(
                    distanceA: $climb->distance_meters,
                    startLatA: $climb->start_lat,
                    startLngA: $climb->start_lng,
                    endLatA: $climb->end_lat,
                    endLngA: $climb->end_lng,
                    polyA: $dedup['points'],
                    cumA: $dedup['cum_dist'],
                    distanceB: $preserved['distance_meters'],
                    startLatB: $preserved['start_lat'],
                    startLngB: $preserved['start_lng'],
                    endLatB: $preserved['end_lat'],
                    endLngB: $preserved['end_lng'],
                    polyB: $preserved['dedup_poly'],
                    cumB: $preserved['dedup_cum'],
                );

                if ($score !== null && $score > $bestScore) {
                    $bestScore = $score;
                    $bestMatch = $preserved;
                }
            }

            if ($bestMatch !== null) {
                $updates = [];

                if ($bestMatch['name_override'] !== null) {
                    $updates['name_override'] = $bestMatch['name_override'];
                }

                if ($bestMatch['is_starred']) {
                    $updates['is_starred'] = true;
                }

                if ($updates !== []) {
                    $climb->update($updates);
                    $this->dispatchGeocodingIfEnabled($climb->fresh() ?? $climb);
                }
            }
        }
    }

    /**
     * @param  list<GnssStreamPoint>  $points
     * @return list<GnssStreamPoint>
     */
    private function sliceGnssPointsByOrigIndex(array $points, int $startOrigIndex, int $endOrigIndex): array
    {
        $slice = [];

        foreach ($points as $point) {
            if ($point['orig_index'] >= $startOrigIndex && $point['orig_index'] <= $endOrigIndex) {
                $slice[] = $point;
            }
        }

        return $slice;
    }

    /**
     * @param  list<GnssStreamPoint>  $points
     * @return list<array{0: float, 1: float}>
     */
    private function pointsToLatLng(array $points): array
    {
        $coords = [];

        foreach ($points as $point) {
            $coords[] = [$point['lat'], $point['lng']];
        }

        return $coords;
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function smoothFloatSeries(array $values, int $window): array
    {
        $count = count($values);
        $radius = max(1, intdiv($window, 2));
        $smoothed = [];

        for ($i = 0; $i < $count; $i++) {
            $lo = max(0, $i - $radius);
            $hi = min($count - 1, $i + $radius);
            $sum = 0.0;

            for ($k = $lo; $k <= $hi; $k++) {
                $sum += $values[$k];
            }

            $smoothed[] = $sum / ($hi - $lo + 1);
        }

        return $smoothed;
    }

    private function withinPrefilter(float $latA, float $lngA, float $latB, float $lngB): bool
    {
        return abs($latA - $latB) <= self::PASS_COORD_PREFILTER_DEG
            && abs($lngA - $lngB) <= self::PASS_COORD_PREFILTER_DEG * 2.0;
    }

    private function buildInitialClimbName(Activity $activity, float $distanceMeters, float $avgGradePct): string
    {
        return $this->buildInitialClimbNameFromLocation($activity->location_name, $distanceMeters, $avgGradePct);
    }

    private function buildInitialClimbNameFromLocation(?string $locationName, float $distanceMeters, float $avgGradePct): string
    {
        $distLabel = $distanceMeters >= 1000.0
            ? number_format($distanceMeters / 1000.0, 2).' km'
            : ((int) round($distanceMeters)).' m';
        $gradeLabel = number_format($avgGradePct, 1).'%';
        $location = is_string($locationName) && trim($locationName) !== ''
            ? trim(explode(',', trim($locationName))[0])
            : null;

        if ($location !== null && $location !== '') {
            return mb_substr("{$location} Climb ({$distLabel} @ {$gradeLabel})", 0, 160);
        }

        return mb_substr("Climb ({$distLabel} @ {$gradeLabel})", 0, 160);
    }

    /**
     * @param  list<float>  $values
     */
    private function medianFloat(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    private function buildInitialCustomName(Activity $activity, float $distanceMeters): string
    {
        $distLabel = $distanceMeters >= 1000.0
            ? number_format($distanceMeters / 1000.0, 2).' km'
            : ((int) round($distanceMeters)).' m';
        $location = is_string($activity->location_name) && trim($activity->location_name) !== ''
            ? trim(explode(',', trim($activity->location_name))[0])
            : null;

        if ($location !== null && $location !== '') {
            return mb_substr("{$location} Segment ({$distLabel})", 0, 160);
        }

        return mb_substr("Custom Segment ({$distLabel})", 0, 160);
    }

    private function dispatchGeocodingIfEnabled(Segment $segment): void
    {
        if (
            $segment->geocoded_at === null
            && $segment->geocode_attempted_at === null
            && $segment->isVisible()
            && (bool) config('services.nominatim.enabled', true)
            && (bool) config('services.nominatim.auto_sync', true)
        ) {
            GeocodeSegmentJob::dispatch($segment->id);
        }
    }
}
