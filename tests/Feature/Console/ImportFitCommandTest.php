<?php

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Models\BestEffort;
use App\Models\SyncRun;
use App\Services\Garmin\ActivityStreamSyncService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * @param  list<string>  $messages
 */
function importFitBuildBinary(array $messages, int $headerSize = 14): string
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
function importFitDefinition(
    int $localType,
    int $globalMsg,
    array $fields,
    bool $bigEndian = false,
): string {
    $header = 0x40 | ($localType & 0x0F);

    $bytes = chr($header)
        .chr(0)
        .chr($bigEndian ? 1 : 0)
        .pack($bigEndian ? 'n' : 'v', $globalMsg)
        .chr(count($fields));

    foreach ($fields as [$num, $size, $baseType]) {
        $bytes .= chr($num).chr($size).chr($baseType);
    }

    return $bytes;
}

function importFitData(int $localType, string $payload): string
{
    return chr($localType & 0x0F).$payload;
}

function importFitDegreesToSemicircles(float $degrees): int
{
    return (int) round($degrees * (2147483648.0 / 180.0));
}

/**
 * Build a synthetic outdoor running FIT binary with file_id, lap, GPS+HR records, and session messages.
 *
 * Default $fitStartTs = 1147292100 corresponds to 2026-05-09T20:15:00Z (2026-05-10 08:15:00 Pacific/Auckland).
 */
