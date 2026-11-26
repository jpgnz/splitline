<?php

namespace App\Services\Segments;

use App\Jobs\GeocodeSegmentJob;
use App\Jobs\MatchSegmentBackfillJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\Segment;
use App\Models\SegmentEffort;
use App\Models\WeightMeasurement;
use App\Services\Heatmap\HeatmapService;
use App\Services\Routes\RouteMatchingService;
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
 */
class SegmentMatchingService
{
    public const int WAYPOINT_COUNT = 20;

    public const int PREVIEW_MAX_POINTS = 80;

    public const float BBOX_PADDING_DEG = 0.002;

    public const float CLIMB_DEDUP_ENDPOINT_MAX_METERS = 150.0;

    public const float CLIMB_DEDUP_MAX_DISTANCE_DIFF = 0.20;

    public const float CLIMB_DEDUP_MEAN_WAYPOINT_MAX_METERS = 80.0;

    public const float PASS_ENDPOINT_MAX_METERS = 60.0;

    public const float PASS_DISTANCE_TOLERANCE = 0.20;

    public const float PASS_MEAN_WAYPOINT_MAX_METERS = 65.0;

    public const float PASS_MAX_WAYPOINT_MAX_METERS = 160.0;

    public const float PASS_COORD_PREFILTER_DEG = 0.0012;

    public const int CUSTOM_MIN_GNSS_POINTS = 5;

    public const float CUSTOM_MIN_DISTANCE_METERS = 100.0;

    public const int SYNC_BACKFILL_LIMIT = 200;

    public const float MOVING_SPEED_THRESHOLD_MPS = 0.5;

    public function __construct(
        private readonly ClimbDetector $climbDetector = new ClimbDetector,
        private readonly ?HealthSourceRegistry $healthSources = null,
    ) {}

    private function healthSources(): HealthSourceRegistry
    {
        return $this->healthSources ?? app(HealthSourceRegistry::class);
    }

    /**
     * Scan a single activity's stream for auto-detected climbs and passes over all overlapping segments.
     * Always stamps `segments_scanned_at` so activities are never re-scanned infinitely.
     *
     * @return ActivityScanSummary
     */
    public function scanActivity(Activity $activity, ?ActivityStream $stream = null): array
    {
        $now = CarbonImmutable::now('UTC');

        if ($activity->is_indoor) {
            $activity->update(['segments_scanned_at' => $now]);

            return ['efforts_recorded' => 0, 'climbs_created' => 0];
        }

        $resolvedStream = $stream ?? $activity->stream()->first();

        if ($resolvedStream === null || ! is_array($resolvedStream->series) || $resolvedStream->series === []) {
            $activity->update(['segments_scanned_at' => $now]);

            return ['efforts_recorded' => 0, 'climbs_created' => 0];
        }

        $rawSeries = $resolvedStream->series;
        $points = $this->extractGnssPoints($rawSeries);

        if (count($points) < self::CUSTOM_MIN_GNSS_POINTS) {
            $activity->update(['segments_scanned_at' => $now]);

            return ['efforts_recorded' => 0, 'climbs_created' => 0];
        }

        $climbsCreated = 0;
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
                waypoints: $waypoints,
            );

            if ($existingClimb !== null) {
                continue;
            }

            $previewPoints = $this->simplifyForPreview($coords, self::PREVIEW_MAX_POINTS);
            $encodedPreview = HeatmapService::encodePolyline($previewPoints);

            $segment = Segment::query()->create([
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
                'best_elapsed_seconds' => null,
                'best_effort_id' => null,
                'last_effort_at' => null,
                'geocoded_at' => null,
            ]);

