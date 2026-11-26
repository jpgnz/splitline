<?php

namespace App\Jobs;

use App\Services\Routes\RouteMatchingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MatchUnfingerprintedRoutesJob implements ShouldBeUnique, ShouldQueue
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
        public readonly bool $rebuild = false,
        public readonly int $chunkSize = 100,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return 'match-routes:'.($this->rebuild ? 'rebuild' : 'scan');
    }

    /**
     * Execute the job.
     */
    public function handle(RouteMatchingService $matcher): void
    {
        if ($this->rebuild) {
            $matcher->rebuildAll($this->chunkSize);

            return;
        }

        $matcher->scanUnfingerprinted($this->chunkSize);
    }
}
