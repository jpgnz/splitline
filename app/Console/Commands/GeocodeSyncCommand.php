<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\GeocodeRouteClusterJob;
use App\Models\Activity;
use App\Models\RouteCluster;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitedException;
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
        {--limit=50 : Maximum number of route clusters and activities to process}
        {--force : Re-geocode items even if they were already geocoded}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reverse-geocode matched routes and outdoor activities missing place names via Nominatim';

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
        $activityIds = $remaining > 0
            ? $this->resolveTargetActivityIds($remaining, $force)
            : [];

        $total = count($clusterIds) + count($activityIds);

        if ($total === 0) {
            $this->components->info('All matched routes and outdoor activities already have place names. Nothing to do.');

            return self::SUCCESS;
        }

        if ($queue) {
            foreach ($clusterIds as $clusterId) {
                GeocodeRouteClusterJob::dispatch($clusterId, force: $force);
            }

            foreach ($activityIds as $activityId) {
                GeocodeActivityLocationJob::dispatch($activityId, force: $force);
            }

            $clusterCount = count($clusterIds);
            $activityCount = count($activityIds);
            $this->components->info("Queued background geocoding for {$clusterCount} route cluster(s) and {$activityCount} activity(ies).");

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $clustersEnriched = 0;
        $activitiesEnriched = 0;
        $skipped = 0;
        $errors = 0;
        $rateLimited = false;

        $this->components->info(
            'Geocoding '.count($clusterIds).' route cluster(s) and '.count($activityIds).' activity(ies) via '.$client->providerHost().'...',
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
            } catch (GeocodingRateLimitedException $exception) {
                $rateLimited = true;
                $this->components->warn($exception->getMessage());
                break;
            } catch (Throwable $exception) {
                $errors++;
                $this->components->twoColumnDetail("Route #{$cluster->id}", "<fg=red>{$exception->getMessage()}</>");
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
                } catch (GeocodingRateLimitedException $exception) {
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

        $this->components->info($rateLimited ? 'Geocoding paused due to Nominatim rate limit.' : 'Geocoding sync completed.');
        $this->components->twoColumnDetail('Routes Enriched', (string) $clustersEnriched);
        $this->components->twoColumnDetail('Activities Enriched', (string) $activitiesEnriched);
        $this->components->twoColumnDetail('Skipped', (string) $skipped);
        $this->components->twoColumnDetail('Errors', (string) $errors);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return $rateLimited && ($clustersEnriched + $activitiesEnriched) === 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTargetClusterIds(int $limit, bool $force): array
    {
        $query = RouteCluster::query()
            ->matched()
            ->orderByDesc('activity_count')
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
        $query = Activity::query()
            ->where('is_indoor', false)
            ->whereNotNull('start_lat')
            ->whereNotNull('start_lng')
            ->orderByDesc('started_at_local')
            ->orderByDesc('id');

        if (! $force) {
            $query->where(function (Builder $sub): void {
                $sub
                    ->whereNull('location_name')
                    ->orWhere('location_name', '');
            });
        }

        return array_values(array_map(
            'intval',
            $query->limit($limit)->pluck('id')->all(),
        ));
    }
}
