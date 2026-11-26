<?php

namespace App\Services\Ingestion;

use App\Models\Activity;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Splitline\ProviderFileImport\FitFileImportProvider;
use Throwable;

/**
 * Automated drop-folder watcher for standalone .fit and .zip activity files.
 *
 * @phpstan-type RecentImportItem array{
 *     activity_id: int,
 *     name: string,
 *     filename: string,
 *     imported_at: string
 * }
 * @phpstan-type LastRunSummary array{
 *     ran_at: string,
 *     directory: string,
 *     mode: 'keep'|'archive',
 *     created: int,
 *     skipped: int,
 *     failed: int,
 *     deferred_recent: int,
 *     last_error: ?string,
 *     recent_imports: list<RecentImportItem>
 * }
 * @phpstan-type ScanEntry array{
 *     filename: string,
 *     status: 'created'|'skipped'|'failed'|'deferred',
 *     message: string,
 *     activity_id?: int
 * }
 * @phpstan-type FileIndexEntry array{
 *     status: 'processed'|'failed',
 *     mtime: int,
 *     size: int,
 *     sha256: string,
 *     external_id: ?string,
 *     error: ?string
 * }
 */
class ImportWatchService
{
    public const string MODE_KEEP = 'keep';

    public const string MODE_ARCHIVE = 'archive';

    public const string LAST_RUN_SETTING_KEY = 'import_watch.last_run';

    public const string FILE_INDEX_SETTING_KEY = 'import_watch.file_index';

    private const int MAX_RECENT_IMPORTS = 5;

    private const int MAX_INDEXED_FILES = 1000;

    /**
     * Subdirectory names managed by archive mode and skipped during recursive discovery.
     *
     * @var array<string, true>
     */
    private const array SKIPPED_SUBDIRECTORIES = [
        'processed' => true,
        'failed' => true,
    ];

    public function __construct(
        private readonly FitFileImportProvider $provider,
        private readonly TelemetryPersistenceService $persistence,
        private readonly AppSettings $settings,
    ) {}

