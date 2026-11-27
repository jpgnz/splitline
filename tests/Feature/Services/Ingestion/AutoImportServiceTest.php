<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use App\Services\Ingestion\AutoImportService;
use App\Services\Ingestion\TelemetryPersistenceService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

/**
 * @param  list<string>  $messages
 */
function autoImportBuildFitBinary(array $messages, int $headerSize = 14): string
{
    $payload = implode('', $messages);
    $dataSize = strlen($payload);

    $header = chr($headerSize)
        .chr(0x20)
        .pack('v', 2140)
        .pack('V', $dataSize)
        .'.FIT';

    if ($headerSize === 14) {
        $header .= pack('v', 0);
    }

    return $header.$payload.pack('v', 0);
}

/**
 * @param  list<array{0: int, 1: int, 2: int}>  $fields
 */
function autoImportFitDefinition(int $localType, int $globalMsg, array $fields): string
{
    $header = 0x40 | ($localType & 0x0F);

    $bytes = chr($header)
        .chr(0)
        .chr(0)
        .pack('v', $globalMsg)
        .chr(count($fields));

    foreach ($fields as [$num, $size, $baseType]) {
        $bytes .= chr($num).chr($size).chr($baseType);
    }

    return $bytes;
}

function autoImportFitData(int $localType, string $payload): string
{
    return chr($localType & 0x0F).$payload;
}

function autoImportDegreesToSemicircles(float $degrees): int
{
    return (int) round($degrees * (2147483648.0 / 180.0));
}

function autoImportCreateSyntheticRun(
    int $fitStartTs = 1147292100,
    float $distanceMeters = 5200.0,
    float $speedMps = 4.0,
    int $sportId = 1,
    int $subSportId = 3,
): string {
    $durationSec = (int) round($distanceMeters / $speedMps);
    $messages = [];

    $messages[] = autoImportFitDefinition(0, 0, [
        [0, 1, 0x00],
        [1, 2, 0x84],
        [2, 2, 0x84],
    ]);
    $messages[] = autoImportFitData(0, chr(4).pack('v', 1).pack('v', 4257));

    $messages[] = autoImportFitDefinition(1, 19, [
        [7, 4, 0x86],
        [8, 4, 0x86],
        [9, 4, 0x86],
        [15, 1, 0x02],
        [110, 4, 0x86],
    ]);
    $messages[] = autoImportFitData(
        1,
        pack('V', $durationSec * 1000)
        .pack('V', $durationSec * 1000)
        .pack('V', (int) round($distanceMeters * 100))
        .chr(156)
        .pack('V', (int) round($speedMps * 1000)),
    );

    $messages[] = autoImportFitDefinition(2, 20, [
        [253, 4, 0x86],
        [0, 4, 0x85],
        [1, 4, 0x85],
        [3, 1, 0x02],
        [4, 1, 0x02],
        [5, 4, 0x86],
        [73, 4, 0x86],
        [78, 4, 0x86],
    ]);

    for ($s = 0; $s <= $durationSec; $s += 10) {
        $dist = min($distanceMeters, $s * $speedMps);
        $lat = -36.8485 + ($s * 0.00002);
        $lng = 174.7633 + ($s * 0.00001);
        $alt = 120.0 + ($dist * 0.01);

        $messages[] = autoImportFitData(
            2,
            pack('V', $fitStartTs + $s)
            .pack('V', autoImportDegreesToSemicircles($lat))
            .pack('V', autoImportDegreesToSemicircles($lng))
            .chr(155)
            .chr(87)
            .pack('V', (int) round($dist * 100))
            .pack('V', (int) round($speedMps * 1000))
            .pack('V', (int) round(($alt + 500.0) * 5.0)),
        );
    }

    $messages[] = autoImportFitDefinition(3, 18, [
        [2, 4, 0x86],
        [5, 1, 0x00],
        [6, 1, 0x00],
        [7, 4, 0x86],
        [8, 4, 0x86],
        [9, 4, 0x86],
        [11, 2, 0x84],
        [16, 1, 0x02],
        [17, 1, 0x02],
        [18, 1, 0x02],
        [22, 2, 0x84],
        [23, 2, 0x84],
        [124, 4, 0x86],
        [125, 4, 0x86],
    ]);
    $messages[] = autoImportFitData(
        3,
        pack('V', $fitStartTs)
        .chr($sportId)
        .chr($subSportId)
        .pack('V', ($durationSec + 20) * 1000)
        .pack('V', $durationSec * 1000)
        .pack('V', (int) round($distanceMeters * 100))
        .pack('v', 420)
        .chr(155)
        .chr(172)
        .chr(87)
        .pack('v', 85)
        .pack('v', 30)
        .pack('V', (int) round($speedMps * 1000))
        .pack('V', (int) round(($speedMps + 1.0) * 1000)),
    );

    return autoImportBuildFitBinary($messages);
}

