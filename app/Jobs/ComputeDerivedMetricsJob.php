<?php

namespace App\Jobs;

use App\Services\Activities\ActivityDerivedMetricsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ComputeDerivedMetricsJob implements ShouldBeUnique, ShouldQueue
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
        public readonly bool $force = false,
        public readonly int $chunkSize = 25,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'compute-derived-metrics:'.($this->force ? 'force' : 'missing');
    }

    /**
     * Execute the job.
     */
    public function handle(ActivityDerivedMetricsService $service): void
    {
        $service->backfill(force: $this->force, chunkSize: $this->chunkSize);
    }
}
