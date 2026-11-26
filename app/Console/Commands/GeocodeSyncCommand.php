<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Jobs\GeocodeSegmentJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Models\Segment;
use App\Services\Geocoding\Exceptions\GeocodingUnavailableException;
use App\Services\Geocoding\NominatimClient;
use App\Services\Geocoding\ReverseGeocodingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class GeocodeSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geocode:sync
        {--queue : Dispatch geocoding jobs onto the background geocode queue instead of running inline}
        {--limit=50 : Maximum number of route clusters, segments, and activities to process}
        {--force : Re-geocode routes, segments, and previously geocoded activity locations, bypassing the place cache (provider-supplied location names are never replaced)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reverse-geocode matched routes, segments, and outdoor activities missing place names via Nominatim';

    /**
     * Execute the console command.
     */
    public function handle(ReverseGeocodingService $geocoder, NominatimClient $client): int
    {
        $rawLimit = $this->option('limit');

        if (! is_numeric($rawLimit) || (int) $rawLimit < 1 || (string) (int) $rawLimit !== trim((string) $rawLimit)) {
            $this->components->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $limit = (int) $rawLimit;
        $queue = (bool) $this->option('queue');
        $force = (bool) $this->option('force');

        if (! $client->isEnabled()) {
            $this->components->info('Nominatim geocoding is disabled (NOMINATIM_ENABLED=false). Nothing to do.');

            return self::SUCCESS;
        }

        $clusterIds = $this->resolveTargetClusterIds($limit, $force);
        $remaining = max(0, $limit - count($clusterIds));
        $segmentIds = $remaining > 0
            ? $this->resolveTargetSegmentIds($remaining, $force)
            : [];
        $remaining = max(0, $remaining - count($segmentIds));
        $activityIds = $remaining > 0
            ? $this->resolveTargetActivityIds($remaining, $force)
            : [];

        $total = count($clusterIds) + count($segmentIds) + count($activityIds);

        if ($total === 0) {
            $this->components->info('No matched routes, segments, or outdoor activities are awaiting geocoding. Nothing to do.');

            return self::SUCCESS;
        }

        if ($queue) {
            foreach ($clusterIds as $clusterId) {
                GeocodeRouteClusterJob::dispatch($clusterId, force: $force);
            }

            foreach ($segmentIds as $segmentId) {
                GeocodeSegmentJob::dispatch($segmentId, force: $force);
            }

            foreach ($activityIds as $activityId) {
                GeocodeActivityLocationJob::dispatch($activityId, force: $force);
            }

            $clusterCount = count($clusterIds);
            $segmentCount = count($segmentIds);
            $activityCount = count($activityIds);

            if ($segmentCount > 0) {
                $this->components->info("Queued background geocoding for {$clusterCount} route cluster(s), {$segmentCount} segment(s), and {$activityCount} activity(ies).");
            } else {
                $this->components->info("Queued background geocoding for {$clusterCount} route cluster(s) and {$activityCount} activity(ies).");
            }

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $clustersEnriched = 0;
        $segmentsEnriched = 0;
        $activitiesEnriched = 0;
        $skipped = 0;
        $errors = 0;
        $rateLimited = false;

        $this->components->info(
            'Geocoding '.count($clusterIds).' route cluster(s), '.count($segmentIds).' segment(s), and '.count($activityIds).' activity(ies) via '.$client->providerHost().'...',
        );

        foreach ($clusterIds as $clusterId) {
            $cluster = RouteCluster::query()->find($clusterId);

            if ($cluster === null) {
                continue;
            }

            try {
                if ($geocoder->enrichRouteCluster($cluster, force: $force)) {
                    $clustersEnriched++;
                    $this->components->twoColumnDetail(
                        "Route #{$cluster->id} {$cluster->displayName()}",
                        $cluster->place_label ?? 'geocoded',
                    );
                } else {
                    $skipped++;
                }
            } catch (GeocodingUnavailableException $exception) {
                $rateLimited = true;
                $this->components->warn($exception->getMessage());
                break;
            } catch (Throwable $exception) {
                $errors++;
                $this->components->twoColumnDetail("Route #{$cluster->id}", "<fg=red>{$exception->getMessage()}</>");
            }
        }

        if (! $rateLimited) {
            foreach ($segmentIds as $segmentId) {
                $segment = Segment::query()->find($segmentId);

                if ($segment === null) {
                    continue;
                }

                try {
                    if ($geocoder->enrichSegment($segment, force: $force)) {
                        $segmentsEnriched++;
                        $this->components->twoColumnDetail(
                            "Segment #{$segment->id} {$segment->displayName()}",
                            $segment->place_label ?? 'geocoded',
                        );
                    } else {
                        $skipped++;
                    }
                } catch (GeocodingUnavailableException $exception) {
                    $rateLimited = true;
                    $this->components->warn($exception->getMessage());
                    break;
                } catch (Throwable $exception) {
                    $errors++;
                    $this->components->twoColumnDetail("Segment #{$segment->id}", "<fg=red>{$exception->getMessage()}</>");
                }
            }
        }

        if (! $rateLimited) {
            foreach ($activityIds as $activityId) {
                $activity = Activity::query()->find($activityId);

                if ($activity === null) {
                    continue;
                }

                try {
                    if ($geocoder->enrichActivityLocation($activity, force: $force)) {
                        $activitiesEnriched++;
                        $this->components->twoColumnDetail(
                            "Activity #{$activity->id} {$activity->name}",
                            $activity->location_name ?? 'geocoded',
                        );
                    } else {
                        $skipped++;
                    }
                } catch (GeocodingUnavailableException $exception) {
                    $rateLimited = true;
                    $this->components->warn($exception->getMessage());
                    break;
                } catch (Throwable $exception) {
                    $errors++;
                    $this->components->twoColumnDetail("Activity #{$activity->id}", "<fg=red>{$exception->getMessage()}</>");
                }
            }
        }

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        $this->components->info($rateLimited ? 'Geocoding paused: Nominatim is rate limiting or unavailable.' : 'Geocoding sync completed.');
        $this->components->twoColumnDetail('Routes Enriched', (string) $clustersEnriched);
        $this->components->twoColumnDetail('Segments Enriched', (string) $segmentsEnriched);
        $this->components->twoColumnDetail('Activities Enriched', (string) $activitiesEnriched);
        $this->components->twoColumnDetail('Skipped', (string) $skipped);
        $this->components->twoColumnDetail('Errors', (string) $errors);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return $rateLimited && ($clustersEnriched + $segmentsEnriched + $activitiesEnriched) === 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTargetClusterIds(int $limit, bool $force): array
    {
        $query = $force
            ? RouteCluster::query()->matched()
            : RouteCluster::query()->awaitingGeocoding();

        return array_values(array_map(
            'intval',
            $query
                ->orderByDesc('activity_count')
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('id')
                ->all(),
        ));
    }

    /**
     * @return list<int>
     */
    private function resolveTargetSegmentIds(int $limit, bool $force): array
    {
        $query = Segment::query()
            ->orderByDesc('effort_count')
            ->orderByDesc('id');

        if (! $force) {
            $query->whereNull('geocoded_at');
        }

        return array_values(array_map(
            'intval',
            $query->limit($limit)->pluck('id')->all(),
        ));
    }

    /**
     * @return list<int>
     */
    private function resolveTargetActivityIds(int $limit, bool $force): array
    {
        $query = $force
            ? Activity::query()
                ->where('is_indoor', false)
                ->whereNotNull('start_lat')
                ->whereNotNull('start_lng')
                ->where(function (Builder $sub): void {
                    $sub
                        ->whereNull('location_name')
                        ->orWhere('location_name', '')
                        ->orWhereNotNull('location_geocoded_at');
                })
            : Activity::query()->awaitingGeocoding();

        return array_values(array_map(
            'intval',
            $query
                ->orderByDesc('started_at_local')
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('id')
                ->all(),
        ));
    }
}
