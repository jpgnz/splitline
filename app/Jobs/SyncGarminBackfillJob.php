<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Garmin\GarminSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncGarminBackfillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $syncRunId,
        public readonly ?string $untilDate = null,
        public readonly bool $includeInitialSetup = true,
        public readonly int $consecutiveEmptyWindows = 0,
        public readonly int $consecutiveEmptyFitnessAgeWindows = 0,
        public readonly int $windowsPerChunk = 6,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GarminSyncService $syncService): void
    {
        $syncRun = SyncRun::query()->find($this->syncRunId);

        if ($syncRun === null || ! in_array($syncRun->status, ['running', 'queued'], true)) {
            return;
        }

        $effectiveUntilDate = $this->untilDate ?? $syncRun->until_date;
        $shouldRunInitialSetup = $this->includeInitialSetup && ! $syncRun->initial_setup_completed;

        $result = $syncService->syncBackfillChunk(
            syncRun: $syncRun,
            maxWindows: $this->windowsPerChunk,
            untilDate: $effectiveUntilDate,
            includeInitialSetup: $shouldRunInitialSetup,
            consecutiveEmptyWindows: $this->consecutiveEmptyWindows,
            consecutiveEmptyFitnessAgeWindows: $this->consecutiveEmptyFitnessAgeWindows,
        );

        if (! $result['completed']) {
            self::dispatch(
                syncRunId: $syncRun->id,
                untilDate: $effectiveUntilDate,
                includeInitialSetup: false,
                consecutiveEmptyWindows: $result['consecutiveEmptyWindows'],
                consecutiveEmptyFitnessAgeWindows: $result['consecutiveEmptyFitnessAgeWindows'],
                windowsPerChunk: $this->windowsPerChunk,
            );
        }
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
            'error_message' => $exception?->getMessage() ?? 'Garmin backfill job failed.',
        ]);
    }
}
