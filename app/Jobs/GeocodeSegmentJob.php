<?php

namespace App\Jobs;

use App\Models\Segment;
use App\Services\Geocoding\Exceptions\GeocodingUnavailableException;
use App\Services\Geocoding\ReverseGeocodingService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeSegmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = GeocodeRouteClusterJob::QUEUE;

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
        public readonly int $segmentId,
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
        return "segment:{$this->segmentId}:".($this->force ? '1' : '0');
    }

    /**
     * Execute the job.
     */
    public function handle(ReverseGeocodingService $service): void
    {
        $segment = Segment::query()->find($this->segmentId);

        if ($segment === null) {
            return;
        }

        try {
            $service->enrichSegment($segment, force: $this->force);
        } catch (GeocodingUnavailableException $exception) {
            if ($this->job !== null) {
                $this->release($exception->retryAfterSeconds);

                return;
            }

            throw $exception;
        }
    }
}