            $climbsCreated++;
            $this->dispatchGeocodingIfEnabled($segment);
        }

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

        foreach ($candidates as $segment) {
            $passes = $this->findPassesInStream($segment, $points);

            if ($passes === []) {
                continue;
            }

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

                $effortsRecorded++;
            }

            SegmentEffort::query()
                ->where('segment_id', $segment->id)
                ->where('activity_id', $activity->id)
                ->whereNotIn('start_index', $matchedStartIndices)
                ->delete();

            $this->refreshSegmentRankings($segment);
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
            throw new InvalidArgumentException('Custom segments can only be created from outdoor activities with GNSS data.');
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
            throw new InvalidArgumentException('Selected range must contain at least '.self::CUSTOM_MIN_GNSS_POINTS.' valid GNSS points.');
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

        $this->refreshSegmentRankings($segment);
        $this->dispatchGeocodingIfEnabled($segment);

        $historicalCandidateCount = $this->historicalCandidateQuery($segment, excludeActivityId: $activity->id)->count();

        if ($historicalCandidateCount > 0) {
            if ($historicalCandidateCount <= self::SYNC_BACKFILL_LIMIT) {
                $this->matchSegmentAgainstHistory($segment, excludeActivityId: $activity->id);
            } else {
                MatchSegmentBackfillJob::dispatch($segment->id);
            }
        }

        return $segment->fresh() ?? $segment;
    }

    /**
     * Scan historical activities in the segment's sport_category to record all matching passes and refresh rankings.
     */
    public function matchSegmentAgainstHistory(
        Segment $segment,
        int $chunkSize = 100,
        ?int $excludeActivityId = null,
    ): int {
        $chunkSize = max(1, $chunkSize);
        $ids = $this->historicalCandidateQuery($segment, $excludeActivityId)
            ->orderBy('started_at_local')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $effortsRecorded = 0;

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

                $points = $this->extractGnssPoints($stream->series);

                if (count($points) < self::CUSTOM_MIN_GNSS_POINTS) {
                    continue;
                }

                $coords = $this->pointsToLatLng($points);
                [$actMinLat, $actMaxLat, $actMinLng, $actMaxLng] = $this->computeBoundingBox($coords);

                if (
                    $segment->bbox_max_lat < $actMinLat - self::BBOX_PADDING_DEG
                    || $segment->bbox_min_lat > $actMaxLat + self::BBOX_PADDING_DEG
                    || $segment->bbox_max_lng < $actMinLng - self::BBOX_PADDING_DEG
                    || $segment->bbox_min_lng > $actMaxLng + self::BBOX_PADDING_DEG
                ) {
                    continue;
                }

                $passes = $this->findPassesInStream($segment, $points);

                if ($passes === []) {
                    continue;
                }

                $weightKg = $this->resolveAthleteWeightKg($activity);
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

                    $effortsRecorded++;
                }

                SegmentEffort::query()
                    ->where('segment_id', $segment->id)
                    ->where('activity_id', $activity->id)
                    ->whereNotIn('start_index', $matchedStartIndices)
                    ->delete();
            }
        }

        $this->refreshSegmentRankings($segment);

        return $effortsRecorded;
    }

    /**
     * Recompute rankings (1..N by elapsed_seconds ASC), PR flag, and aggregate stats on a Segment.
     */
    public function refreshSegmentRankings(Segment $segment): Segment
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
                'best_elapsed_seconds' => null,
                'best_effort_id' => null,
                'last_effort_at' => null,
            ]);

            return $segment->fresh() ?? $segment;
        }

        $rank = 0;
        $bestEffort = null;
        $lastEffortAt = null;

        foreach ($efforts as $effort) {
            $rank++;
            $isPr = $rank === 1;

            if ($isPr) {
                $bestEffort = $effort;
            }

            if ($lastEffortAt === null || $effort->started_at_local->greaterThan($lastEffortAt)) {
                $lastEffortAt = $effort->started_at_local;
            }

            if ($effort->rank !== $rank || $effort->is_pr !== $isPr) {
                $effort->update([
                    'rank' => $rank,
                    'is_pr' => $isPr,
                ]);
            }
        }

        $segment->update([
            'effort_count' => $efforts->count(),
            'best_elapsed_seconds' => $bestEffort?->elapsed_seconds,
            'best_effort_id' => $bestEffort?->id,
            'last_effort_at' => $lastEffortAt,
        ]);

        return $segment->fresh() ?? $segment;
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
     * Scan all outdoor streamed activities that have not yet been scanned for climbs and segments.
     *
     * @return SegmentScanRunSummary
     */
    public function scanUnscanned(int $chunkSize = 50): array
    {
        $chunkSize = max(1, $chunkSize);
        $scanned = 0;
        $effortsRecorded = 0;
        $climbsCreated = 0;

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

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $activities = Activity::query()
                ->with('stream')
                ->whereIn('id', $idChunk)
                ->orderBy('started_at_local')
                ->orderBy('id')
                ->get();

            foreach ($activities as $activity) {
                $scanned++;
                $summary = $this->scanActivity($activity, $activity->stream);
                $effortsRecorded += $summary['efforts_recorded'];
                $climbsCreated += $summary['climbs_created'];
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
         *     waypoints: list<array{0: float, 1: float}>,
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

            $preservedClimbs[] = [
                'sport_category' => $climb->sport_category,
                'distance_meters' => $climb->distance_meters,
                'start_lat' => $climb->start_lat,
                'start_lng' => $climb->start_lng,
                'end_lat' => $climb->end_lat,
                'end_lng' => $climb->end_lng,
                'waypoints' => $climb->waypoints,
                'name_override' => $override,
                'is_starred' => $climb->is_starred,
            ];
        }

        DB::transaction(function (): void {
            SegmentEffort::query()->delete();
            Segment::query()->climbs()->delete();
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
     * @param  list<array{0: float, 1: float}>  $waypoints
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
        array $waypoints,
    ): ?Segment {
        $candidates = Segment::query()
            ->climbs()
            ->where('sport_category', $sportCategory)
            ->where('bbox_max_lat', '>=', $bboxMinLat - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lat', '<=', $bboxMaxLat + self::BBOX_PADDING_DEG)
            ->where('bbox_max_lng', '>=', $bboxMinLng - self::BBOX_PADDING_DEG)
            ->where('bbox_min_lng', '<=', $bboxMaxLng + self::BBOX_PADDING_DEG)
            ->orderBy('id')
            ->get();

        $bestMatch = null;
        $bestMean = INF;

        foreach ($candidates as $candidate) {
            $mean = $this->climbSimilarityMeanDistance(
                distanceA: $distanceMeters,
                startLatA: $startLat,
                startLngA: $startLng,
                endLatA: $endLat,
                endLngA: $endLng,
                waypointsA: $waypoints,
                distanceB: $candidate->distance_meters,
                startLatB: $candidate->start_lat,
                startLngB: $candidate->start_lng,
                endLatB: $candidate->end_lat,
                endLngB: $candidate->end_lng,
                waypointsB: $candidate->waypoints,
            );

            if ($mean !== null && $mean < $bestMean) {
                $bestMean = $mean;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $waypointsA
     * @param  list<array{0: float, 1: float}>  $waypointsB
     */
    private function climbSimilarityMeanDistance(
        float $distanceA,
        float $startLatA,
        float $startLngA,
        float $endLatA,
        float $endLngA,
        array $waypointsA,
        float $distanceB,
        float $startLatB,
        float $startLngB,
        float $endLatB,
        float $endLngB,
        array $waypointsB,
    ): ?float {
        $startDist = RouteMatchingService::haversineMeters($startLatA, $startLngA, $startLatB, $startLngB);

        if ($startDist > self::CLIMB_DEDUP_ENDPOINT_MAX_METERS) {
            return null;
        }

        $endDist = RouteMatchingService::haversineMeters($endLatA, $endLngA, $endLatB, $endLngB);

        if ($endDist > self::CLIMB_DEDUP_ENDPOINT_MAX_METERS) {
            return null;
        }

        $maxDist = max($distanceA, $distanceB);

        if ($maxDist <= 0.0 || (abs($distanceA - $distanceB) / $maxDist) > self::CLIMB_DEDUP_MAX_DISTANCE_DIFF) {
            return null;
        }

        if (count($waypointsA) !== self::WAYPOINT_COUNT || count($waypointsB) !== self::WAYPOINT_COUNT) {
            return null;
        }

        [$meanDist] = $this->compareWaypoints($waypointsA, $waypointsB);

        if ($meanDist > self::CLIMB_DEDUP_MEAN_WAYPOINT_MAX_METERS) {
            return null;
        }

        return $meanDist;
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
     *     waypoints: list<array{0: float, 1: float}>,
     *     name_override: ?string,
     *     is_starred: bool
     * }>  $preservedClimbs
     */
    private function restorePreservedClimbMetadata(array $preservedClimbs): void
    {
        $newClimbs = Segment::query()->climbs()->get();

        foreach ($newClimbs as $climb) {
            $bestMatch = null;
            $bestMean = INF;

            foreach ($preservedClimbs as $preserved) {
                if ($preserved['sport_category'] !== $climb->sport_category) {
                    continue;
                }

                $mean = $this->climbSimilarityMeanDistance(
                    distanceA: $climb->distance_meters,
                    startLatA: $climb->start_lat,
                    startLngA: $climb->start_lng,
                    endLatA: $climb->end_lat,
                    endLngA: $climb->end_lng,
                    waypointsA: $climb->waypoints,
                    distanceB: $preserved['distance_meters'],
                    startLatB: $preserved['start_lat'],
                    startLngB: $preserved['start_lng'],
                    endLatB: $preserved['end_lat'],
                    endLngB: $preserved['end_lng'],
                    waypointsB: $preserved['waypoints'],
                );

                if ($mean !== null && $mean < $bestMean) {
                    $bestMean = $mean;
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
        $distLabel = $distanceMeters >= 1000.0
            ? number_format($distanceMeters / 1000.0, 2).' km'
            : ((int) round($distanceMeters)).' m';
        $gradeLabel = number_format($avgGradePct, 1).'%';
        $location = is_string($activity->location_name) && trim($activity->location_name) !== ''
            ? trim(explode(',', trim($activity->location_name))[0])
            : null;

        if ($location !== null && $location !== '') {
            return mb_substr("{$location} Climb ({$distLabel} @ {$gradeLabel})", 0, 160);
        }

        return mb_substr("Climb ({$distLabel} @ {$gradeLabel})", 0, 160);
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
            (bool) config('services.nominatim.enabled', true)
            && (bool) config('services.nominatim.auto_sync', true)
        ) {
            GeocodeSegmentJob::dispatch($segment->id);
        }
    }
}
