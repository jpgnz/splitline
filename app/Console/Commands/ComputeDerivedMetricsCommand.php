<?php

namespace App\Console\Commands;

use App\Jobs\ComputeDerivedMetricsJob;
use App\Models\Activity;
use App\Services\Activities\ActivityDerivedMetricsService;
use Illuminate\Console\Command;

class ComputeDerivedMetricsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activities:compute-derived-metrics
        {--activity= : Compute derived metrics for a single activity ID}
        {--force : Recompute all existing derived metrics}
        {--queue : Queue backfill onto the background streams queue instead of running inline}
        {--chunk=100 : Number of streamed activities to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute aerobic decoupling, efficiency factor, pacing, HR split, and route geometry metrics';

    /**
     * Execute the console command.
     */
    public function handle(ActivityDerivedMetricsService $service): int
    {
        $rawChunk = $this->option('chunk');

        if (! is_numeric($rawChunk) || (int) $rawChunk < 1 || (string) (int) $rawChunk !== trim((string) $rawChunk)) {
            $this->components->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        $chunkSize = (int) $rawChunk;
        $force = (bool) $this->option('force');
        $queue = (bool) $this->option('queue');
        $rawActivity = $this->option('activity');

        if ($rawActivity !== null && $rawActivity !== '') {
            if (! is_numeric($rawActivity) || (int) $rawActivity < 1 || (string) (int) $rawActivity !== trim((string) $rawActivity)) {
                $this->components->error('The --activity option must be a positive integer.');

                return self::FAILURE;
            }

            $activity = Activity::query()->with('stream')->find((int) $rawActivity);

            if ($activity === null) {
                $this->components->error("Activity #{$rawActivity} not found.");

                return self::FAILURE;
            }

            if ($activity->stream === null) {
                $this->components->warn("Activity #{$activity->id} has no synced FIT stream yet.");

                return self::SUCCESS;
            }

            $metric = $service->computeAndStore($activity, $activity->stream);

            $this->components->info("Computed derived metrics for activity #{$activity->id} ({$activity->name}).");
            $this->components->twoColumnDetail(
                'Efficiency Factor',
                $metric->efficiency_factor !== null ? number_format($metric->efficiency_factor, 3) : '—',
            );
            $this->components->twoColumnDetail(
                'Aerobic Decoupling',
                $metric->decoupling_pct !== null
                    ? sprintf('%.2f%% (%s)', $metric->decoupling_pct, (string) $metric->decoupling_rating)
                    : '—',
            );
            $this->components->twoColumnDetail(
                'Pacing Strategy',
                $metric->pacing_strategy ?? '—',
            );

            return self::SUCCESS;
        }

        if ($queue) {
            if (! $force) {
                $pending = $service->missingCount();

                if ($pending === 0) {
                    $this->components->info('All streamed activities already have derived metrics. Nothing to queue.');

                    return self::SUCCESS;
                }

                ComputeDerivedMetricsJob::dispatch(force: false, chunkSize: $chunkSize);
                $this->components->info("Queued derived metrics backfill for {$pending} activity(ies).");

                return self::SUCCESS;
            }

            ComputeDerivedMetricsJob::dispatch(force: true, chunkSize: $chunkSize);
            $this->components->info('Queued full derived metrics recomputation.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        $this->components->info(
            $force
                ? "Recomputing derived metrics for all streamed activities (chunk size: {$chunkSize})..."
                : "Computing derived metrics for missing streamed activities (chunk size: {$chunkSize})...",
        );

        $processed = $service->backfill(force: $force, chunkSize: $chunkSize);
        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        $this->components->info($force ? 'Derived metrics recomputation completed.' : 'Derived metrics backfill completed.');
        $this->components->twoColumnDetail('Activities Processed', (string) $processed);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }
}
