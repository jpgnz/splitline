<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Garmin\GarminSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncGarminJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $syncRunId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GarminSyncService $syncService): void
    {
        $syncRun = SyncRun::query()->find($this->syncRunId);

        if ($syncRun === null) {
            return;
        }

        $syncService->syncIncremental(existingRun: $syncRun);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $syncRun = SyncRun::query()->find($this->syncRunId);

        if ($syncRun === null || in_array($syncRun->status, ['completed', 'failed'], true)) {
            return;
        }

        $syncRun->update([
            'status' => 'failed',
            'finished_at' => CarbonImmutable::now(),
            'error_message' => $exception?->getMessage() ?? 'Garmin sync job failed.',
        ]);
    }
}
