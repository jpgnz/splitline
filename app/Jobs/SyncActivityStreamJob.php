<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Services\Garmin\GarminApiException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncActivityStreamJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $activityId,
        public readonly bool $forceDownload = false,
    ) {}

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
        } catch (GarminApiException $exception) {
            if ($exception->isRateLimited() && $this->job !== null) {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            throw $exception;
        }
    }
}
