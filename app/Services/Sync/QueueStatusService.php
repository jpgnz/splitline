<?php

namespace App\Services\Sync;

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Services\Routes\RouteMatchingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspects queued background enrichment jobs and remaining unenriched activity counts.
 *
 * @phpstan-type EnrichmentQueueSummary array{
 *     jobs: array{default: int, streams: int, weather: int, total: int},
 *     pending: array{streams: int, weather: int, routes: int},
 *     has_active_work: bool
 * }
 */
class QueueStatusService
{
    public function __construct(
        private readonly RouteMatchingService $routeMatchingService = new RouteMatchingService,
    ) {}

    /**
     * Build the combined background queue and pending enrichment summary.
     *
     * @return EnrichmentQueueSummary
     */
    public function summary(): array
    {
        $jobs = $this->queuedJobCounts();
        $pending = $this->pendingEnrichmentCounts();

        return [
            'jobs' => $jobs,
            'pending' => $pending,
            'has_active_work' => $jobs['total'] > 0,
        ];
    }

    /**
     * @return array{default: int, streams: int, weather: int, total: int}
     */
    private function queuedJobCounts(): array
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return [
                'default' => 0,
                'streams' => 0,
                'weather' => 0,
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

        foreach ($rows as $row) {
            $queue = is_string($row->queue ?? null) ? $row->queue : 'default';
            $count = (int) ($row->aggregate ?? 0);

            if ($queue === SyncActivityStreamJob::QUEUE) {
                $streams += $count;
            } elseif ($queue === SyncActivityWeatherJob::QUEUE) {
                $weather += $count;
            } else {
                $default += $count;
            }
        }

        return [
            'default' => $default,
            'streams' => $streams,
            'weather' => $weather,
            'total' => $default + $streams + $weather,
        ];
    }

    /**
     * @return array{streams: int, weather: int, routes: int}
     */
    private function pendingEnrichmentCounts(): array
    {
        if (! Schema::hasTable('activities')) {
            return [
                'streams' => 0,
                'weather' => 0,
                'routes' => 0,
            ];
        }

        $awaitingStreams = Activity::query()
            ->where('is_manual', false)
            ->awaitingStreams()
            ->count();

        $hasStreamsTable = Schema::hasTable('activity_streams');
        $missingWeather = 0;

        if (Schema::hasTable('activity_weathers')) {
            $weatherQuery = Activity::query()
                ->where('is_indoor', false)
                ->where(function (Builder $coordQuery) use ($hasStreamsTable): void {
                    $coordQuery->where(function (Builder $startCoords): void {
                        $startCoords
                            ->whereNotNull('start_lat')
                            ->whereNotNull('start_lng');
                    });

                    if ($hasStreamsTable) {
                        $coordQuery->orWhereExists(function (QueryBuilder $streamSub): void {
                            $streamSub
                                ->selectRaw('1')
                                ->from('activity_streams')
                                ->whereColumn('activity_streams.activity_id', 'activities.id');
                        });
                    }
                })
                ->where(function (Builder $missingOrUpgradeQuery) use ($hasStreamsTable): void {
                    $missingOrUpgradeQuery->whereNotExists(function (QueryBuilder $weatherSub): void {
                        $weatherSub
                            ->selectRaw('1')
                            ->from('activity_weathers')
                            ->whereColumn('activity_weathers.activity_id', 'activities.id');
                    });

                    if ($hasStreamsTable) {
                        $missingOrUpgradeQuery->orWhere(function (Builder $upgradeQuery): void {
                            $upgradeQuery
                                ->whereExists(function (QueryBuilder $weatherSub): void {
                                    $weatherSub
                                        ->selectRaw('1')
                                        ->from('activity_weathers')
                                        ->whereColumn('activity_weathers.activity_id', 'activities.id')
                                        ->where('activity_weathers.sampling_mode', 'start_point');
                                })
                                ->whereExists(function (QueryBuilder $streamSub): void {
                                    $streamSub
                                        ->selectRaw('1')
                                        ->from('activity_streams')
                                        ->whereColumn('activity_streams.activity_id', 'activities.id');
                                });
                        });
                    }
                });

            $missingWeather = $weatherQuery->count();
        }

        $unfingerprintedRoutes = ($hasStreamsTable && Schema::hasTable('activity_route_fingerprints'))
            ? $this->routeMatchingService->unfingerprintedCount()
            : 0;

        return [
            'streams' => $awaitingStreams,
            'weather' => $missingWeather,
            'routes' => $unfingerprintedRoutes,
        ];
    }
}
