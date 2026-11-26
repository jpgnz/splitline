<?php

namespace App\Console\Commands;

use App\Jobs\MatchUnfingerprintedRoutesJob;
use App\Services\Routes\RouteMatchingService;
use Illuminate\Console\Command;

class MatchRoutesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'routes:match
        {--rebuild : Recompute all fingerprints and rebuild clusters from scratch}
        {--queue : Dispatch route matching onto the background streams queue instead of running inline}
        {--chunk=100 : Number of streamed activities to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fingerprint outdoor GNSS polylines and cluster repeated routes';

    /**
     * Execute the console command.
     */
    public function handle(RouteMatchingService $matcher): int
    {
        $rawChunk = $this->option('chunk');

        if (! is_numeric($rawChunk) || (int) $rawChunk < 1 || (string) (int) $rawChunk !== trim((string) $rawChunk)) {
            $this->components->error('The --chunk option must be a positive integer.');

            return self::FAILURE;
        }

        $chunkSize = (int) $rawChunk;
        $rebuild = (bool) $this->option('rebuild');
        $queue = (bool) $this->option('queue');

        if ($queue) {
            if (! $rebuild) {
                $pending = $matcher->unfingerprintedCount();

                if ($pending === 0) {
                    $this->components->info('All eligible outdoor activities are already fingerprinted. Nothing to queue.');

                    return self::SUCCESS;
                }

                MatchUnfingerprintedRoutesJob::dispatch(rebuild: false, chunkSize: $chunkSize);
                $this->components->info("Queued background route matching for {$pending} un-fingerprinted activity(ies).");

                return self::SUCCESS;
            }

            MatchUnfingerprintedRoutesJob::dispatch(rebuild: true, chunkSize: $chunkSize);
            $this->components->info('Queued background route cluster rebuild.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        if ($rebuild) {
            $this->components->info("Rebuilding all route fingerprints and clusters (chunk size: {$chunkSize})...");
            $result = $matcher->rebuildAll($chunkSize);
        } else {
            $this->components->info("Scanning un-fingerprinted outdoor activities (chunk size: {$chunkSize})...");
            $result = $matcher->scanUnfingerprinted($chunkSize);
        }

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        $this->components->info($rebuild ? 'Route cluster rebuild completed.' : 'Route matching scan completed.');
        $this->components->twoColumnDetail('Activities Scanned', (string) $result['scanned']);
        $this->components->twoColumnDetail('Activities Matched', (string) $result['matched']);
        $this->components->twoColumnDetail('Clusters Created', (string) $result['clusters_created']);
        $this->components->twoColumnDetail('Matched Routes (2+ attempts)', (string) $result['matched_routes']);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }
}