    /**
     * Resolve the configured (or overridden) drop-folder path, or null when unconfigured.
     */
    public function configuredDirectory(?string $override = null): ?string
    {
        $raw = $override !== null ? $override : config('services.import_watch.dir');

        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        if ($trimmed !== DIRECTORY_SEPARATOR) {
            $trimmed = rtrim($trimmed, '/\\');
        }

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Resolve the active file handling mode ('keep' or 'archive').
     *
     * @return 'keep'|'archive'
     */
    public function resolveMode(?string $override = null): string
    {
        $raw = $override !== null ? $override : config('services.import_watch.mode', self::MODE_KEEP);
        $normalized = is_string($raw) ? strtolower(trim($raw)) : self::MODE_KEEP;

        return $normalized === self::MODE_ARCHIVE ? self::MODE_ARCHIVE : self::MODE_KEEP;
    }

    /**
     * Resolve the minimum file modification age in seconds before ingestion.
     */
    public function minFileAgeSeconds(?int $override = null): int
    {
        if ($override !== null) {
            return max(0, $override);
        }

        return max(0, (int) config('services.import_watch.min_file_age_seconds', 5));
    }

    /**
     * Scan the configured (or overridden) drop folder and ingest eligible .fit / .zip files.
     *
     * @return array{
     *     configured: bool,
     *     directory: ?string,
     *     directory_exists: bool,
     *     directory_readable: bool,
     *     mode: 'keep'|'archive',
     *     created: int,
     *     skipped: int,
     *     failed: int,
     *     deferred_recent: int,
     *     last_error: ?string,
     *     recent_imports: list<RecentImportItem>,
     *     ran_at: string,
     *     entries: list<ScanEntry>
     * }
     */
    public function scan(
        ?string $directoryOverride = null,
        ?string $modeOverride = null,
        ?int $minFileAgeSeconds = null,
    ): array {
        $directory = $this->configuredDirectory($directoryOverride);
        $mode = $this->resolveMode($modeOverride);
        $ranAt = CarbonImmutable::now('UTC')->toIso8601String();

        if ($directory === null) {
            return [
                'configured' => false,
                'directory' => null,
                'directory_exists' => false,
                'directory_readable' => false,
                'mode' => $mode,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'deferred_recent' => 0,
                'last_error' => null,
                'recent_imports' => [],
                'ran_at' => $ranAt,
                'entries' => [],
            ];
        }

        $directoryExists = is_dir($directory);
        $directoryReadable = $directoryExists && is_readable($directory);
        $previousRecent = $this->lastRunSummary()['recent_imports'] ?? [];

        if (! $directoryExists || ! $directoryReadable) {
            $error = ! $directoryExists
                ? "Import watch directory does not exist: {$directory}"
                : "Import watch directory is not readable: {$directory}";

            $lastRun = [
                'ran_at' => $ranAt,
                'directory' => $directory,
                'mode' => $mode,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'deferred_recent' => 0,
                'last_error' => $error,
                'recent_imports' => $previousRecent,
            ];
            $this->settings->set(self::LAST_RUN_SETTING_KEY, $lastRun);

            return [
                'configured' => true,
                'directory' => $directory,
                'directory_exists' => $directoryExists,
                'directory_readable' => $directoryReadable,
                'mode' => $mode,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'deferred_recent' => 0,
                'last_error' => $error,
                'recent_imports' => $previousRecent,
                'ran_at' => $ranAt,
                'entries' => [],
            ];
        }

        $effectiveMinAge = $this->minFileAgeSeconds($minFileAgeSeconds);
        $nowTimestamp = CarbonImmutable::now('UTC')->getTimestamp();
        $timezone = $this->settings->displayTimezone();
        $disk = Storage::disk('local');
        $providerId = $this->provider->id();

        $files = $this->discoverFiles($directory);
        $fileIndex = $this->loadFileIndex($directory);
        /** @var array<string, FileIndexEntry> $nextFileIndex */
        $nextFileIndex = [];

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $deferredRecent = 0;
        $lastError = null;
        /** @var list<RecentImportItem> $newImports */
        $newImports = [];
        /** @var list<ScanEntry> $entries */
        $entries = [];

        $canArchive = $mode === self::MODE_ARCHIVE && is_writable($directory);

        foreach ($files as $file) {
            $filename = basename($file);
            $relativePath = $this->relativePath($directory, $file);

            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            $size = @filesize($file);
            $mtimeInt = $mtime !== false ? $mtime : 0;
            $sizeInt = $size !== false ? $size : 0;

            if ($effectiveMinAge > 0 && $mtime !== false && ($nowTimestamp - $mtime) < $effectiveMinAge) {
                $deferredRecent++;
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'deferred',
                    'message' => 'deferred (recently modified)',
                ];

                continue;
            }

            $indexed = $fileIndex[$relativePath] ?? null;

            $bytes = @file_get_contents($file);

            if ($bytes === false || $bytes === '') {
                $errorMessage = $bytes === false
                    ? 'Unable to read file contents.'
                    : 'File is empty.';

                if (! $canArchive && $indexed !== null && $indexed['status'] === 'failed' && $indexed['mtime'] === $mtimeInt && $indexed['size'] === $sizeInt) {
                    $skipped++;
                    $nextFileIndex[$relativePath] = $indexed;
                    $entries[] = [
                        'filename' => $filename,
                        'status' => 'skipped',
                        'message' => 'skipped (previously failed)',
                    ];

                    continue;
                }

                $failed++;
                $lastError = "{$filename}: {$errorMessage}";
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'failed',
                    'message' => $errorMessage,
                ];

                $moved = $canArchive && $this->moveToSubdirectory($directory, $file, 'failed');

                if (! $moved) {
                    $nextFileIndex[$relativePath] = [
                        'status' => 'failed',
                        'mtime' => $mtimeInt,
                        'size' => $sizeInt,
                        'sha256' => '',
                        'external_id' => null,
                        'error' => $errorMessage,
                    ];
                }

                continue;
            }

            $rawSha256 = hash('sha256', $bytes);

            if (
                ! $canArchive
                && $indexed !== null
                && $indexed['status'] === 'failed'
                && $indexed['sha256'] === $rawSha256
            ) {
                $skipped++;
                $nextFileIndex[$relativePath] = [
                    ...$indexed,
                    'mtime' => $mtimeInt,
                    'size' => $sizeInt,
                ];
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'skipped',
                    'message' => 'skipped (previously failed)',
                ];

                continue;
            }

