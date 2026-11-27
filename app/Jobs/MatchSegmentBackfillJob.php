<?php

namespace App\Jobs;

use App\Models\Segment;
use App\Services\Segments\SegmentMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchSegmentBackfillJob implements ShouldBeUnique, ShouldQueue
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
        public readonly int $segmentId,
        public readonly int $chunkSize = 100,
        public readonly ?int $excludeActivityId = null,
        public readonly ?CarbonImmutable $scannedBefore = null,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return "segment-backfill:{$this->segmentId}";
    }

    /**
     * Execute the job.
     */
    public function handle(SegmentMatchingService $matcher): void
    {
        $segment = Segment::query()->find($this->segmentId);

        if ($segment === null) {
            return;
        }

        $matcher->matchSegmentAgainstHistory(
            $segment,
            chunkSize: $this->chunkSize,
            excludeActivityId: $this->excludeActivityId,
            scannedBefore: $this->scannedBefore,
        );
    }
}
