<?php

namespace App\Jobs;

use App\Services\Routes\RouteMatchingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class MatchUnfingerprintedRoutesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string QUEUE = SyncActivityStreamJob::QUEUE;

    public const string OVERLAP_KEY = 'match-routes';

    public int $tries = 10;

    public int $maxExceptions = 3;

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
     * Scans and rebuilds share one overlap lock so a rebuild never deletes clusters mid-scan (and vice versa).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::OVERLAP_KEY))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
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
