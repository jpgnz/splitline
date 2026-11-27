<?php

namespace App\Services\Sync;

use App\Jobs\ComputeDerivedMetricsJob;
use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Jobs\GeocodeSegmentJob;
use App\Jobs\MatchUnfingerprintedRoutesJob;
use App\Jobs\ScanActivitySegmentsJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Services\Activities\ActivityDerivedMetricsService;
use App\Services\Routes\RouteMatchingService;
use App\Services\Segments\SegmentMatchingService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspects queued background enrichment jobs and remaining unenriched activity counts.
 *
 * @phpstan-type EnrichmentQueueJobs array{
 *     jobs: array{default: int, streams: int, weather: int, total: int},
 *     has_active_work: bool
 * }
 * @phpstan-type EnrichmentQueueSummary array{
 *     jobs: array{default: int, streams: int, weather: int, geocode: int, total: int},
 *     pending: array{streams: int, weather: int, routes: int, segments: int, derived_metrics: int, geocoding: int},
 *     has_active_work: bool
 * }
 */
class QueueStatusService
{
    public function __construct(
        private readonly RouteMatchingService $routeMatchingService = new RouteMatchingService,
        private readonly SegmentMatchingService $segmentMatchingService = new SegmentMatchingService,
        private readonly ActivityDerivedMetricsService $derivedMetricsService = new ActivityDerivedMetricsService,
    ) {}

    /**
     * Build the lightweight queued-job summary that is cheap enough to share on every request and poll.
     *
     * @return EnrichmentQueueJobs
     */
    public function activeJobs(): array
    {
        $jobs = $this->queuedJobCounts();

        return [
            'jobs' => $jobs,
            'has_active_work' => $jobs['total'] > 0,
        ];
    }

    /**
     * Build the combined background queue and pending enrichment summary, including per-activity backlog counts.
     *
     * @return EnrichmentQueueSummary
     */
    public function summary(): array
    {
        $jobs = $this->queuedJobCounts();

        return [
            'jobs' => $jobs,
            'pending' => $this->pendingEnrichmentCounts(),
            'has_active_work' => $jobs['total'] > 0,
        ];
    }

    /**
     * @return array{default: int, streams: int, weather: int, geocode: int, total: int}
     */
    private function queuedJobCounts(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return [
                'default' => 0,
                'streams' => 0,
                'weather' => 0,
                'geocode' => 0,
                'total' => 0,
            ];
        }

        $rows = DB::table('jobs')
            ->selectRaw('queue, COUNT(*) as aggregate')
            ->groupBy('queue')
            ->get();

        $default = 0;
        $streams = 0;
        $weather = 0;
        $geocode = 0;

        foreach ($rows as $row) {
            $queue = is_string($row->queue ?? null) ? $row->queue : 'default';
            $count = (int) ($row->aggregate ?? 0);

            if ($queue === SyncActivityStreamJob::QUEUE) {
                $streams += $count;
            } elseif ($queue === SyncActivityWeatherJob::QUEUE) {
                $weather += $count;
            } elseif ($queue === GeocodeRouteClusterJob::QUEUE) {
                $geocode += $count;
            } else {
                $default += $count;
            }
        }