function importFitCreateSyntheticRun(
    int $fitStartTs = 1147292100,
    float $distanceMeters = 5200.0,
    float $speedMps = 4.0,
    int $sportId = 1,
    int $subSportId = 3,
): string {
    $durationSec = (int) round($distanceMeters / $speedMps);
    $messages = [];

    // file_id (MSG 0): manufacturer=1 (Garmin), product=4257 (Forerunner 965)
    $messages[] = importFitDefinition(0, 0, [
        [0, 1, 0x00],
        [1, 2, 0x84],
        [2, 2, 0x84],
    ]);
    $messages[] = importFitData(0, chr(4).pack('v', 1).pack('v', 4257));

    // lap (MSG 19)
    $messages[] = importFitDefinition(1, 19, [
        [7, 4, 0x86],   // total_elapsed_time (/1000)
        [8, 4, 0x86],   // total_timer_time (/1000)
        [9, 4, 0x86],   // total_distance (/100)
        [15, 1, 0x02],  // avg_hr
        [110, 4, 0x86], // enhanced_avg_speed (/1000)
    ]);
    $messages[] = importFitData(
        1,
        pack('V', $durationSec * 1000)
        .pack('V', $durationSec * 1000)
        .pack('V', (int) round($distanceMeters * 100))
        .chr(156)
        .pack('V', (int) round($speedMps * 1000)),
    );

    // record (MSG 20)
    $messages[] = importFitDefinition(2, 20, [
        [253, 4, 0x86], // timestamp
        [0, 4, 0x85],   // lat
        [1, 4, 0x85],   // lng
        [3, 1, 0x02],   // hr
        [4, 1, 0x02],   // cadence (single foot)
        [5, 4, 0x86],   // distance (/100)
        [73, 4, 0x86],  // enhanced_speed (/1000)
        [78, 4, 0x86],  // enhanced_altitude
    ]);

    $stepSec = 10;

    for ($s = 0; $s <= $durationSec; $s += $stepSec) {
        $dist = min($distanceMeters, $s * $speedMps);
        $lat = -36.8485 + ($s * 0.00002);
        $lng = 174.7633 + ($s * 0.00001);
        $alt = 120.0 + ($dist * 0.01);

        $messages[] = importFitData(
            2,
            pack('V', $fitStartTs + $s)
            .pack('V', importFitDegreesToSemicircles($lat))
            .pack('V', importFitDegreesToSemicircles($lng))
            .chr(155)
            .chr(87)
            .pack('V', (int) round($dist * 100))
            .pack('V', (int) round($speedMps * 1000))
            .pack('V', (int) round(($alt + 500.0) * 5.0)),
        );
    }

    // session (MSG 18)
    $messages[] = importFitDefinition(3, 18, [
        [2, 4, 0x86],   // start_time
        [5, 1, 0x00],   // sport_id
        [6, 1, 0x00],   // sub_sport_id
        [7, 4, 0x86],   // total_elapsed_time (/1000)
        [8, 4, 0x86],   // total_timer_time (/1000)
        [9, 4, 0x86],   // total_distance (/100)
        [11, 2, 0x84],  // total_calories
        [16, 1, 0x02],  // avg_hr
        [17, 1, 0x02],  // max_hr
        [18, 1, 0x02],  // avg_cadence
        [22, 2, 0x84],  // total_ascent
        [23, 2, 0x84],  // total_descent
        [124, 4, 0x86], // enhanced_avg_speed (/1000)
        [125, 4, 0x86], // enhanced_max_speed (/1000)
    ]);

    $messages[] = importFitData(
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

    return importFitBuildBinary($messages);
}

function importFitZipBytes(string $contents, string $entryName = 'activity.fit'): string
{
    $path = tempnam(sys_get_temp_dir(), 'splitline_import_zip_');
    expect($path)->toBeString();

    $zip = new ZipArchive;
    expect($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();
    $zip->addFromString('README.txt', 'synthetic-fixture');
    $zip->addFromString($entryName, $contents);
    $zip->close();

    $bytes = file_get_contents($path);
    @unlink($path);

    expect($bytes)->toBeString();

    return $bytes;
}

function importFitCreateTempDir(): string
{
    $dir = sys_get_temp_dir().'/splitline_import_fit_test_'.bin2hex(random_bytes(8));
    mkdir($dir, 0777, true);

    return $dir;
}

function importFitRemoveDir(string $dir): void
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

/**
 * @param  list<int>  $timestamps
 * @return array{hourly: array<string, list<float|int|null>>}
 */
function importFitOpenMeteoLocation(array $timestamps): array
{
    $count = count($timestamps);

    return [
        'hourly' => [
            'time' => $timestamps,
            'temperature_2m' => array_fill(0, $count, 15.0),
            'relative_humidity_2m' => array_fill(0, $count, 68.0),
            'apparent_temperature' => array_fill(0, $count, 13.5),
            'dew_point_2m' => array_fill(0, $count, 9.5),
            'wind_speed_10m' => array_fill(0, $count, 12.0),
            'wind_gusts_10m' => array_fill(0, $count, 18.0),
            'wind_direction_10m' => array_fill(0, $count, 90.0),
            'weather_code' => array_fill(0, $count, 1),
            'precipitation' => array_fill(0, $count, 0.0),
            'cloud_cover' => array_fill(0, $count, 25.0),
            'surface_pressure' => array_fill(0, $count, 1018.0),
        ],
    ];
}

it('imports a raw .fit file, persists activity, stream, best efforts, and archive, respects display timezone, and dispatches only weather sync', function () {
    Http::preventStrayRequests();
    Http::fake();
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class]);

    config([
        'app.display_timezone' => 'Pacific/Auckland',
        'services.garmin.auto_sync_streams' => true,
        'services.open_meteo.enabled' => true,
        'services.open_meteo.auto_sync' => true,
    ]);
    app(AppSettings::class)->flushMemo();

    $tempDir = importFitCreateTempDir();

    try {
        $fitBytes = importFitCreateSyntheticRun(
            fitStartTs: 1147292100, // 2026-05-09T20:15:00Z -> 2026-05-10 08:15:00 Pacific/Auckland
            distanceMeters: 5200.0,
            speedMps: 4.0,
        );
        $expectedExternalId = hash('sha256', $fitBytes);
        $fitPath = $tempDir.'/morning_trail.fit';
        file_put_contents($fitPath, $fitBytes);

        $this->artisan('import:fit', ['paths' => [$fitPath]])
            ->expectsOutputToContain('Created: 1, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $activity = Activity::query()->first();

        expect($activity)->not->toBeNull()
            ->and($activity->provider)->toBe('fit_upload')
            ->and($activity->garmin_activity_id)->toBeNull()
            ->and($activity->external_id)->toBe($expectedExternalId)
            ->and($activity->name)->toBe('Trail Running 2026-05-10')
            ->and($activity->activity_type_key)->toBe('trail_running')
            ->and($activity->sport_category)->toBe('running')
            ->and($activity->sport_discipline)->toBe('trail')
            ->and($activity->is_indoor)->toBeFalse()
            ->and($activity->started_at_utc->format('Y-m-d H:i:s'))->toBe('2026-05-09 20:15:00')
            ->and($activity->started_at_local->format('Y-m-d H:i:s'))->toBe('2026-05-10 08:15:00')
            ->and($activity->distance_meters)->toBe(5200.0)
            ->and($activity->duration_seconds)->toBe(1300.0)
            ->and($activity->streams_synced_at)->not->toBeNull();

        $stream = ActivityStream::query()->where('activity_id', $activity->id)->first();

        expect($stream)->not->toBeNull()
            ->and($stream->series)->not->toBeEmpty()
            ->and($stream->polyline)->not->toBeEmpty()
            ->and($stream->laps)->toHaveCount(1)
            ->and($stream->km_splits)->toHaveCount(6);

        $bestEfforts = BestEffort::query()
            ->where('activity_id', $activity->id)
            ->orderBy('distance_meters')
            ->get();

        expect($bestEfforts->pluck('distance_key')->all())->toBe([
            '400m',
            'half_mile',
            '1k',
            '1mi',
            '2mi',
            '5k',
        ]);

        $fiveK = $bestEfforts->firstWhere('distance_key', '5k');
        expect($fiveK)->not->toBeNull()
            ->and($fiveK->source)->toBe('fit_stream')
            ->and($fiveK->elapsed_seconds)->toBe(1250.0);

        $archivePath = "fit_upload/fits/{$expectedExternalId}.fit";
        Storage::disk('local')->assertExists($archivePath);
        expect(Storage::disk('local')->get($archivePath))->toBe($fitBytes);

        Queue::assertPushed(SyncActivityWeatherJob::class, 1);
        Queue::assertPushedOn(
            'weather',
            SyncActivityWeatherJob::class,
            fn (SyncActivityWeatherJob $job): bool => $job->activityId === $activity->id,
        );
        Queue::assertNotPushed(SyncActivityStreamJob::class);

        Http::assertNothingSent();
    } finally {
        importFitRemoveDir($tempDir);
    }
});

it('skips a .zip wrapping the exact same .fit bytes as a duplicate', function () {
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class]);
    config(['app.display_timezone' => 'Pacific/Auckland']);

    $tempDir = importFitCreateTempDir();

    try {
        $fitBytes = importFitCreateSyntheticRun();
        $expectedExternalId = hash('sha256', $fitBytes);
        $fitPath = $tempDir.'/original.fit';
        $zipPath = $tempDir.'/wrapped.zip';

        file_put_contents($fitPath, $fitBytes);
        file_put_contents($zipPath, importFitZipBytes($fitBytes, '987654_ACTIVITY.FIT'));

        $this->artisan('import:fit', ['paths' => [$fitPath]])
            ->expectsOutputToContain('Created: 1, Skipped: 0, Failed: 0')
            ->assertSuccessful();

        $this->artisan('import:fit', ['paths' => [$zipPath]])
            ->expectsOutputToContain('Created: 0, Skipped: 1, Failed: 0')
            ->assertSuccessful();

        expect(Activity::query()->count())->toBe(1)
            ->and(ActivityStream::query()->count())->toBe(1);

        Storage::disk('local')->assertExists("fit_upload/fits/{$expectedExternalId}.fit");
        Storage::disk('local')->assertMissing("fit_upload/fits/{$expectedExternalId}.zip");
    } finally {
        importFitRemoveDir($tempDir);
    }
});

