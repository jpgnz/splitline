<?php

namespace App\Console\Commands;

use App\Services\Ingestion\AutoImportService;
use Illuminate\Console\Command;

class ImportScanCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:scan
        {--dir= : Override AUTO_IMPORT_DIR}
        {--mode= : Override AUTO_IMPORT_MODE (keep|archive)}
        {--no-age-check : Skip file stability checks (minimum age and unchanged since the previous scan)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan the configured auto-import folder for new .fit and .zip activity files';

    /**
     * Execute the console command.
     */
    public function handle(AutoImportService $autoImport): int
    {
        $rawDir = $this->option('dir');
        $dirOverride = is_string($rawDir) && trim($rawDir) !== '' ? trim($rawDir) : null;

        $rawMode = $this->option('mode');
        $modeOverride = null;

        if (is_string($rawMode) && trim($rawMode) !== '') {
            $normalizedMode = strtolower(trim($rawMode));

            if ($normalizedMode !== AutoImportService::MODE_KEEP && $normalizedMode !== AutoImportService::MODE_ARCHIVE) {
                $this->components->error("Invalid --mode value '{$rawMode}'. Expected 'keep' or 'archive'.");

                return self::FAILURE;
            }

            $modeOverride = $normalizedMode;
        }

        $skipStabilityChecks = (bool) $this->option('no-age-check');

        $result = $autoImport->scan(
            directoryOverride: $dirOverride,
            modeOverride: $modeOverride,
            minFileAgeSeconds: $skipStabilityChecks ? 0 : null,
            requireStableAcrossScans: ! $skipStabilityChecks,
        );

        if (! $result['configured']) {
            $this->components->info('Auto-import directory is not configured (AUTO_IMPORT_DIR is empty). Nothing to do.');

            return self::SUCCESS;
        }

        if (! $result['directory_exists'] || ! $result['directory_readable']) {
            $this->components->error($result['last_error'] ?? "Auto-import directory is not accessible: {$result['directory']}");

            return self::FAILURE;
        }

        if ($result['busy']) {
            $this->components->warn('Another auto-import scan is already running. Skipping this run.');

            return self::SUCCESS;
        }

        foreach ($result['entries'] as $entry) {
            if ($entry['status'] === 'failed') {
                $this->components->error("{$entry['filename']}: {$entry['message']}");
            } else {
                $this->components->twoColumnDetail($entry['filename'], $entry['message']);
            }
        }

        $summary = sprintf(
            'Auto-import scan complete (%s): Created: %d, Skipped: %d, Failed: %d, Deferred: %d',
            $result['mode'],
            $result['created'],
            $result['skipped'],
            $result['failed'],
            $result['deferred_recent'],
        );

        $this->components->info($summary);

        return self::SUCCESS;
    }
}
