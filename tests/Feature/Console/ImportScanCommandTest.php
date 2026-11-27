<?php

use App\Jobs\GeocodeActivityLocationJob;
use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Services\Ingestion\AutoImportService;
use App\Support\AppSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<string>  $messages
 */
function importScanCmdBuildFitBinary(array $messages): string
{
    $payload = implode('', $messages);
    $header = chr(14)
        .chr(0x20)
        .pack('v', 2140)
        .pack('V', strlen($payload))
        .'.FIT'
        .pack('v', 0);

    return $header.$payload.pack('v', 0);
}

/**
 * @param  list<array{0: int, 1: int, 2: int}>  $fields
 */
function importScanCmdFitDefinition(int $localType, int $globalMsg, array $fields): string
{
    $bytes = chr(0x40 | ($localType & 0x0F))
        .chr(0)
        .chr(0)
        .pack('v', $globalMsg)
        .chr(count($fields));

    foreach ($fields as [$num, $size, $baseType]) {
        $bytes .= chr($num).chr($size).chr($baseType);
    }

    return $bytes;
}

function importScanCmdSyntheticFit(int $fitStartTs = 1147292100): string
{
    $messages = [];

    $messages[] = importScanCmdFitDefinition(0, 0, [
        [0, 1, 0x00],
        [1, 2, 0x84],
        [2, 2, 0x84],
    ]);
    $messages[] = chr(0).chr(4).pack('v', 1).pack('v', 4257);

    $messages[] = importScanCmdFitDefinition(1, 20, [
        [253, 4, 0x86],
        [3, 1, 0x02],
        [5, 4, 0x86],
        [73, 4, 0x86],
    ]);

    for ($s = 0; $s <= 300; $s += 30) {
        $messages[] = chr(1)
            .pack('V', $fitStartTs + $s)
            .chr(150)
            .pack('V', $s * 400)
            .pack('V', 4000);
    }

    $messages[] = importScanCmdFitDefinition(2, 18, [
        [2, 4, 0x86],
        [5, 1, 0x00],
        [6, 1, 0x00],
        [7, 4, 0x86],
        [8, 4, 0x86],
        [9, 4, 0x86],
    ]);
    $messages[] = chr(2)
        .pack('V', $fitStartTs)
        .chr(1)
        .chr(0)
        .pack('V', 300000)
        .pack('V', 300000)
        .pack('V', 120000);

    return importScanCmdBuildFitBinary($messages);
}

function importScanCmdCreateTempDir(): string
{
    $dir = sys_get_temp_dir().'/splitline_scan_cmd_test_'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);

    return $dir;
}

function importScanCmdRemoveDir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

it('exits cleanly with success when AUTO_IMPORT_DIR is not configured', function () {
    config(['services.auto_import.dir' => null]);

    $this->artisan('import:scan')
        ->expectsOutputToContain('not configured')
        ->assertSuccessful();
});