it('recursively imports .fit and .zip files from directories while isolating corrupt files', function () {
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class]);
    config(['app.display_timezone' => 'Pacific/Auckland']);

    $tempDir = importFitCreateTempDir();

    try {
        mkdir($tempDir.'/runs/2026', 0777, true);
        mkdir($tempDir.'/zips/nested', 0777, true);

        $fitOneBytes = importFitCreateSyntheticRun(fitStartTs: 1147292100, distanceMeters: 2000.0);
        $fitTwoBytes = importFitCreateSyntheticRun(fitStartTs: 1147378500, distanceMeters: 3000.0);
        $zipTwoBytes = importFitZipBytes($fitTwoBytes, 'SECOND_ACTIVITY.FIT');

        $hashOne = hash('sha256', $fitOneBytes);
        $hashTwo = hash('sha256', $fitTwoBytes);

        file_put_contents($tempDir.'/runs/2026/first.fit', $fitOneBytes);
        file_put_contents($tempDir.'/zips/nested/second.zip', $zipTwoBytes);
        file_put_contents($tempDir.'/runs/corrupt.fit', 'not-a-valid-fit-binary');
        file_put_contents($tempDir.'/README.txt', 'ignored non-fit file');

        $this->artisan('import:fit', ['paths' => [$tempDir]])
            ->expectsOutputToContain('Created: 2, Skipped: 0, Failed: 1')
            ->assertSuccessful();

        expect(Activity::query()->count())->toBe(2)
            ->and(Activity::query()->where('provider', 'fit_upload')->pluck('external_id')->all())
            ->toEqualCanonicalizing([$hashOne, $hashTwo]);

        Storage::disk('local')->assertExists("fit_upload/fits/{$hashOne}.fit");
        expect(Storage::disk('local')->get("fit_upload/fits/{$hashOne}.fit"))->toBe($fitOneBytes);

        Storage::disk('local')->assertExists("fit_upload/fits/{$hashTwo}.zip");
        expect(Storage::disk('local')->get("fit_upload/fits/{$hashTwo}.zip"))->toBe($zipTwoBytes);

        // Failing every requested input exits with a non-zero status code
        $this->artisan('import:fit', ['paths' => [$tempDir.'/runs/corrupt.fit']])
            ->expectsOutputToContain('Created: 0, Skipped: 0, Failed: 1')
            ->assertFailed();
    } finally {
        importFitRemoveDir($tempDir);
    }
});

