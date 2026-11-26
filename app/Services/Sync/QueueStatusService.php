<?php

namespace App\Services\Sync;

use App\Jobs\GeocodeRouteClusterJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Services\Activities\ActivityDerivedMetricsService;
use App\Services\Routes\RouteMatchingService;
use App\Services\Segments\SegmentMatchingService;
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
}