function autoImportZipBytes(string $contents, string $entryName = 'activity.fit'): string
{
    $path = tempnam(sys_get_temp_dir(), 'splitline_auto_import_zip_');
    expect($path)->toBeString();

    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString($entryName, $contents);
    $zip->close();

    $bytes = file_get_contents($path);
    @unlink($path);

    expect($bytes)->toBeString();

    return $bytes;
}

function autoImportCreateTempDir(): string
{
    $dir = sys_get_temp_dir().'/splitline_auto_import_test_'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);

    return $dir;
}

function autoImportRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    @chmod($dir, 0777);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        @chmod($item->getPathname(), 0777);

        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

it('imports .fit and .zip files in keep mode, stores archives, dispatches enrichments, and deduplicates subsequent scans via SHA-256', function () {
    Http::preventStrayRequests();
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        config([
            'app.display_timezone' => 'Pacific/Auckland',
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'keep',
            'services.auto_import.min_file_age_seconds' => 0,
            'services.open_meteo.enabled' => true,
            'services.open_meteo.auto_sync' => true,
            'services.nominatim.enabled' => true,
            'services.nominatim.auto_sync' => true,
        ]);
        app(AppSettings::class)->flushMemo();

        $fitOne = autoImportCreateSyntheticRun(fitStartTs: 1147292100, distanceMeters: 5200.0);
        $fitTwo = autoImportCreateSyntheticRun(fitStartTs: 1147378500, distanceMeters: 3000.0);
        $zipTwo = autoImportZipBytes($fitTwo, 'SECOND.FIT');
        $zipDuplicateOfOne = autoImportZipBytes($fitOne, 'DUP_OF_ONE.FIT');

        file_put_contents($tempDir.'/01_morning.fit', $fitOne);
        file_put_contents($tempDir.'/02_evening.zip', $zipTwo);
        file_put_contents($tempDir.'/03_duplicate.zip', $zipDuplicateOfOne);

        $service = app(AutoImportService::class);
        $firstScan = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($firstScan['configured'])->toBeTrue()
            ->and($firstScan['mode'])->toBe('keep')
            ->and($firstScan['created'])->toBe(2)
            ->and($firstScan['skipped'])->toBe(1)
            ->and($firstScan['failed'])->toBe(0)
            ->and($firstScan['deferred_recent'])->toBe(0)
            ->and($firstScan['last_error'])->toBeNull()
            ->and($firstScan['recent_imports'])->toHaveCount(2);

        expect(Activity::query()->where('provider', 'fit_upload')->count())->toBe(2)
            ->and(ActivityStream::query()->count())->toBe(2)
            ->and(BestEffort::query()->count())->toBeGreaterThan(0);

        $hashOne = hash('sha256', $fitOne);
        $hashTwo = hash('sha256', $fitTwo);

        Storage::disk('local')->assertExists("fit_upload/fits/{$hashOne}.fit");
        Storage::disk('local')->assertExists("fit_upload/fits/{$hashTwo}.zip");

        expect(file_exists($tempDir.'/01_morning.fit'))->toBeTrue()
            ->and(file_exists($tempDir.'/02_evening.zip'))->toBeTrue()
            ->and(file_exists($tempDir.'/03_duplicate.zip'))->toBeTrue();

        Queue::assertNotPushed(SyncActivityStreamJob::class);
        Queue::assertPushed(SyncActivityWeatherJob::class, 2);
        Queue::assertPushed(GeocodeActivityLocationJob::class, 2);

        $secondScan = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($secondScan['created'])->toBe(0)
            ->and($secondScan['skipped'])->toBe(3)
            ->and($secondScan['failed'])->toBe(0)
            ->and($secondScan['recent_imports'])->toHaveCount(2);

        $status = $service->statusPayload();

        expect($status['configured'])->toBeTrue()
            ->and($status['directory'])->toBe($tempDir)
            ->and($status['directory_exists'])->toBeTrue()
            ->and($status['directory_readable'])->toBeTrue()
            ->and($status['mode'])->toBe('keep')
            ->and($status['pending_files'])->toBe(0)
            ->and($status['imported_total'])->toBe(2)
            ->and($status['last_run'])->not->toBeNull()
            ->and($status['last_run']['recent_imports'])->toHaveCount(2);
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('tracks unchanged failed files in keep mode and retries them if replaced with valid bytes', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'keep',
            'services.auto_import.min_file_age_seconds' => 0,
        ]);
        app(AppSettings::class)->flushMemo();

        $filePath = $tempDir.'/broken.fit';
        file_put_contents($filePath, 'corrupt-fit-bytes');

        $service = app(AutoImportService::class);
        $first = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($first['created'])->toBe(0)
            ->and($first['failed'])->toBe(1)
            ->and($first['last_error'])->toContain('broken.fit');

        $second = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($second['created'])->toBe(0)
            ->and($second['skipped'])->toBe(1)
            ->and($second['failed'])->toBe(0)
            ->and($second['last_error'])->toBeNull();

        file_put_contents($filePath, autoImportCreateSyntheticRun());

        $third = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($third['created'])->toBe(1)
            ->and($third['failed'])->toBe(0)
            ->and($third['last_error'])->toBeNull()
            ->and(Activity::query()->count())->toBe(1);
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('moves created and duplicate files to processed and corrupt files to failed in archive mode with collision handling', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'archive',
            'services.auto_import.min_file_age_seconds' => 0,
        ]);
        app(AppSettings::class)->flushMemo();

        $fitBytes = autoImportCreateSyntheticRun();
        file_put_contents($tempDir.'/run.fit', $fitBytes);
        file_put_contents($tempDir.'/corrupt.fit', 'invalid-binary-payload');

        $service = app(AutoImportService::class);
        $first = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($first['created'])->toBe(1)
            ->and($first['failed'])->toBe(1)
            ->and(file_exists($tempDir.'/run.fit'))->toBeFalse()
            ->and(file_exists($tempDir.'/corrupt.fit'))->toBeFalse()
            ->and(file_exists($tempDir.'/processed/run.fit'))->toBeTrue()
            ->and(file_exists($tempDir.'/failed/corrupt.fit'))->toBeTrue();

        // Drop another file with the exact same name (duplicate content) and another corrupt file
        file_put_contents($tempDir.'/run.fit', $fitBytes);
        file_put_contents($tempDir.'/corrupt.fit', 'still-invalid');

        $second = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($second['created'])->toBe(0)
            ->and($second['skipped'])->toBe(1)
            ->and($second['failed'])->toBe(1)
            ->and(file_exists($tempDir.'/processed/run-2.fit'))->toBeTrue()
            ->and(file_exists($tempDir.'/failed/corrupt-2.fit'))->toBeTrue();
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('skips processed and failed subdirectories, hidden dotfiles, Syncthing temp files, and non-FIT files while scanning nested folders', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        mkdir($tempDir.'/processed', 0777, true);
        mkdir($tempDir.'/failed', 0777, true);
        mkdir($tempDir.'/.stversions', 0777, true);
        mkdir($tempDir.'/2026/05', 0777, true);

        file_put_contents($tempDir.'/processed/old.fit', autoImportCreateSyntheticRun(fitStartTs: 1147000000));
        file_put_contents($tempDir.'/failed/bad.fit', autoImportCreateSyntheticRun(fitStartTs: 1147010000));
        file_put_contents($tempDir.'/.stversions/versioned.fit', autoImportCreateSyntheticRun(fitStartTs: 1147020000));
        file_put_contents($tempDir.'/.hidden.fit', autoImportCreateSyntheticRun(fitStartTs: 1147030000));
        file_put_contents($tempDir.'/.syncthing.incoming.fit.tmp', autoImportCreateSyntheticRun(fitStartTs: 1147040000));
        file_put_contents($tempDir.'/notes.txt', 'ignore me');
        file_put_contents($tempDir.'/2026/05/nested_run.fit', autoImportCreateSyntheticRun(fitStartTs: 1147292100));

        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'keep',
            'services.auto_import.min_file_age_seconds' => 0,
        ]);
        app(AppSettings::class)->flushMemo();

        $service = app(AutoImportService::class);
        $discovered = $service->discoverFiles($tempDir);

        expect($discovered)->toBe([$tempDir.'/2026/05/nested_run.fit']);

        $result = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($result['created'])->toBe(1)
            ->and($result['skipped'])->toBe(0)
            ->and($result['failed'])->toBe(0)
            ->and(Activity::query()->count())->toBe(1);
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('defers brand-new files younger than min_file_age_seconds without moving or failing them', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $now = CarbonImmutable::parse('2026-05-12 12:00:00', 'UTC');
    $this->travelTo($now);

    $tempDir = autoImportCreateTempDir();

    try {
        $oldFile = $tempDir.'/stable.fit';
        $freshFile = $tempDir.'/syncing.fit';

        file_put_contents($oldFile, autoImportCreateSyntheticRun(fitStartTs: 1147292100));
        file_put_contents($freshFile, autoImportCreateSyntheticRun(fitStartTs: 1147378500));

        touch($oldFile, $now->subSeconds(30)->getTimestamp());
        touch($freshFile, $now->subSeconds(2)->getTimestamp());

        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'archive',
            'services.auto_import.min_file_age_seconds' => 10,
        ]);
        app(AppSettings::class)->flushMemo();

        $service = app(AutoImportService::class);
        $first = $service->scan(requireStableAcrossScans: false);

        expect($first['created'])->toBe(1)
            ->and($first['deferred_recent'])->toBe(1)
            ->and($first['failed'])->toBe(0)
            ->and(file_exists($tempDir.'/processed/stable.fit'))->toBeTrue()
            ->and(file_exists($freshFile))->toBeTrue()
            ->and(Activity::query()->count())->toBe(1);

        $this->travel(15)->seconds();

        $second = $service->scan(requireStableAcrossScans: false);

        expect($second['created'])->toBe(1)
            ->and($second['deferred_recent'])->toBe(0)
            ->and(file_exists($tempDir.'/processed/syncing.fit'))->toBeTrue()
            ->and(Activity::query()->count())->toBe(2);
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('reports unconfigured and missing directories cleanly in statusPayload, reports settling files as pending, and caps recent_imports to 5 items', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    config([
        'services.auto_import.dir' => null,
        'services.auto_import.mode' => 'keep',
    ]);
    app(AppSettings::class)->flushMemo();

    $service = app(AutoImportService::class);
    $unconfiguredStatus = $service->statusPayload();

    expect($unconfiguredStatus)->toMatchArray([
        'configured' => false,
        'directory' => null,
        'directory_exists' => false,
        'directory_readable' => false,
        'mode' => 'keep',
        'pending_files' => 0,
        'imported_total' => 0,
        'last_run' => null,
    ]);

    $unconfiguredScan = $service->scan();

    expect($unconfiguredScan['configured'])->toBeFalse()
        ->and(app(AppSettings::class)->get(AutoImportService::LAST_RUN_SETTING_KEY))->toBeNull();

    $missingPath = sys_get_temp_dir().'/splitline_missing_auto_import_dir_'.bin2hex(random_bytes(6));
    config(['services.auto_import.dir' => $missingPath]);

    $missingStatus = $service->statusPayload();

    expect($missingStatus['configured'])->toBeTrue()
        ->and($missingStatus['directory_exists'])->toBeFalse()
        ->and($missingStatus['directory_readable'])->toBeFalse()
        ->and($missingStatus['pending_files'])->toBe(0);

    $missingScan = $service->scan();

    expect($missingScan['directory_exists'])->toBeFalse()
        ->and($missingScan['last_error'])->toContain($missingPath);

    $tempDir = autoImportCreateTempDir();

    try {
        config(['services.auto_import.dir' => $tempDir]);

        for ($i = 1; $i <= 6; $i++) {
            file_put_contents(
                sprintf('%s/%02d_run.fit', $tempDir, $i),
                autoImportCreateSyntheticRun(fitStartTs: 1147292100 + ($i * 3600)),
            );
        }

        expect($service->statusPayload()['pending_files'])->toBe(0);

        $settlingScan = $service->scan(minFileAgeSeconds: 0);

        expect($settlingScan['created'])->toBe(0)
            ->and($settlingScan['deferred_recent'])->toBe(6)
            ->and($service->statusPayload()['pending_files'])->toBe(6);

        $scanResult = $service->scan(minFileAgeSeconds: 0);

        expect($scanResult['created'])->toBe(6)
            ->and($scanResult['recent_imports'])->toHaveCount(5)
            ->and($scanResult['recent_imports'][0]['filename'])->toBe('06_run.fit')
            ->and($service->statusPayload()['pending_files'])->toBe(0)
            ->and($service->statusPayload()['imported_total'])->toBe(6);
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('falls back to keeping files in place when archive mode scans a read-only directory', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Read-only directory permission test requires non-root execution.');
    }

    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        $filePath = $tempDir.'/readonly_run.fit';
        file_put_contents($filePath, autoImportCreateSyntheticRun());
        chmod($tempDir, 0555);

        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'archive',
            'services.auto_import.min_file_age_seconds' => 0,
        ]);
        app(AppSettings::class)->flushMemo();

        $service = app(AutoImportService::class);
        $first = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($first['created'])->toBe(1)
            ->and($first['failed'])->toBe(0)
            ->and(file_exists($filePath))->toBeTrue()
            ->and(is_dir($tempDir.'/processed'))->toBeFalse()
            ->and(Activity::query()->where('provider', 'fit_upload')->count())->toBe(1);

        $second = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($second['created'])->toBe(0)
            ->and($second['skipped'])->toBe(1)
            ->and($second['failed'])->toBe(0);
    } finally {
        chmod($tempDir, 0777);
        autoImportRemoveDir($tempDir);
    }
});

