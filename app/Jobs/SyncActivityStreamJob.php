<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Garmin\ActivityStreamSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Splitline\Core\Exceptions\ArchiveUnavailableException;
use Splitline\Core\Exceptions\RateLimitedException;

class SyncActivityStreamJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'streams';

    public int $tries = 5;

    public int $uniqueFor = 14400;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $activityId,
        public readonly bool $forceDownload = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "{$this->activityId}:".($this->forceDownload ? '1' : '0');
    }

    /**
     * Execute the job.
     */
    public function handle(ActivityStreamSyncService $service): void
    {
        $activity = Activity::query()->find($this->activityId);

        if ($activity === null) {
            return;
        }

        try {
            $service->sync($activity, forceDownload: $this->forceDownload);
        } catch (RateLimitedException $exception) {
            if ($this->job !== null) {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            throw $exception;
        } catch (ArchiveUnavailableException) {
            if ($activity->started_at_utc->lessThanOrEqualTo(CarbonImmutable::now('UTC')->subDay())) {
                $activity->update([
                    'streams_unavailable_at' => CarbonImmutable::now(),
                ]);
            }
        }
    }
}
