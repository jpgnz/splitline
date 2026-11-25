<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Support\AppSettings;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Splitline\ProviderFileImport\FitFileImportProvider;
use Throwable;

class ImportFitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:fit {paths* : One or more .fit/.zip files or directories to import}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import one or more standalone .fit or .zip activity files or directories';

    /**
     * Execute the console command.
     */
    public function handle(
        FitFileImportProvider $provider,
        TelemetryPersistenceService $persistence,
        AppSettings $settings,
    ): int {
        /** @var list<string> $rawPaths */
        $rawPaths = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) $this->argument('paths'),
        ));

        $timezone = $settings->displayTimezone();

        $created = 0;
        $skipped = 0;
        $failed = 0;

        /** @var list<string> $files */
        $files = [];

        foreach ($rawPaths as $path) {
            if (is_dir($path)) {
                $dirFiles = $this->collectDirectoryFiles($path);

                foreach ($dirFiles as $dirFile) {
                    $files[] = $dirFile;
                }

                continue;
            }

            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $failed++;
            $this->components->warn("Path not found: {$path}");
        }

        $disk = Storage::disk('local');

        foreach ($files as $file) {
            try {
                $bytes = file_get_contents($file);

                if ($bytes === false || $bytes === '') {
                    $failed++;
                    $this->components->error(basename($file).': Unable to read file contents.');

                    continue;
                }

                $bundle = $provider->importBytes($bytes, basename($file), $timezone);

                $exists = Activity::query()
                    ->where('provider', $bundle->activity->provider)
                    ->where('external_id', $bundle->activity->externalId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $this->components->twoColumnDetail(basename($file), 'skipped (duplicate)');

                    continue;
                }

                $ext = $bundle->format === 'fit_zip' ? 'zip' : 'fit';
                $archivePath = "fit_upload/fits/{$bundle->activity->externalId}.{$ext}";
                $disk->put($archivePath, $bundle->rawBytes);

                $activity = $persistence->upsertActivity($bundle->activity, dispatchEnrichments: true);
                $persistence->upsertStream($activity, $bundle->stream);

                $created++;
                $this->components->twoColumnDetail(
                    basename($file),
                    "created (#{$activity->id} {$activity->name})",
                );
            } catch (Throwable $exception) {
                $failed++;
                $this->components->error(basename($file).": {$exception->getMessage()}");
            }
        }

        $this->components->info("Created: {$created}, Skipped: {$skipped}, Failed: {$failed}");

        if ($failed > 0 && $created === 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Recursively collect all .fit and .zip files inside a directory in deterministic sorted order.
     *
     * @return list<string>
     */
    private function collectDirectoryFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        $matches = [];

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }

            $extension = strtolower($fileInfo->getExtension());

            if ($extension === 'fit' || $extension === 'zip') {
                $matches[] = $fileInfo->getPathname();
            }
        }

        sort($matches, SORT_STRING);

        return $matches;
    }
}
