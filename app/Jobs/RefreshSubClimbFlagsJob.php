<?php

namespace App\Jobs;

use App\Services\Segments\SegmentMatchingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Releases its unique lock when processing starts so refreshes requested mid-run are not dropped.
 */
class RefreshSubClimbFlagsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
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
        public readonly string $sportCategory,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "segments-sub-climbs:{$this->sportCategory}";
    }

    /**
     * Execute the job.
     */
    public function handle(SegmentMatchingService $matcher): void
    {
        $matcher->refreshSubClimbFlagsForSport($this->sportCategory);
    }
}
