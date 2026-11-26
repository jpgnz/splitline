<?php

namespace App\Console\Commands;

use App\Jobs\ScanActivitySegmentsJob;
use App\Services\Segments\SegmentMatchingService;
use Illuminate\Console\Command;

class ScanSegmentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'segments:scan
        {--rebuild : Recompute all auto-detected climbs and segment efforts from scratch}
        {--queue : Dispatch segment scanning onto the background streams queue instead of running inline}
        {--chunk=50 : Number of streamed activities to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect climbs and match personal segments across outdoor activity streams';

    /**
     * Execute the console command.
     */
    public function handle(SegmentMatchingService $matcher): int
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
                $pending = $matcher->unscannedCount();

                if ($pending === 0) {
                    $this->components->info('All eligible outdoor activities have already been scanned for segments. Nothing to queue.');

                    return self::SUCCESS;
                }

                ScanActivitySegmentsJob::dispatch(rebuild: false, chunkSize: $chunkSize);
                $this->components->info("Queued background segment scanning for {$pending} unscanned activity(ies).");

                return self::SUCCESS;
            }

            ScanActivitySegmentsJob::dispatch(rebuild: true, chunkSize: $chunkSize);
            $this->components->info('Queued background segment & climb rebuild.');

            return self::SUCCESS;
        }

        $startedAt = microtime(true);

        if ($rebuild) {
            $this->components->info("Rebuilding all auto-detected climbs and segment efforts (chunk size: {$chunkSize})...");
            $result = $matcher->rebuildAll($chunkSize);
        } else {
            $this->components->info("Scanning unscanned outdoor activities for climbs & segments (chunk size: {$chunkSize})...");
            $result = $matcher->scanUnscanned($chunkSize);
        }

        $elapsedSeconds = round(microtime(true) - $startedAt, 2);

        $this->components->info($rebuild ? 'Segment & climb rebuild completed.' : 'Segment & climb scan completed.');
        $this->components->twoColumnDetail('Activities Scanned', (string) $result['scanned']);
        $this->components->twoColumnDetail('Climbs Created', (string) $result['climbs_created']);
        $this->components->twoColumnDetail('Efforts Recorded', (string) $result['efforts_recorded']);
        $this->components->twoColumnDetail('Total Segments', (string) $result['segments_total']);
        $this->components->twoColumnDetail('Duration', "{$elapsedSeconds}s");

        return self::SUCCESS;
    }
}