it('re-parses stored raw .fit uploads offline without hitting Garmin and resolves non-numeric external_id in garmin:sync-streams and weather:sync', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-12 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Queue::fake([SyncActivityStreamJob::class, SyncActivityWeatherJob::class]);

    config([
        'app.display_timezone' => 'Pacific/Auckland',
        'services.garmin.key' => 'unused-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'services.open_meteo.enabled' => true,
        'services.open_meteo.auto_sync' => true,
    ]);

    $timestamps = [
        CarbonImmutable::parse('2026-05-09 19:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-05-09 20:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-05-09 21:00:00', 'UTC')->getTimestamp(),
        CarbonImmutable::parse('2026-05-09 22:00:00', 'UTC')->getTimestamp(),
    ];

    Http::fake(function (Request $request) use ($timestamps) {
        if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $coords = explode(',', (string) ($query['latitude'] ?? ''));

            if (count($coords) === 1) {
                return Http::response(importFitOpenMeteoLocation($timestamps), 200);
            }

            return Http::response(
                array_map(static fn (): array => importFitOpenMeteoLocation($timestamps), $coords),
                200,
            );
        }

        return Http::response('Unexpected external request', 500);
    });

    $tempDir = importFitCreateTempDir();

    try {
        $fitBytes = importFitCreateSyntheticRun(
            fitStartTs: 1147292100, // 2026-05-09T20:15:00Z
            distanceMeters: 5200.0,
            speedMps: 4.0,
        );
        $externalId = hash('sha256', $fitBytes);
        $fitPath = $tempDir.'/reparse.fit';
        file_put_contents($fitPath, $fitBytes);

        $this->artisan('import:fit', ['paths' => [$fitPath]])
            ->assertSuccessful();

        $activity = Activity::query()
            ->where('provider', 'fit_upload')
            ->where('external_id', $externalId)
            ->firstOrFail();

        // 1. Re-parse directly via ActivityStreamSyncService::sync() offline
        ActivityStream::query()->where('activity_id', $activity->id)->delete();
        BestEffort::query()->where('activity_id', $activity->id)->delete();
        $activity->update(['streams_synced_at' => null]);

        $streamSyncService = app(ActivityStreamSyncService::class);
        $reparsedStream = $streamSyncService->sync($activity->fresh());

        expect($reparsedStream)->toBeInstanceOf(ActivityStream::class)
            ->and($reparsedStream->series)->not->toBeEmpty()
            ->and(BestEffort::query()->where('activity_id', $activity->id)->count())->toBe(6);

        // 2. Recalculate Best Efforts offline from stored raw .fit archive
        BestEffort::query()->where('activity_id', $activity->id)->delete();
        $recalculatedCount = $streamSyncService->recalculateBestEfforts($activity->fresh());

        expect($recalculatedCount)->toBe(6)
            ->and(BestEffort::query()->where('activity_id', $activity->id)->count())->toBe(6);

        // 3. garmin:sync-streams --activity={sha256} resolves non-numeric external_id and re-parses from local archive
        $this->artisan('garmin:sync-streams', ['--activity' => $externalId])
            ->expectsOutputToContain('Garmin stream sync completed.')
            ->assertSuccessful();

        $streamSyncRun = SyncRun::query()->where('type', 'streams')->latest('id')->first();

        expect($streamSyncRun)->not->toBeNull()
            ->and($streamSyncRun->status)->toBe('completed')
            ->and($streamSyncRun->items_synced)->toBe(1);

        // No HTTP calls made to Garmin (or anywhere else) through all three offline stream operations
        Http::assertNothingSent();

        // 4. weather:sync --activity={sha256} resolves non-numeric external_id cleanly
        $this->artisan('weather:sync', ['--activity' => $externalId])
            ->expectsOutputToContain('Weather sync completed.')
            ->assertSuccessful();

        $weather = ActivityWeather::query()->where('activity_id', $activity->id)->first();
        $weatherSyncRun = SyncRun::query()->where('type', 'weather')->latest('id')->first();

        expect($weather)->not->toBeNull()
            ->and($weather->provider)->toBe('open_meteo')
            ->and($weather->sampling_mode)->toBe('route')
            ->and($weatherSyncRun)->not->toBeNull()
            ->and($weatherSyncRun->status)->toBe('completed')
            ->and($weatherSyncRun->items_synced)->toBe(1);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'garmin'));
    } finally {
        importFitRemoveDir($tempDir);
    }
});
