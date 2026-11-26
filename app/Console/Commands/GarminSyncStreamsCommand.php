<?php

namespace App\Console\Commands;

use App\Jobs\SyncActivityStreamJob;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Services\Garmin\ActivityStreamSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Splitline\Core\Exceptions\ArchiveUnavailableException;
use Throwable;

class GarminSyncStreamsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'garmin:sync-streams
        {--limit= : Maximum number of activities to sync (defaults to all matching)}
        {--activity= : Sync a single activity by local ID, Garmin activity ID, or external ID}
        {--force : Re-sync activities that already have streams (reuses local FIT archives unless --redownload is set)}
        {--redownload : Re-download FIT archives from Garmin even when a local archive already exists}
        {--queue : Dispatch jobs to the queue instead of running synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download, cache, and parse FIT telemetry streams and Best Efforts for all non-manual activities';

    /**
     * Execute the console command.
     */
    public function handle(ActivityStreamSyncService $streamSyncService): int
    {
        $rawLimit = $this->option('limit');
        $limit = null;

        if ($rawLimit !== null && $rawLimit !== '') {
            if (! is_numeric($rawLimit) || (int) $rawLimit < 1 || (string) (int) $rawLimit !== trim((string) $rawLimit)) {
                $this->components->error('The --limit option must be a positive integer.');

                return self::FAILURE;
            }

            $limit = (int) $rawLimit;
        }

        $rawActivity = $this->option('activity');
        $activityOption = null;

        if ($rawActivity !== null) {
            $trimmedActivity = trim($rawActivity);

            if ($trimmedActivity === '') {
                $this->components->error('The --activity option must be a non-empty activity ID or external ID.');

                return self::FAILURE;
            }

            $activityOption = $trimmedActivity;
        }

        $force = (bool) $this->option('force');
        $redownload = (bool) $this->option('redownload');
        $queue = (bool) $this->option('queue');

        $activityIds = $this->resolveTargetActivityIds($activityOption, $limit, $force);

        if ($activityOption !== null && $activityIds === []) {
            $this->components->error("No activity found matching ID {$activityOption}.");

            return self::FAILURE;
        }

        if ($activityIds === []) {
            $this->components->info('No activities require stream synchronization.');

            return self::SUCCESS;
        }

        if ($queue) {
            foreach ($activityIds as $activityId) {
                SyncActivityStreamJob::dispatch($activityId, forceDownload: $redownload);
            }

            $count = count($activityIds);
            $this->components->info("Requested stream sync for {$count} activity(ies) on the streams queue.");

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $syncRun = SyncRun::query()->create([
            'type' => 'streams',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'items_synced' => 0,
        ]);

        $total = count($activityIds);
        $itemsSynced = 0;
        $cachedHits = 0;
        /** @var list<string> $errors */
        $errors = [];

        $this->components->info("Syncing FIT streams for {$total} activity(ies)...");

        $onRateLimitWait = function (float $waitSeconds, string $reason): void {
            $seconds = (int) ceil($waitSeconds);
            $this->components->twoColumnDetail("Rate limited ({$reason})", "waiting {$seconds}s");
        };

        foreach ($activityIds as $index => $activityId) {
            $position = $index + 1;
            $activity = Activity::query()->find($activityId);

            if ($activity === null) {
                continue;
            }

            $usedCache = ! $redownload && $streamSyncService->hasLocalArchive($activity);

            try {
                $streamSyncService->sync($activity, $redownload, $onRateLimitWait);
                $itemsSynced++;

                if ($usedCache) {
                    $cachedHits++;
                }

                $this->components->twoColumnDetail(
                    "[{$position}/{$total}] #{$activity->id} {$activity->name}",
                    $usedCache ? 'synced (local)' : 'synced',
                );
            } catch (Throwable $exception) {
                if (
                    $exception instanceof ArchiveUnavailableException
                    && $activity->started_at_utc->lessThanOrEqualTo(CarbonImmutable::now('UTC')->subDay())
                ) {
                    $activity->update([
                        'streams_unavailable_at' => CarbonImmutable::now(),
                    ]);
                }

                $identifier = $this->formatActivityIdentifier($activity);
                $message = "Activity #{$activity->id} ({$identifier}): {$exception->getMessage()}";
                $errors[] = $message;
                $this->components->twoColumnDetail(
                    "[{$position}/{$total}] #{$activity->id} {$activity->name}",
                    '<fg=red>failed</>',
                );
            }

            unset($activity);

            if ($position % 25 === 0) {
                gc_collect_cycles();
            }
        }

        $status = $errors !== [] && $itemsSynced === 0 ? 'failed' : 'completed';

        $syncRun->update([
            'status' => $status,
            'finished_at' => CarbonImmutable::now(),
            'items_synced' => $itemsSynced,
            'error_message' => $errors !== [] ? implode("\n", $errors) : null,
        ]);

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        if ($status === 'failed') {
            $this->components->error('Garmin stream sync failed: '.$errors[0]);

            return self::FAILURE;
        }

        $this->components->info('Garmin stream sync completed.');
        $this->components->twoColumnDetail('Items Synced', (string) $itemsSynced);
        $this->components->twoColumnDetail('From Local Cache', (string) $cachedHits);
        $this->components->twoColumnDetail('Errors', (string) count($errors));
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTargetActivityIds(?string $activityOption, ?int $limit, bool $force): array
    {
        if ($activityOption !== null) {
            $query = Activity::query();

            if (ctype_digit($activityOption) && (int) $activityOption > 0) {
                $targetId = (int) $activityOption;
                $query
                    ->where('id', $targetId)
                    ->orWhere('garmin_activity_id', $targetId)
                    ->orWhere('external_id', $activityOption);
            } else {
                $query->where('external_id', $activityOption);
            }

            return array_values(array_map(
                'intval',
                $query->limit(1)->pluck('id')->all(),
            ));
        }

        $query = Activity::query()
            ->where('is_manual', false);

        if (! $force) {
            $query->awaitingStreams();
        }

        $query
            ->orderByDesc('started_at_local')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }

    private function formatActivityIdentifier(Activity $activity): string
    {
        $externalId = $activity->getAttribute('external_id');
        $provider = $activity->getAttribute('provider');

        if (is_string($externalId) && $externalId !== '') {
            $providerPrefix = is_string($provider) && $provider !== '' ? $provider : 'garmin_personal';

            return "{$providerPrefix}:{$externalId}";
        }

        $garminActivityId = $activity->getAttribute('garmin_activity_id');

        if (is_int($garminActivityId) || (is_string($garminActivityId) && $garminActivityId !== '')) {
            return (string) $garminActivityId;
        }

        return (string) $activity->id;
    }
}
