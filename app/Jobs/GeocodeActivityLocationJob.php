<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitedException;
use App\Services\Geocoding\ReverseGeocodingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeActivityLocationJob implements ShouldBeUnique, ShouldQueue
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
        public readonly int $activityId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "activity-location:{$this->activityId}:".($this->force ? '1' : '0');
    }

    /**
     * Execute the job.
     */
    public function handle(ReverseGeocodingService $service): void
    {
        $activity = Activity::query()->find($this->activityId);

        if ($activity === null) {
            return;
        }

        try {
            $service->enrichActivityLocation($activity, force: $this->force);
        } catch (GeocodingRateLimitedException $exception) {
            if ($this->job !== null) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw $exception;
        }
    }
}
