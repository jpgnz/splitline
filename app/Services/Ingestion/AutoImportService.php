<?php

namespace App\Services\Ingestion;

use App\Models\Activity;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Splitline\Core\Data\ImportedActivityBundle;
use Splitline\Parsers\Exceptions\ParseException;
use Splitline\ProviderFileImport\FitFileImportProvider;
use Throwable;

/**
 * Automated drop-folder importer for standalone .fit and .zip activity files.
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
 *     status: 'pending'|'processed'|'failed',
 *     mtime: int,
 *     size: int,
 *     sha256: string,
 *     external_id: ?string,
 *     error: ?string
 * }
 * @phpstan-type ScanResult array{
 *     configured: bool,
 *     busy: bool,
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
class AutoImportService
{
    public const string MODE_KEEP = 'keep';

    public const string MODE_ARCHIVE = 'archive';

    public const string LAST_RUN_SETTING_KEY = 'auto_import.last_run';

    /**
     * Local-disk path of the per-file fingerprint index (kept out of app_settings so it never bloats shared props).
     */
    public const string FILE_INDEX_PATH = 'auto-import/file-index.json';

    /**
     * Cache lock shared by the scheduled command and the Settings "Scan now" button.
     */
    public const string SCAN_LOCK_KEY = 'auto-import:scan';

    private const int SCAN_LOCK_SECONDS = 1800;

    private const int MAX_RECENT_IMPORTS = 5;

    private const string GENERIC_IMPORT_ERROR = 'Unexpected error while importing this file. Check the application log for details.';

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
        $raw = $override !== null ? $override : config('services.auto_import.dir');

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
        $raw = $override !== null ? $override : config('services.auto_import.mode', self::MODE_KEEP);
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

        return max(0, (int) config('services.auto_import.min_file_age_seconds', 5));
    }

    /**
     * Scan the configured (or overridden) drop folder and ingest eligible .fit / .zip files.
     *
     * Files are only ingested once they are older than the minimum age and (unless disabled) their
     * size and mtime are unchanged since the previous scan, so partially-copied files are never parsed.
     *
     * @return ScanResult
     */
    public function scan(
        ?string $directoryOverride = null,
        ?string $modeOverride = null,
        ?int $minFileAgeSeconds = null,
        bool $requireStableAcrossScans = true,
    ): array {
        $directory = $this->configuredDirectory($directoryOverride);
        $mode = $this->resolveMode($modeOverride);
        $ranAt = CarbonImmutable::now('UTC')->toIso8601String();

        if ($directory === null) {
            return $this->emptyResult(configured: false, directory: null, exists: false, readable: false, mode: $mode, ranAt: $ranAt);
        }

        $directoryExists = is_dir($directory);
        $directoryReadable = $directoryExists && is_readable($directory);

        if (! $directoryExists || ! $directoryReadable) {
            $error = ! $directoryExists
                ? "Auto-import directory does not exist: {$directory}"
                : "Auto-import directory is not readable: {$directory}";
            $previousRecent = $this->lastRunSummary()['recent_imports'] ?? [];

            $this->settings->set(self::LAST_RUN_SETTING_KEY, [
                'ran_at' => $ranAt,
                'directory' => $directory,
                'mode' => $mode,
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'deferred_recent' => 0,
                'last_error' => $error,
                'recent_imports' => $previousRecent,
            ]);

            return [
                ...$this->emptyResult(configured: true, directory: $directory, exists: $directoryExists, readable: $directoryReadable, mode: $mode, ranAt: $ranAt),
                'last_error' => $error,
                'recent_imports' => $previousRecent,
            ];
        }

        $lock = Cache::lock(self::SCAN_LOCK_KEY, self::SCAN_LOCK_SECONDS);

        if (! $lock->get()) {
            return [
                ...$this->emptyResult(configured: true, directory: $directory, exists: true, readable: true, mode: $mode, ranAt: $ranAt),
                'busy' => true,
            ];
        }

        try {
            return $this->scanDirectory($directory, $mode, $ranAt, $this->minFileAgeSeconds($minFileAgeSeconds), $requireStableAcrossScans);
        } finally {
            $lock->release();
        }
    }

    /**
     * Build the status payload consumed by SettingsController and diagnostics.
     *
     * Intentionally avoids walking or hashing the drop folder: `pending_files` reflects the
     * files still waiting to settle at the most recent scan.
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
        $lastRun = $this->lastRunSummary();

        $pendingFiles = $directoryReadable && $lastRun !== null && $lastRun['directory'] === $directory
            ? $lastRun['deferred_recent']
            : 0;

        return [
            'configured' => $configured,
            'directory' => $directory,
            'directory_exists' => $directoryExists,
            'directory_readable' => $directoryReadable,
            'mode' => $this->resolveMode(),
            'pending_files' => $pendingFiles,
            'imported_total' => Activity::query()->where('provider', $this->provider->id())->count(),
            'last_run' => $lastRun,
        ];
    }

    /**
     * Recursively discover eligible .fit and .zip files, skipping processed/, failed/, hidden files, and symlinks.
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

                if ($name === '' || str_starts_with($name, '.') || $current->isLink()) {
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
            if (! $fileInfo instanceof SplFileInfo || $fileInfo->isLink() || ! $fileInfo->isFile()) {
                continue;
            }

            $matches[] = $fileInfo->getPathname();
        }

        sort($matches, SORT_STRING);

        return $matches;
    }

    /**
     * @param  'keep'|'archive'  $mode
     * @return ScanResult
     */
    private function scanDirectory(
        string $directory,
        string $mode,
        string $ranAt,
        int $minFileAgeSeconds,
        bool $requireStableAcrossScans,
    ): array {
        $previousRecent = $this->lastRunSummary()['recent_imports'] ?? [];
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

            if (is_link($file)) {
                continue;
            }

            $mtime = @filemtime($file);
            $size = @filesize($file);

            if ($mtime === false || $size === false) {
                continue;
            }

            $indexed = $fileIndex[$relativePath] ?? null;
            $unchangedSinceLastScan = $indexed !== null && $indexed['mtime'] === $mtime && $indexed['size'] === $size;

            if ($unchangedSinceLastScan && $indexed['status'] === 'failed' && ! $canArchive) {
                $skipped++;
                $nextFileIndex[$relativePath] = $indexed;
                $entries[] = ['filename' => $filename, 'status' => 'skipped', 'message' => 'skipped (previously failed)'];

                continue;
            }

            if (
                $unchangedSinceLastScan
                && $indexed['status'] === 'processed'
                && $indexed['external_id'] !== null
                && $this->activityExists($providerId, $indexed['external_id'])
            ) {
                $skipped++;
                $entries[] = ['filename' => $filename, 'status' => 'skipped', 'message' => 'skipped (duplicate)'];
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'processed', $mtime, $size, $indexed['sha256'], $indexed['external_id']);

                continue;
            }

            $isTooRecent = $minFileAgeSeconds > 0 && ($nowTimestamp - $mtime) < $minFileAgeSeconds;
            $isUnsettled = $requireStableAcrossScans && ! $unchangedSinceLastScan;

            if ($isTooRecent || $isUnsettled) {
                $deferredRecent++;
                $nextFileIndex[$relativePath] = $this->pendingIndexEntry($mtime, $size);
                $entries[] = [
                    'filename' => $filename,
                    'status' => 'deferred',
                    'message' => $isTooRecent ? 'deferred (recently modified)' : 'deferred (waiting for file to settle)',
                ];

                continue;
            }

            $bytes = @file_get_contents($file);

            clearstatcache(true, $file);

            if ($bytes !== false && $bytes !== '' && (strlen($bytes) !== @filesize($file) || @filemtime($file) !== $mtime)) {
                $deferredRecent++;
                $nextFileIndex[$relativePath] = $this->pendingIndexEntry((int) @filemtime($file), (int) @filesize($file));
                $entries[] = ['filename' => $filename, 'status' => 'deferred', 'message' => 'deferred (file changed while reading)'];

                continue;
            }

            if ($bytes === false || $bytes === '') {
                $errorMessage = $bytes === false ? 'Unable to read file contents.' : 'File is empty.';

                $failed++;
                $lastError = "{$filename}: {$errorMessage}";
                $entries[] = ['filename' => $filename, 'status' => 'failed', 'message' => $errorMessage];
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'failed', $mtime, $size, error: $errorMessage);

                continue;
            }

            $rawSha256 = hash('sha256', $bytes);

            $isRawFit = ! str_starts_with($bytes, "PK\x03\x04")
                && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'fit';

            if ($isRawFit && $this->activityExists($providerId, $rawSha256)) {
                $skipped++;
                $entries[] = ['filename' => $filename, 'status' => 'skipped', 'message' => 'skipped (duplicate)'];
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'processed', $mtime, $size, $rawSha256, $rawSha256);

                continue;
            }

            $externalId = null;

            try {
                $bundle = $this->provider->importBytes($bytes, $filename, $timezone);
                $externalId = $bundle->activity->externalId;

                if ($this->activityExists($bundle->activity->provider, $externalId)) {
                    $skipped++;
                    $entries[] = ['filename' => $filename, 'status' => 'skipped', 'message' => 'skipped (duplicate)'];
                    $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'processed', $mtime, $size, $rawSha256, $externalId);

                    continue;
                }

                $activity = $this->persistBundle($bundle, $disk);

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
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'processed', $mtime, $size, $rawSha256, $externalId);
            } catch (UniqueConstraintViolationException) {
                $skipped++;
                $entries[] = ['filename' => $filename, 'status' => 'skipped', 'message' => 'skipped (duplicate)'];
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'processed', $mtime, $size, $rawSha256, $externalId);
            } catch (Throwable $exception) {
                if (! $exception instanceof ParseException) {
                    report($exception);
                }

                $errorMessage = $exception instanceof ParseException
                    ? $exception->getMessage()
                    : self::GENERIC_IMPORT_ERROR;

                $failed++;
                $lastError = "{$filename}: {$errorMessage}";
                $entries[] = ['filename' => $filename, 'status' => 'failed', 'message' => $errorMessage];
                $this->recordOutcome($nextFileIndex, $canArchive, $directory, $file, $relativePath, 'failed', $mtime, $size, $rawSha256, error: $errorMessage);
            }
        }

        $recentImports = $this->mergeRecentImports($newImports, $previousRecent);

        $this->settings->set(self::LAST_RUN_SETTING_KEY, [
            'ran_at' => $ranAt,
            'directory' => $directory,
            'mode' => $mode,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
            'deferred_recent' => $deferredRecent,
            'last_error' => $lastError,
            'recent_imports' => $recentImports,
        ]);
        $this->persistFileIndex($directory, $nextFileIndex);

        return [
            'configured' => true,
            'busy' => false,
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
     * Atomically store the original archive, activity summary, and telemetry stream.
     *
     * Enrichment jobs are dispatched after commit, and a freshly written archive is removed on rollback.
     */
    private function persistBundle(ImportedActivityBundle $bundle, Filesystem $disk): Activity
    {
        $ext = $bundle->format === 'fit_zip' ? 'zip' : 'fit';
        $archivePath = "fit_upload/fits/{$bundle->activity->externalId}.{$ext}";
        $archiveExisted = $disk->exists($archivePath);

        try {
            return DB::transaction(function () use ($bundle, $disk, $archivePath, $archiveExisted): Activity {
                if (! $archiveExisted) {
                    $disk->put($archivePath, $bundle->rawBytes);
                }

                $activity = $this->persistence->upsertActivity($bundle->activity, dispatchEnrichments: true);
                $this->persistence->upsertStream($activity, $bundle->stream);

                return $activity;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Another importer stored this activity (and its identical archive) concurrently.
            throw $exception;
        } catch (Throwable $exception) {
            if (! $archiveExisted) {
                $disk->delete($archivePath);
            }

            throw $exception;
        }
    }

    /**
     * Archive the file into processed/ or failed/ (archive mode), or remember its outcome in the file index.
     *
     * @param  array<string, FileIndexEntry>  $nextFileIndex
     * @param  'processed'|'failed'  $status
     */
    private function recordOutcome(
        array &$nextFileIndex,
        bool $canArchive,
        string $directory,
        string $file,
        string $relativePath,
        string $status,
        int $mtime,
        int $size,
        string $sha256 = '',
        ?string $externalId = null,
        ?string $error = null,
    ): void {
        if ($canArchive && $this->moveToSubdirectory($directory, $file, $status)) {
            return;
        }

        $nextFileIndex[$relativePath] = [
            'status' => $status,
            'mtime' => $mtime,
            'size' => $size,
            'sha256' => $sha256,
            'external_id' => $externalId,
            'error' => $error,
        ];
    }

    private function activityExists(string $provider, string $externalId): bool
    {
        return Activity::withExcluded()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->exists();
    }

    /**
     * @return FileIndexEntry
     */
    private function pendingIndexEntry(int $mtime, int $size): array
    {
        return [
            'status' => 'pending',
            'mtime' => $mtime,
            'size' => $size,
            'sha256' => '',
            'external_id' => null,
            'error' => null,
        ];
    }

    /**
     * @param  'keep'|'archive'  $mode
     * @return ScanResult
     */
    private function emptyResult(
        bool $configured,
        ?string $directory,
        bool $exists,
        bool $readable,
        string $mode,
        string $ranAt,
    ): array {
        return [
            'configured' => $configured,
            'busy' => false,
            'directory' => $directory,
            'directory_exists' => $exists,
            'directory_readable' => $readable,
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

    /**
     * Move a file into the target archive subdirectory (processed/ or failed/), resolving filename collisions.
     */
    private function moveToSubdirectory(string $importDir, string $sourceFile, string $subdirectory): bool
    {
        if (is_link($sourceFile) || ! is_writable($importDir) || ! is_writable(dirname($sourceFile))) {
            return false;
        }

        $targetDir = $importDir.DIRECTORY_SEPARATOR.$subdirectory;

        if (! is_dir($targetDir)) {
            if (! @mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                return false;
            }
        }

        if (is_link($targetDir) || ! is_writable($targetDir)) {
            return false;
        }

        $filename = basename($sourceFile);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem = pathinfo($filename, PATHINFO_FILENAME);

        $destination = $targetDir.DIRECTORY_SEPARATOR.$filename;
        $counter = 2;

        while (file_exists($destination) || is_link($destination)) {
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
     * Load the persisted per-file fingerprint index for an auto-import directory.
     *
     * @return array<string, FileIndexEntry>
     */
    private function loadFileIndex(string $directory): array
    {
        $contents = Storage::disk('local')->get(self::FILE_INDEX_PATH);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        try {
            $raw = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($raw) || ($raw['directory'] ?? null) !== $directory || ! is_array($raw['entries'] ?? null)) {
            return [];
        }

        /** @var array<string, FileIndexEntry> $entries */
        $entries = [];

        foreach ($raw['entries'] as $path => $entry) {
            if (! is_string($path) || ! is_array($entry)) {
                continue;
            }

            $status = match ($entry['status'] ?? null) {
                'failed' => 'failed',
                'pending' => 'pending',
                default => 'processed',
            };
            $externalId = is_string($entry['external_id'] ?? null) && $entry['external_id'] !== ''
                ? $entry['external_id']
                : null;
            $error = is_string($entry['error'] ?? null) && $entry['error'] !== ''
                ? $entry['error']
                : null;

            $entries[$path] = [
                'status' => $status,
                'mtime' => is_numeric($entry['mtime'] ?? null) ? (int) $entry['mtime'] : 0,
                'size' => is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : 0,
                'sha256' => is_string($entry['sha256'] ?? null) ? $entry['sha256'] : '',
                'external_id' => $externalId,
                'error' => $error,
            ];
        }

        return $entries;
    }

    /**
     * Persist the per-file fingerprint index (only files still present in the auto-import directory are kept).
     *
     * @param  array<string, FileIndexEntry>  $entries
     */
    private function persistFileIndex(string $directory, array $entries): void
    {
        Storage::disk('local')->put(self::FILE_INDEX_PATH, json_encode([
            'directory' => $directory,
            'entries' => (object) $entries,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
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
