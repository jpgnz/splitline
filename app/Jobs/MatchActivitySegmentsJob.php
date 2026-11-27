<?php

namespace App\Jobs;

use App\Models\Activity;
use App\Services\Segments\SegmentMatchingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchActivitySegmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = SyncActivityStreamJob::QUEUE;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 60];

    public function __construct(
        public readonly int $activityId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "segments-activity:{$this->activityId}";
    }

    /**
     * Execute the job.
     */
    public function handle(SegmentMatchingService $matcher): void
    {
        $activity = Activity::query()->find($this->activityId);

        if ($activity === null) {
            return;
        }

        $matcher->scanActivity($activity);
    }
}
