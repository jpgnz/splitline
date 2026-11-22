<?php

namespace App\Console\Commands;

use App\Services\Garmin\GarminSyncService;
use Illuminate\Console\Command;
use Throwable;

class GarminSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'garmin:sync
        {--days=7 : Number of recent days to sync incrementally}
        {--backfill : Run full all-time resumable history backfill}
        {--from= : Optional YYYY-MM-DD date to resume backfill from}
        {--until= : Optional earliest YYYY-MM-DD date to stop backfill at}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync health telemetry and activities from the Garmin Connect Personal API';

    /**
     * Execute the console command.
     */
    public function handle(GarminSyncService $syncService): int
    {
        $isBackfill = (bool) $this->option('backfill');
        $rawFrom = $this->option('from');
        $fromDate = is_string($rawFrom) && trim($rawFrom) !== '' ? trim($rawFrom) : null;

        if ($fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1) {
            $this->components->error('The --from option must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $rawUntil = $this->option('until');
        $untilDate = is_string($rawUntil) && trim($rawUntil) !== '' ? trim($rawUntil) : null;

        if ($untilDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $untilDate) !== 1) {
            $this->components->error('The --until option must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $rawDays = $this->option('days');
        $days = is_numeric($rawDays) ? (int) $rawDays : 7;

        if ($days < 1) {
            $this->components->error('The --days option must be a positive integer.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        $this->components->info(
            $isBackfill
                ? 'Starting Garmin all-time history backfill...'
                : "Starting Garmin incremental sync ({$days} days)...",
        );

        $onProgress = function (string $message): void {
            $this->components->twoColumnDetail($message, 'running');
        };

        try {
            $syncRun = $isBackfill
                ? $syncService->syncBackfill($fromDate, $onProgress, $untilDate)
                : $syncService->syncIncremental($days, $onProgress);
        } catch (Throwable $exception) {
            $this->components->error('Garmin sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        $this->components->info('Garmin sync completed successfully.');
        $this->components->twoColumnDetail('Type', (string) $syncRun->type);
        $this->components->twoColumnDetail('Status', (string) $syncRun->status);
        $this->components->twoColumnDetail('Items Synced', (string) $syncRun->items_synced);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }
}
