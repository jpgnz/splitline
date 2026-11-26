<?php

namespace App\Jobs;

use App\Models\RouteCluster;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitedException;
use App\Services\Geocoding\ReverseGeocodingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeRouteClusterJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'geocode';

    public int $tries = 5;

    public int $uniqueFor = 14400;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 30, 60, 120];

    public function __construct(
        public readonly int $routeClusterId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "route-cluster:{$this->routeClusterId}:".($this->force ? '1' : '0');
    }

    /**
     * Execute the job.
     */
    public function handle(ReverseGeocodingService $service): void
    {
        $cluster = RouteCluster::query()->find($this->routeClusterId);

        if ($cluster === null) {
            return;
        }

        try {
            $service->enrichRouteCluster($cluster, force: $this->force);
        } catch (GeocodingRateLimitedException $exception) {
            if ($this->job !== null) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw $exception;
        }
    }
}