it('skips the scan without importing while another scan holds the lock', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();
    $lock = Cache::lock(AutoImportService::SCAN_LOCK_KEY, 60);

    try {
        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'keep']);
        file_put_contents($tempDir.'/run.fit', autoImportCreateSyntheticRun());

        expect($lock->get())->toBeTrue();

        $service = app(AutoImportService::class);
        $blocked = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($blocked['busy'])->toBeTrue()
            ->and($blocked['created'])->toBe(0)
            ->and(Activity::query()->count())->toBe(0);

        $lock->release();

        $unblocked = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($unblocked['busy'])->toBeFalse()
            ->and($unblocked['created'])->toBe(1);
    } finally {
        $lock->release();
        autoImportRemoveDir($tempDir);
    }
});

it('treats a unique constraint race with another importer as a duplicate and archives the file as processed', function () {
    Storage::fake('local');

    $tempDir = autoImportCreateTempDir();

    try {
        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'archive']);
        file_put_contents($tempDir.'/raced.fit', autoImportCreateSyntheticRun());

        $this->mock(TelemetryPersistenceService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('upsertActivity')->andThrow(new UniqueConstraintViolationException(
                'sqlite',
                'insert into "activities"',
                [],
                new PDOException('UNIQUE constraint failed: activities.provider, activities.external_id'),
            ));
        });

        $result = app(AutoImportService::class)->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($result['skipped'])->toBe(1)
            ->and($result['failed'])->toBe(0)
            ->and($result['last_error'])->toBeNull()
            ->and($result['entries'][0]['message'])->toBe('skipped (duplicate)')
            ->and(file_exists($tempDir.'/processed/raced.fit'))->toBeTrue()
            ->and(file_exists($tempDir.'/failed/raced.fit'))->toBeFalse();
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('waits until a file keeps the same size and mtime across scans before importing it, even when the mtime is old', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'archive']);

        $filePath = $tempDir.'/copying.fit';
        $fitBytes = autoImportCreateSyntheticRun();
        $preservedMtime = now()->subDay()->getTimestamp();

        file_put_contents($filePath, substr($fitBytes, 0, 40));
        touch($filePath, $preservedMtime);

        $service = app(AutoImportService::class);
        $partial = $service->scan();

        expect($partial['deferred_recent'])->toBe(1)
            ->and($partial['failed'])->toBe(0)
            ->and($partial['entries'][0]['message'])->toBe('deferred (waiting for file to settle)')
            ->and(file_exists($filePath))->toBeTrue();

        file_put_contents($filePath, $fitBytes);
        touch($filePath, $preservedMtime);

        $grown = $service->scan();

        expect($grown['deferred_recent'])->toBe(1)
            ->and($grown['failed'])->toBe(0)
            ->and(Activity::query()->count())->toBe(0);

        $settled = $service->scan();

        expect($settled['created'])->toBe(1)
            ->and($settled['deferred_recent'])->toBe(0)
            ->and(file_exists($tempDir.'/processed/copying.fit'))->toBeTrue();
    } finally {
        autoImportRemoveDir($tempDir);
    }
});

