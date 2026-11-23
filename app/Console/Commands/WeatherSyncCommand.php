<?php

namespace App\Console\Commands;

use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\SyncRun;
use App\Services\Weather\ActivityWeatherSyncService;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Throwable;

class WeatherSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'weather:sync
        {--limit= : Maximum number of outdoor activities to sync}
        {--activity= : Sync a single activity by local ID or Garmin activity ID}
        {--force : Re-fetch weather even when already cached}
        {--upgrade-only : Only upgrade activities currently stored in start_point mode that now have streams}
        {--queue : Dispatch SyncActivityWeatherJob to the queue instead of running synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and store historical Open-Meteo weather telemetry for outdoor activities';

    /**
     * Execute the console command.
     */
    public function handle(ActivityWeatherSyncService $weatherSyncService, OpenMeteoClient $client): int
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
        $activityOption = is_string($rawActivity) && trim($rawActivity) !== '' ? trim($rawActivity) : null;

        if ($activityOption !== null && (! ctype_digit($activityOption) || (int) $activityOption <= 0)) {
            $this->components->error('The --activity option must be a positive integer ID.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $upgradeOnly = (bool) $this->option('upgrade-only');
        $queue = (bool) $this->option('queue');

        $activityIds = $this->resolveTargetActivityIds($activityOption, $limit, $force, $upgradeOnly);

        if ($activityOption !== null && $activityIds === []) {
            $this->components->error("No activity found matching ID {$activityOption}.");

            return self::FAILURE;
        }

        if ($activityIds === []) {
            $this->components->info('No activities require weather synchronization.');

            return self::SUCCESS;
        }

        if ($queue) {
            foreach ($activityIds as $activityId) {
                SyncActivityWeatherJob::dispatch($activityId, force: $force);
            }

            $count = count($activityIds);
            $this->components->info("Requested weather sync for {$count} activity(ies) on the weather queue.");

            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $syncRun = SyncRun::query()->create([
            'type' => 'weather',
            'status' => 'running',
            'started_at' => CarbonImmutable::now(),
            'items_synced' => 0,
        ]);

        $total = count($activityIds);
        $itemsSynced = 0;
        $skipped = 0;
        /** @var list<string> $errors */
        $errors = [];

        $this->components->info("Syncing weather for {$total} activity(ies)...");

        $client->onRateLimitWait(function (float $waitSeconds, string $reason): void {
            $seconds = (int) ceil($waitSeconds);
            $this->components->twoColumnDetail("Rate limited ({$reason})", "waiting {$seconds}s");
        });

        try {
            foreach ($activityIds as $index => $activityId) {
                $position = $index + 1;
                $activity = Activity::query()->find($activityId);

                if ($activity === null) {
                    continue;
                }

                try {
                    $weather = $weatherSyncService->sync($activity, force: $force);

                    if ($weather === null) {
                        $skipped++;
                        $this->components->twoColumnDetail(
                            "[{$position}/{$total}] #{$activity->id} {$activity->name}",
                            'skipped',
                        );
                    } else {
                        $itemsSynced++;
                        $this->components->twoColumnDetail(
                            "[{$position}/{$total}] #{$activity->id} {$activity->name}",
                            "{$weather->condition} ({$weather->sampling_mode})",
                        );
                    }
                } catch (Throwable $exception) {
                    $message = "Activity #{$activity->id} ({$activity->garmin_activity_id}): {$exception->getMessage()}";
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
        } finally {
            $client->onRateLimitWait(null);
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
            $this->components->error('Weather sync failed: '.$errors[0]);

            return self::FAILURE;
        }

        $this->components->info('Weather sync completed.');
        $this->components->twoColumnDetail('Items Synced', (string) $itemsSynced);
        $this->components->twoColumnDetail('Skipped', (string) $skipped);
        $this->components->twoColumnDetail('Errors', (string) count($errors));
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveTargetActivityIds(
        ?string $activityOption,
        ?int $limit,
        bool $force,
        bool $upgradeOnly,
    ): array {
        if ($activityOption !== null) {
            $targetId = (int) $activityOption;

            return array_values(array_map(
                'intval',
                Activity::query()
                    ->where('id', $targetId)
                    ->orWhere('garmin_activity_id', $targetId)
                    ->limit(1)
                    ->pluck('id')
                    ->all(),
            ));
        }

        $query = Activity::query()
            ->where('is_indoor', false)
            ->where(function (Builder $coordinateQuery): void {
                $coordinateQuery
                    ->where(function (Builder $startCoords): void {
                        $startCoords
                            ->whereNotNull('start_lat')
                            ->whereNotNull('start_lng');
                    })
                    ->orWhereExists(function (QueryBuilder $streamSub): void {
                        $streamSub
                            ->selectRaw('1')
                            ->from('activity_streams')
                            ->whereColumn('activity_streams.activity_id', 'activities.id');
                    });
            });

        if ($upgradeOnly) {
            $query
                ->whereExists(function (QueryBuilder $weatherSub): void {
                    $weatherSub
                        ->selectRaw('1')
                        ->from('activity_weathers')
                        ->whereColumn('activity_weathers.activity_id', 'activities.id')
                        ->where('activity_weathers.sampling_mode', 'start_point');
                })
                ->whereExists(function (QueryBuilder $streamSub): void {
                    $streamSub
                        ->selectRaw('1')
                        ->from('activity_streams')
                        ->whereColumn('activity_streams.activity_id', 'activities.id');
                });
        } elseif (! $force) {
            $query->whereNotExists(function (QueryBuilder $weatherSub): void {
                $weatherSub
                    ->selectRaw('1')
                    ->from('activity_weathers')
                    ->whereColumn('activity_weathers.activity_id', 'activities.id');
            });
        }

        $query
            ->orderByDesc('started_at_local')
            ->orderByDesc('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values(array_map('intval', $query->pluck('id')->all()));
    }
}