        return [
            'default' => $default,
            'streams' => $streams,
            'weather' => $weather,
            'geocode' => $geocode,
            'total' => $default + $streams + $weather + $geocode,
        ];
    }

    /**
     * @return array{streams: int, weather: int, routes: int, segments: int, derived_metrics: int, geocoding: int}
     */
    private function pendingEnrichmentCounts(): array
    {
        if (! Schema::hasTable('activities')) {
            return [
                'streams' => 0,
                'weather' => 0,
                'routes' => 0,
                'segments' => 0,
                'derived_metrics' => 0,
                'geocoding' => 0,
            ];
        }

        $awaitingStreams = Activity::query()
            ->where('is_manual', false)
            ->awaitingStreams()
            ->count();

        $hasEnrichmentTables = Schema::hasTable('activity_streams') && Schema::hasTable('activity_weathers');

        $missingWeather = $hasEnrichmentTables
            ? Activity::query()->awaitingWeather()->count()
            : 0;

        $unfingerprintedRoutes = ($hasEnrichmentTables && Schema::hasTable('activity_route_fingerprints'))
            ? $this->routeMatchingService->unfingerprintedCount()
            : 0;

        $unscannedSegments = ($hasEnrichmentTables && Schema::hasColumn('activities', 'segments_scanned_at'))
            ? $this->segmentMatchingService->unscannedCount()
            : 0;

        $missingDerivedMetrics = ($hasEnrichmentTables && Schema::hasTable('activity_derived_metrics'))
            ? $this->derivedMetricsService->missingCount()
            : 0;

        $pendingGeocoding = 0;

        if (Schema::hasColumn('activities', 'geocode_attempted_at')) {
            $pendingGeocoding += Activity::query()->awaitingGeocoding()->count();
        }

        if (Schema::hasTable('route_clusters') && Schema::hasColumn('route_clusters', 'geocode_attempted_at')) {
            $pendingGeocoding += RouteCluster::query()->awaitingGeocoding()->count();
        }

        if (Schema::hasTable('segments') && Schema::hasColumn('segments', 'geocode_attempted_at')) {
            $pendingGeocoding += Segment::query()->awaitingGeocoding()->count();
        }

        return [
            'streams' => $awaitingStreams,
            'weather' => $missingWeather,
            'routes' => $unfingerprintedRoutes,
            'segments' => $unscannedSegments,
            'derived_metrics' => $missingDerivedMetrics,
            'geocoding' => $pendingGeocoding,
        ];
    }

    /**
     * Dispatch background queue jobs for every currently pending activity enrichment.
     *
     * Category counts and `total` only include newly queued work; pending items whose unique job is
     * already waiting on the queue are reported separately as `already_queued`.
     *
     * @return array{
     *     streams: int,
     *     weather: int,
     *     routes: int,
     *     segments: int,
     *     derived_metrics: int,
     *     geocoding: int,
     *     total: int,
     *     already_queued: int
     * }
     */
    public function queuePendingEnrichments(): array
    {
        $counts = [
            'streams' => 0,
            'weather' => 0,
            'routes' => 0,
            'segments' => 0,
            'derived_metrics' => 0,
            'geocoding' => 0,
            'total' => 0,
            'already_queued' => 0,
        ];

        if (! Schema::hasTable('activities')) {
            return $counts;
        }

        if (trim((string) config('services.garmin.key', '')) !== '') {
            /** @var list<int> $streamIds */
            $streamIds = array_values(array_map(
                'intval',
                Activity::query()
                    ->where('is_manual', false)
                    ->awaitingStreams()
                    ->orderByDesc('started_at_local')
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->all(),
            ));

            if ($streamIds !== []) {
                DB::transaction(function () use ($streamIds, &$counts): void {
                    foreach ($streamIds as $activityId) {
                        $this->recordDispatch($counts, 'streams', new SyncActivityStreamJob($activityId));
                    }
                });
            }
        }

        $hasEnrichmentTables = Schema::hasTable('activity_streams') && Schema::hasTable('activity_weathers');

        if ($hasEnrichmentTables && (bool) config('services.open_meteo.enabled', true)) {
            /** @var list<int> $weatherIds */
            $weatherIds = array_values(array_map(
                'intval',
                Activity::query()
                    ->awaitingWeather()
                    ->orderByDesc('started_at_local')
                    ->orderByDesc('id')
                    ->pluck('id')
                    ->all(),
            ));

            if ($weatherIds !== []) {
                DB::transaction(function () use ($weatherIds, &$counts): void {
                    foreach ($weatherIds as $activityId) {
                        $this->recordDispatch($counts, 'weather', new SyncActivityWeatherJob($activityId));
                    }
                });
            }
        }

        if ($hasEnrichmentTables && Schema::hasTable('activity_route_fingerprints')) {
            $unfingerprintedRoutes = $this->routeMatchingService->unfingerprintedCount();

            if ($unfingerprintedRoutes > 0) {
                $this->recordDispatch($counts, 'routes', new MatchUnfingerprintedRoutesJob(rebuild: false), $unfingerprintedRoutes);
            }
        }

        if ($hasEnrichmentTables && Schema::hasColumn('activities', 'segments_scanned_at')) {
            $unscannedSegments = $this->segmentMatchingService->unscannedCount();

            if ($unscannedSegments > 0) {
                $this->recordDispatch($counts, 'segments', new ScanActivitySegmentsJob(rebuild: false), $unscannedSegments);
            }
        }

        if ($hasEnrichmentTables && Schema::hasTable('activity_derived_metrics')) {
            $missingDerivedMetrics = $this->derivedMetricsService->missingCount();

            if ($missingDerivedMetrics > 0) {
                $this->recordDispatch($counts, 'derived_metrics', new ComputeDerivedMetricsJob(force: false), $missingDerivedMetrics);
            }
        }

        if ((bool) config('services.nominatim.enabled', true)) {
            /** @var list<int> $clusterIds */
            $clusterIds = (Schema::hasTable('route_clusters') && Schema::hasColumn('route_clusters', 'geocode_attempted_at'))
                ? array_values(array_map(
                    'intval',
                    RouteCluster::query()
                        ->awaitingGeocoding()
                        ->orderByDesc('activity_count')
                        ->orderBy('id')
                        ->pluck('id')
                        ->all(),
                ))
                : [];

            /** @var list<int> $segmentIds */
            $segmentIds = (Schema::hasTable('segments') && Schema::hasColumn('segments', 'geocode_attempted_at'))
                ? array_values(array_map(
                    'intval',
                    Segment::query()
                        ->awaitingGeocoding()
                        ->orderByDesc('effort_count')
                        ->orderBy('id')
                        ->pluck('id')
                        ->all(),
                ))
                : [];

            /** @var list<int> $activityIds */
            $activityIds = Schema::hasColumn('activities', 'geocode_attempted_at')
                ? array_values(array_map(
                    'intval',
                    Activity::query()
                        ->awaitingGeocoding()
                        ->orderByDesc('started_at_local')
                        ->orderByDesc('id')
                        ->pluck('id')
                        ->all(),
                ))
                : [];

            if ($clusterIds !== [] || $segmentIds !== [] || $activityIds !== []) {
                DB::transaction(function () use ($clusterIds, $segmentIds, $activityIds, &$counts): void {
                    foreach ($clusterIds as $clusterId) {
                        $this->recordDispatch($counts, 'geocoding', new GeocodeRouteClusterJob($clusterId));
                    }

                    foreach ($segmentIds as $segmentId) {
                        $this->recordDispatch($counts, 'geocoding', new GeocodeSegmentJob($segmentId));
                    }

                    foreach ($activityIds as $activityId) {
                        $this->recordDispatch($counts, 'geocoding', new GeocodeActivityLocationJob($activityId));
                    }
                });
            }
        }

        $counts['total'] = $counts['streams']
            + $counts['weather']
            + $counts['routes']
            + $counts['segments']
            + $counts['derived_metrics']
            + $counts['geocoding'];

        return $counts;
    }

    /**
     * Dispatch a unique enrichment job and tally its pending items as newly queued, or as already queued
     * when an identical job still holds its unique lock (the framework would silently skip the dispatch).
     *
     * @param  array{streams: int, weather: int, routes: int, segments: int, derived_metrics: int, geocoding: int, total: int, already_queued: int}  $counts
     * @param  'streams'|'weather'|'routes'|'segments'|'derived_metrics'|'geocoding'  $category
     */
    private function recordDispatch(array &$counts, string $category, ShouldBeUnique $job, int $pendingItems = 1): void
    {
        $lock = Cache::lock(UniqueLock::getKey($job));

        if ($lock instanceof Lock && $lock->isLocked()) {
            $counts['already_queued'] += $pendingItems;

            return;
        }

        dispatch($job);

        $counts[$category] += $pendingItems;
    }
}
