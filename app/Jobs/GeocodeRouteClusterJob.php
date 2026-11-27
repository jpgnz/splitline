<?php

namespace App\Jobs;

use App\Models\RouteCluster;
use App\Services\Geocoding\Exceptions\GeocodingUnavailableException;
use App\Services\Geocoding\ReverseGeocodingService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeRouteClusterJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'geocode';

    /**
     * Fail after this many unhandled exceptions; provider cooldown releases do not count.
     */
    public int $maxExceptions = 3;

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

    /**
     * Allow provider cooldown releases to keep retrying for a day instead of exhausting a fixed attempt count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
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
        } catch (GeocodingUnavailableException $exception) {
            if ($this->job !== null) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw $exception;
        }
    }
}