            $isRawFit = ! str_starts_with($bytes, "PK\x03\x04")
                && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'fit';

            $fastExternalId = null;

            if ($isRawFit) {
                $fastExternalId = $rawSha256;
            } elseif (
                $indexed !== null
                && $indexed['status'] === 'processed'
                && $indexed['sha256'] === $rawSha256
                && $indexed['external_id'] !== null
            ) {
                $fastExternalId = $indexed['external_id'];
            }

            if (
                $fastExternalId !== null
                && Activity::query()
                    ->where('provider', $providerId)
                    ->where('external_id', $fastExternalId)
                    ->exists()
            ) {
                $skipped++;
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'skipped',
                    'message' => 'skipped (duplicate)',
                ];

                $moved = $canArchive && $this->moveToSubdirectory($directory, $file, 'processed');

                if (! $moved) {
                    $nextFileIndex[$relativePath] = [
                        'status' => 'processed',
                        'mtime' => $mtimeInt,
                        'size' => $sizeInt,
                        'sha256' => $rawSha256,
                        'external_id' => $fastExternalId,
                        'error' => null,
                    ];
                }

                continue;
            }

            try {
                $bundle = $this->provider->importBytes($bytes, $filename, $timezone);

                $exists = Activity::query()
                    ->where('provider', $bundle->activity->provider)
                    ->where('external_id', $bundle->activity->externalId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    $entries[] = [
                        'filename' => $filename,
                        'status' => 'skipped',
                        'message' => 'skipped (duplicate)',
                    ];

                    $moved = $canArchive && $this->moveToSubdirectory($directory, $file, 'processed');

                    if (! $moved) {
                        $nextFileIndex[$relativePath] = [
                            'status' => 'processed',
                            'mtime' => $mtimeInt,
                            'size' => $sizeInt,
                            'sha256' => $rawSha256,
                            'external_id' => $bundle->activity->externalId,
                            'error' => null,
                        ];
                    }

                    continue;
                }

                $ext = $bundle->format === 'fit_zip' ? 'zip' : 'fit';
                $archivePath = "fit_upload/fits/{$bundle->activity->externalId}.{$ext}";
                $disk->put($archivePath, $bundle->rawBytes);

                $activity = $this->persistence->upsertActivity($bundle->activity, dispatchEnrichments: true);
                $this->persistence->upsertStream($activity, $bundle->stream);

                $created++;
                $newImports[] = [
                    'activity_id' => (int) $activity->id,
                    'name' => (string) $activity->name,
                    'filename' => $filename,
                    'imported_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ];
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'created',
                    'message' => "created (#{$activity->id} {$activity->name})",
                    'activity_id' => (int) $activity->id,
                ];

                $moved = $canArchive && $this->moveToSubdirectory($directory, $file, 'processed');

                if (! $moved) {
                    $nextFileIndex[$relativePath] = [
                        'status' => 'processed',
                        'mtime' => $mtimeInt,
                        'size' => $sizeInt,
                        'sha256' => $rawSha256,
                        'external_id' => $bundle->activity->externalId,
                        'error' => null,
                    ];
                }
            } catch (Throwable $exception) {
                $failed++;
                $errorMessage = $exception->getMessage();
                $lastError = "{$filename}: {$errorMessage}";
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'failed',
                    'message' => $errorMessage,
                ];

                $moved = $canArchive && $this->moveToSubdirectory($directory, $file, 'failed');

                if (! $moved) {
                    $nextFileIndex[$relativePath] = [
                        'status' => 'failed',
                        'mtime' => $mtimeInt,
                        'size' => $sizeInt,
                        'sha256' => $rawSha256,
                        'external_id' => null,
                        'error' => $errorMessage,
                    ];
                }
            }
        }

        $recentImports = $this->mergeRecentImports($newImports, $previousRecent);

        $lastRun = [
            'ran_at' => $ranAt,
            'directory' => $directory,
            'mode' => $mode,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'deferred_recent' => $deferredRecent,
            'last_error' => $lastError,
            'recent_imports' => $recentImports,
        ];

        $this->settings->set(self::LAST_RUN_SETTING_KEY, $lastRun);
        $this->persistFileIndex($directory, $nextFileIndex);

        return [
            'configured' => true,
            'directory' => $directory,
            'directory_exists' => true,
            'directory_readable' => true,
            'mode' => $mode,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'deferred_recent' => $deferredRecent,
            'last_error' => $lastError,
            'recent_imports' => $recentImports,
            'ran_at' => $ranAt,
            'entries' => $entries,
        ];
    }

    /**
     * Build the status payload consumed by SettingsController and diagnostics.
     *
     * @return array{
     *     configured: bool,
     *     directory: ?string,
     *     directory_exists: bool,
     *     directory_readable: bool,
     *     mode: 'keep'|'archive',
     *     pending_files: int,
     *     imported_total: int,
     *     last_run: ?LastRunSummary
     * }
     */
    public function statusPayload(): array
    {
        $directory = $this->configuredDirectory();
        $configured = $directory !== null;
        $directoryExists = $configured && is_dir($directory);
        $directoryReadable = $directoryExists && is_readable($directory);
        $mode = $this->resolveMode();

        $importedTotal = Activity::query()
            ->where('provider', $this->provider->id())
            ->count();

        $pendingFiles = $directoryReadable
            ? $this->countPendingFiles($directory)
            : 0;

        return [
            'configured' => $configured,
            'directory' => $directory,
            'directory_exists' => $directoryExists,
            'directory_readable' => $directoryReadable,
            'mode' => $mode,
            'pending_files' => $pendingFiles,
            'imported_total' => $importedTotal,
            'last_run' => $this->lastRunSummary(),
        ];
    }

    /**
     * Recursively discover eligible .fit and .zip files, skipping processed/, failed/, and hidden files.
     *
     * @return list<string>
     */
    public function discoverFiles(string $directory): array
    {
        if (! is_dir($directory) || ! is_readable($directory)) {
            return [];
        }

        $directoryIterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $filterIterator = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();

                if ($name === '' || str_starts_with($name, '.')) {
                    return false;
                }

                if ($current->isDir()) {
                    if (isset(self::SKIPPED_SUBDIRECTORIES[strtolower($name)])) {
                        return false;
                    }

                    return $current->isReadable();
                }

                if (! $current->isFile()) {
                    return false;
                }

                $extension = strtolower($current->getExtension());

                return $extension === 'fit' || $extension === 'zip';
            },
        );

        $iterator = new RecursiveIteratorIterator($filterIterator, RecursiveIteratorIterator::LEAVES_ONLY);

        /** @var list<string> $matches */
        $matches = [];

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }

            $matches[] = $fileInfo->getPathname();
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /**
     * Count eligible files in the watch directory that have not yet been imported or permanently failed.
     */
    private function countPendingFiles(string $directory): int
    {
        $files = $this->discoverFiles($directory);

        if ($files === []) {
            return 0;
        }

        /** @var array<string, true> $existingExternalIds */
        $existingExternalIds = [];

        foreach (Activity::query()->where('provider', $this->provider->id())->pluck('external_id') as $externalId) {
            if (is_string($externalId) && $externalId !== '') {
                $existingExternalIds[$externalId] = true;
            }
        }

        $fileIndex = $this->loadFileIndex($directory);
        $pending = 0;

        foreach ($files as $file) {
            $relativePath = $this->relativePath($directory, $file);
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            $size = @filesize($file);
            $mtimeInt = $mtime !== false ? $mtime : 0;
            $sizeInt = $size !== false ? $size : 0;

            $indexed = $fileIndex[$relativePath] ?? null;

            if ($indexed !== null && $indexed['mtime'] === $mtimeInt && $indexed['size'] === $sizeInt) {
                if ($indexed['status'] === 'failed') {
                    continue;
                }

                if ($indexed['external_id'] !== null && isset($existingExternalIds[$indexed['external_id']])) {
                    continue;
                }
            }

            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'fit') {
                $hash = @hash_file('sha256', $file);

                if (is_string($hash) && isset($existingExternalIds[$hash])) {
                    continue;
                }
            }

            $pending++;
        }

        return $pending;
    }

    /**
     * Move a file into the target archive subdirectory (processed/ or failed/), resolving filename collisions.
     */
    private function moveToSubdirectory(string $watchDir, string $sourceFile, string $subdirectory): bool
    {
        if (! is_writable($watchDir) || ! is_writable(dirname($sourceFile))) {
            return false;
        }

        $targetDir = $watchDir.DIRECTORY_SEPARATOR.$subdirectory;

        if (! is_dir($targetDir)) {
            if (! @mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                return false;
            }
        }

        if (! is_writable($targetDir)) {
            return false;
        }

        $filename = basename($sourceFile);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        $destination = $targetDir.DIRECTORY_SEPARATOR.$filename;
        $counter = 2;

        while (file_exists($destination)) {
            $candidateName = $extension !== ''
                ? "{$stem}-{$counter}.{$extension}"
                : "{$stem}-{$counter}";
            $destination = $targetDir.DIRECTORY_SEPARATOR.$candidateName;
            $counter++;
        }

        if (@rename($sourceFile, $destination)) {
            return true;
        }

        if (@copy($sourceFile, $destination)) {
            if (@unlink($sourceFile)) {
                return true;
            }

            @unlink($destination);
        }

        return false;
    }

    /**
     * Retrieve and validate the last persisted run summary.
     *
     * @return ?LastRunSummary
     */
    private function lastRunSummary(): ?array
    {
        $raw = $this->settings->get(self::LAST_RUN_SETTING_KEY);

        if (! is_array($raw) || ! is_string($raw['ran_at'] ?? null) || ! is_string($raw['directory'] ?? null)) {
            return null;
        }

        $mode = ($raw['mode'] ?? null) === self::MODE_ARCHIVE ? self::MODE_ARCHIVE : self::MODE_KEEP;
        $lastError = is_string($raw['last_error'] ?? null) && $raw['last_error'] !== ''
            ? $raw['last_error']
            : null;

        /** @var list<RecentImportItem> $recentImports */
        $recentImports = [];

        if (is_array($raw['recent_imports'] ?? null)) {
            foreach ($raw['recent_imports'] as $item) {
                if (
                    is_array($item)
                    && isset($item['activity_id'], $item['name'], $item['filename'], $item['imported_at'])
                    && is_numeric($item['activity_id'])
                    && is_string($item['name'])
                    && is_string($item['filename'])
                    && is_string($item['imported_at'])
                ) {
                    $recentImports[] = [
                        'activity_id' => (int) $item['activity_id'],
                        'name' => $item['name'],
                        'filename' => $item['filename'],
                        'imported_at' => $item['imported_at'],
                    ];
                }
            }
        }

        return [
            'ran_at' => $raw['ran_at'],
            'directory' => $raw['directory'],
            'mode' => $mode,
            'created' => is_numeric($raw['created'] ?? null) ? (int) $raw['created'] : 0,
            'skipped' => is_numeric($raw['skipped'] ?? null) ? (int) $raw['skipped'] : 0,
            'failed' => is_numeric($raw['failed'] ?? null) ? (int) $raw['failed'] : 0,
            'deferred_recent' => is_numeric($raw['deferred_recent'] ?? null) ? (int) $raw['deferred_recent'] : 0,
            'last_error' => $lastError,
            'recent_imports' => array_slice($recentImports, 0, self::MAX_RECENT_IMPORTS),
        ];
    }

    /**
     * Merge newly imported activities ahead of previous recent imports, deduplicated and capped.
     *
     * @param  list<RecentImportItem>  $newImports
     * @param  list<RecentImportItem>  $previousImports
     * @return list<RecentImportItem>
     */
    private function mergeRecentImports(array $newImports, array $previousImports): array
    {
        $combined = [...array_reverse($newImports), ...$previousImports];
        /** @var list<RecentImportItem> $merged */
        $merged = [];
        /** @var array<int, true> $seenIds */
        $seenIds = [];

        foreach ($combined as $item) {
            $id = $item['activity_id'];

            if (isset($seenIds[$id])) {
                continue;
            }

            $seenIds[$id] = true;
            $merged[] = $item;

            if (count($merged) >= self::MAX_RECENT_IMPORTS) {
                break;
            }
        }

        return $merged;
    }

    /**
     * Load the persisted per-file fingerprint index for a watch directory.
     *
     * @return array<string, FileIndexEntry>
     */
    private function loadFileIndex(string $directory): array
    {
        $raw = $this->settings->get(self::FILE_INDEX_SETTING_KEY);

        if (! is_array($raw) || ($raw['directory'] ?? null) !== $directory || ! is_array($raw['entries'] ?? null)) {
            return [];
        }

        /** @var array<string, FileIndexEntry> $entries */
        $entries = [];

        foreach ($raw['entries'] as $path => $entry) {
            if (! is_string($path) || ! is_array($entry)) {
                continue;
            }

            $status = ($entry['status'] ?? null) === 'failed' ? 'failed' : 'processed';
            $mtime = is_numeric($entry['mtime'] ?? null) ? (int) $entry['mtime'] : 0;
            $size = is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : 0;
            $sha256 = is_string($entry['sha256'] ?? null) ? $entry['sha256'] : '';
            $externalId = is_string($entry['external_id'] ?? null) && $entry['external_id'] !== ''
                ? $entry['external_id']
                : null;
            $error = is_string($entry['error'] ?? null) && $entry['error'] !== ''
                ? $entry['error']
                : null;

            $entries[$path] = [
                'status' => $status,
                'mtime' => $mtime,
                'size' => $size,
                'sha256' => $sha256,
                'external_id' => $externalId,
                'error' => $error,
            ];
        }

        return $entries;
    }

    /**
     * Persist the bounded per-file fingerprint index for a watch directory.
     *
     * @param  array<string, FileIndexEntry>  $entries
     */
    private function persistFileIndex(string $directory, array $entries): void
    {
        if (count($entries) > self::MAX_INDEXED_FILES) {
            $entries = array_slice($entries, -self::MAX_INDEXED_FILES, null, true);
        }

        $this->settings->set(self::FILE_INDEX_SETTING_KEY, [
            'directory' => $directory,
            'entries' => $entries,
        ]);
    }

    private function relativePath(string $baseDir, string $filePath): string
    {
        $prefix = rtrim($baseDir, '/\\').DIRECTORY_SEPARATOR;

        if (str_starts_with($filePath, $prefix)) {
            return substr($filePath, strlen($prefix));
        }

        return basename($filePath);
    }
}