it('skips unchanged previously imported files in keep mode without reading them again', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Unreadable file permission test requires non-root execution.');
    }

    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();

    try {
        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'keep']);

        $filePath = $tempDir.'/run.zip';
        file_put_contents($filePath, autoImportZipBytes(autoImportCreateSyntheticRun()));

        $service = app(AutoImportService::class);

        expect($service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false)['created'])->toBe(1);

        chmod($filePath, 0000);

        $rescan = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($rescan['skipped'])->toBe(1)
            ->and($rescan['failed'])->toBe(0);
    } finally {
        @chmod($tempDir.'/run.zip', 0644);
        autoImportRemoveDir($tempDir);
    }
});

it('ignores symlinked files and directories in the auto-import folder', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = autoImportCreateTempDir();
    $outsideDir = autoImportCreateTempDir();

    try {
        file_put_contents($outsideDir.'/secret.fit', autoImportCreateSyntheticRun());
        symlink($outsideDir.'/secret.fit', $tempDir.'/linked.fit');
        symlink($outsideDir, $tempDir.'/linked-dir');

        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'archive']);

        $service = app(AutoImportService::class);

        expect($service->discoverFiles($tempDir))->toBe([]);

        $result = $service->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($result['created'])->toBe(0)
            ->and($result['failed'])->toBe(0)
            ->and(Activity::query()->count())->toBe(0)
            ->and(is_link($tempDir.'/linked.fit'))->toBeTrue();
    } finally {
        @unlink($tempDir.'/linked.fit');
        @unlink($tempDir.'/linked-dir');
        autoImportRemoveDir($tempDir);
        autoImportRemoveDir($outsideDir);
    }
});

it('rolls back the activity, archive, and enrichment jobs when persisting the stream fails, without exposing the raw error', function () {
    Storage::fake('local');
    config(['queue.default' => 'database']);

    $tempDir = autoImportCreateTempDir();

    try {
        config(['services.auto_import.dir' => $tempDir, 'services.auto_import.mode' => 'keep']);

        $fitBytes = autoImportCreateSyntheticRun();
        file_put_contents($tempDir.'/run.fit', $fitBytes);

        ActivityStream::creating(function (): never {
            throw new RuntimeException('SQLSTATE[HY000]: disk I/O error at /var/lib/secret.sqlite');
        });

        $result = app(AutoImportService::class)->scan(minFileAgeSeconds: 0, requireStableAcrossScans: false);

        expect($result['failed'])->toBe(1)
            ->and($result['last_error'])->toContain('run.fit')
            ->and($result['last_error'])->not->toContain('secret.sqlite')
            ->and(Activity::query()->count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0);

        Storage::disk('local')->assertMissing('fit_upload/fits/'.hash('sha256', $fitBytes).'.fit');
    } finally {
        autoImportRemoveDir($tempDir);
    }
});
