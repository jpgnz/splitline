<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Weather\ActivityWeatherSyncService;
use App\Services\Weather\OpenMeteoException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncActivityWeatherJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public readonly int $activityId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('weather');
    }

    /**
     * Execute the job.
     */
    public function handle(ActivityWeatherSyncService $service): void
    {
        $activity = Activity::query()->find($this->activityId);

        if ($activity === null) {
            return;
        }

        try {
            $service->sync($activity, force: $this->force);
        } catch (OpenMeteoException $exception) {
            if ($exception->isRateLimited() && $this->job !== null) {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            throw $exception;
        }
    }
}