it('scans with --dir, --mode, and --no-age-check overrides and fails on invalid mode or missing directory', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = importScanCmdCreateTempDir();

    try {
        config([
            'services.auto_import.dir' => null,
            'services.auto_import.mode' => 'keep',
            'services.auto_import.min_file_age_seconds' => 60,
        ]);
        app(AppSettings::class)->flushMemo();

        file_put_contents($tempDir.'/fresh_run.fit', importScanCmdSyntheticFit());

        $this->artisan('import:scan', [
            '--dir' => $tempDir,
            '--mode' => 'archive',
            '--no-age-check' => true,
        ])
            ->expectsOutputToContain('Created: 1, Skipped: 0, Failed: 0, Deferred: 0')
            ->assertSuccessful();

        expect(Activity::query()->where('provider', 'fit_upload')->count())->toBe(1)
            ->and(file_exists($tempDir.'/processed/fresh_run.fit'))->toBeTrue();

        $this->artisan('import:scan', ['--mode' => 'delete'])
            ->expectsOutputToContain('Invalid --mode')
            ->assertFailed();

        $this->artisan('import:scan', ['--dir' => $tempDir.'/does-not-exist'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    } finally {
        importScanCmdRemoveDir($tempDir);
    }
});

it('schedules import:scan every five minutes with withoutOverlapping only when configured', function () {
    $schedule = app(Schedule::class);
    $scanEvents = array_values(array_filter(
        $schedule->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, 'import:scan'),
    ));

    expect($scanEvents)->toHaveCount(1);

    $event = $scanEvents[0];

    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();

    config(['services.auto_import.dir' => null]);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['services.auto_import.dir' => '/data/imports']);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('handles POST /settings/auto-import/scan for unconfigured, missing, and valid auto-import folders', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    config(['services.auto_import.dir' => null]);

    $this->from('/settings')
        ->post(route('settings.auto-import.scan'))
        ->assertRedirect('/settings')
        ->assertSessionHas('status', 'Auto-import folder is not configured. Set AUTO_IMPORT_DIR in your environment.');

    $missingDir = sys_get_temp_dir().'/splitline_missing_controller_dir_'.bin2hex(random_bytes(6));
    config(['services.auto_import.dir' => $missingDir]);

    $this->from('/settings')
        ->post(route('settings.auto-import.scan'))
        ->assertRedirect('/settings')
        ->assertSessionHas('status', "Auto-import folder does not exist: {$missingDir}");

    $tempDir = importScanCmdCreateTempDir();

    try {
        config([
            'services.auto_import.dir' => $tempDir,
            'services.auto_import.mode' => 'keep',
            'services.auto_import.min_file_age_seconds' => 300,
        ]);
        app(AppSettings::class)->flushMemo();

        file_put_contents($tempDir.'/still_copying.fit', importScanCmdSyntheticFit());
        file_put_contents($tempDir.'/manual_scan.fit', importScanCmdSyntheticFit());
        touch($tempDir.'/manual_scan.fit', now()->subMinutes(10)->getTimestamp());

        $this->from('/settings')
            ->post(route('settings.auto-import.scan'))
            ->assertRedirect('/settings')
            ->assertSessionHas('status', 'Scanned import folder: 0 imported, 0 skipped, 0 failed. 2 files are still settling and will be imported on the next scan.');

        expect(Activity::query()->where('provider', 'fit_upload')->count())->toBe(0);

        $this->from('/settings')
            ->post(route('settings.auto-import.scan'))
            ->assertRedirect('/settings')
            ->assertSessionHas('status', 'Scanned import folder: 1 imported, 0 skipped, 0 failed. 1 file is still settling and will be imported on the next scan.');

        expect(Activity::query()->where('provider', 'fit_upload')->count())->toBe(1);
    } finally {
        importScanCmdRemoveDir($tempDir);
    }
});

it('reports a busy scan from both the command and the settings scan button while the scan lock is held', function () {
    Storage::fake('local');
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class, GeocodeActivityLocationJob::class]);

    $tempDir = importScanCmdCreateTempDir();
    $lock = Cache::lock(AutoImportService::SCAN_LOCK_KEY, 60);

    try {
        config(['services.auto_import.dir' => $tempDir]);
        file_put_contents($tempDir.'/locked.fit', importScanCmdSyntheticFit());

        expect($lock->get())->toBeTrue();

        $this->artisan('import:scan', ['--no-age-check' => true])
            ->expectsOutputToContain('already running')
            ->assertSuccessful();

        $this->from('/settings')
            ->post(route('settings.auto-import.scan'))
            ->assertRedirect('/settings')
            ->assertSessionHas('status', 'An auto-import folder scan is already running. Try again in a moment.');

        expect(Activity::query()->count())->toBe(0);
    } finally {
        $lock->release();
        importScanCmdRemoveDir($tempDir);
    }
});
