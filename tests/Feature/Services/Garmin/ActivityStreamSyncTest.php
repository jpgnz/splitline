<?php

use App\Jobs\SyncActivityStreamJob;
use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use App\Models\SyncRun;
use App\Services\Garmin\ActivityStreamSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
});

function streamSyncFakeJwt(): string
{
    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => CarbonImmutable::now()->addHours(24)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return "{$header}.{$payload}.sig";
}

function configureGarminForStreamTest(): void
{
    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);
}

/**
 * Create a realistic zipped FIT binary for a 5.2 km run or ride at the specified speed.
 */
function createZippedActivityFit(float $distanceMeters = 5200.0, float $speedMps = 4.0): string
{
    $baseFitTs = 1100000000;
    $messages = [];

    // Lap definition (local 0, global 19)
    $messages[] = chr(0x40).chr(0).chr(0).pack('v', 19).chr(5)
        .chr(7).chr(4).chr(0x86)  // total_elapsed_time
        .chr(8).chr(4).chr(0x86)  // total_timer_time
        .chr(9).chr(4).chr(0x86)  // total_distance
        .chr(15).chr(1).chr(0x02) // avg_hr
        .chr(110).chr(4).chr(0x86); // enhanced_avg_speed

    $durationSec = (int) round($distanceMeters / $speedMps);
    $messages[] = chr(0)
        .pack('V', $durationSec * 1000)
        .pack('V', $durationSec * 1000)
        .pack('V', (int) round($distanceMeters * 100))
        .chr(160)
        .pack('V', (int) round($speedMps * 1000));

    // Record definition (local 1, global 20)
    $messages[] = chr(0x41).chr(0).chr(0).pack('v', 20).chr(7)
        .chr(253).chr(4).chr(0x86) // timestamp
        .chr(0).chr(4).chr(0x85)   // lat
        .chr(1).chr(4).chr(0x85)   // lng
        .chr(3).chr(1).chr(0x02)   // hr
        .chr(5).chr(4).chr(0x86)   // distance
        .chr(73).chr(4).chr(0x86)  // enhanced_speed
        .chr(78).chr(4).chr(0x86); // enhanced_altitude

    $stepSec = 5;
    for ($s = 0; $s <= $durationSec; $s += $stepSec) {
        $dist = min($distanceMeters, $s * $speedMps);
        $lat = -36.8485 + ($s * 0.00002);
        $lng = 174.7633 + ($s * 0.00001);
        $alt = 50.0 + ($dist * 0.01);

        $messages[] = chr(1)
            .pack('V', $baseFitTs + $s)
            .pack('V', (int) round($lat * (2147483648.0 / 180.0)))
            .pack('V', (int) round($lng * (2147483648.0 / 180.0)))
            .chr(158)
            .pack('V', (int) round($dist * 100))
            .pack('V', (int) round($speedMps * 1000))
            .pack('V', (int) round(($alt + 500.0) * 5.0));
    }

    $payload = implode('', $messages);
    $fitBytes = chr(14).chr(0x20).pack('v', 2140).pack('V', strlen($payload)).'.FIT'.pack('v', 0).$payload.pack('v', 0);

    $path = tempnam(sys_get_temp_dir(), 'feature_fit_');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('activity.fit', $fitBytes);
    $zip->close();

    $zipBytes = (string) file_get_contents($path);
    @unlink($path);

    return $zipBytes;
}

it('downloads and parses FIT archive, persists stream and best efforts, preserves overrides, and fills missing start coordinates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 555001,
        'sport_category' => 'running',
        'is_indoor' => false,
        'start_lat' => null,
        'start_lng' => null,
        'streams_synced_at' => null,
    ]);

    BestEffort::factory()->create([
        'activity_id' => $activity->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1300.0,
        'source' => 'garmin_split',
        'override_seconds' => 1242.5,
        'is_excluded' => true,
    ]);

    $zipBytes = createZippedActivityFit(distanceMeters: 5200.0, speedMps: 4.0);

    Http::fake(function (Request $request) use ($zipBytes) {
        if (str_contains($request->url(), '/personalapitoken-service/')) {
            return Http::response(streamSyncFakeJwt(), 200);
        }

        if (str_contains($request->url(), '/download-service/files/activity/555001')) {
            return Http::response($zipBytes, 200, ['Content-Type' => 'application/zip']);
        }

        return Http::response('', 404);
    });

    $stream = app(ActivityStreamSyncService::class)->sync($activity);

    expect($stream)->toBeInstanceOf(ActivityStream::class)
        ->and($stream->activity_id)->toBe($activity->id)
        ->and($stream->series)->not->toBeEmpty()
        ->and($stream->polyline)->not->toBeEmpty()
        ->and($stream->laps)->toHaveCount(1)
        ->and($stream->km_splits)->toHaveCount(6); // 5 full km + 200m partial

    $activity->refresh();
    expect($activity->streams_synced_at)->not->toBeNull()
        ->and($activity->start_lat)->toBeGreaterThan(-36.85)->toBeLessThan(-36.84)
        ->and($activity->start_lng)->toBeGreaterThan(174.76)->toBeLessThan(174.77);

    $fiveK = BestEffort::query()
        ->where('activity_id', $activity->id)
        ->where('distance_key', '5k')
        ->first();

    expect($fiveK)->not->toBeNull()
        ->and($fiveK->source)->toBe('fit_stream')
        ->and($fiveK->elapsed_seconds)->toBe(1250.0)
        ->and($fiveK->override_seconds)->toBe(1242.5)
        ->and($fiveK->is_excluded)->toBeTrue();

    expect(BestEffort::query()->where('activity_id', $activity->id)->count())->toBe(6);

    Storage::disk('local')->assertExists("garmin/fits/{$activity->garmin_activity_id}.zip");
    expect(Storage::disk('local')->get("garmin/fits/{$activity->garmin_activity_id}.zip"))->toBe($zipBytes);
});

it('reuses locally cached FIT archives on subsequent syncs unless forceDownload is requested', function () {
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 555010,
        'sport_category' => 'running',
        'is_indoor' => false,
    ]);

    $zipBytes = createZippedActivityFit(distanceMeters: 2000.0, speedMps: 4.0);
    $downloadRequests = 0;

    Http::fake(function (Request $request) use ($zipBytes, &$downloadRequests) {
        if (str_contains($request->url(), '/personalapitoken-service/')) {
            return Http::response(streamSyncFakeJwt(), 200);
        }

        if (str_contains($request->url(), '/download-service/files/activity/555010')) {
            $downloadRequests++;

            return Http::response($zipBytes, 200, ['Content-Type' => 'application/zip']);
        }

        return Http::response('', 404);
    });

    $service = app(ActivityStreamSyncService::class);
    $service->sync($activity);
    expect($downloadRequests)->toBe(1);

    // Second sync reuses local FIT archive without calling Garmin again
    $service->sync($activity);
    expect($downloadRequests)->toBe(1);

    // Explicit forceDownload hits Garmin again
    $service->sync($activity, forceDownload: true);
    expect($downloadRequests)->toBe(2);
});

it('recalculates and clears road biking best efforts offline when discipline changes', function () {
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $ride = Activity::factory()->otherBiking()->create([
        'garmin_activity_id' => 555020,
        'is_indoor' => false,
        'streams_synced_at' => null,
    ]);

    $zipBytes = createZippedActivityFit(distanceMeters: 10500.0, speedMps: 8.0);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(streamSyncFakeJwt(), 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/555020' => Http::response($zipBytes, 200),
    ]);

    $service = app(ActivityStreamSyncService::class);
    $service->sync($ride);

    // Synced while discipline is 'other' => no road_biking best efforts yet
    expect(BestEffort::query()->where('activity_id', $ride->id)->count())->toBe(0);

    // Reclassify to road => computes road_biking 5k and 10k offline from cached FIT archive
    $ride->update(['discipline_override' => 'road']);
    $upserted = $service->recalculateBestEfforts($ride->fresh());

    expect($upserted)->toBe(2)
        ->and(
            BestEffort::query()
                ->where('activity_id', $ride->id)
                ->where('sport', 'road_biking')
                ->pluck('distance_key')
                ->all(),
        )->toEqualCanonicalizing(['5k', '10k']);

    // Reclassify to mtb => removes stale road_biking best efforts
    $ride->update(['discipline_override' => 'mtb']);
    $service->recalculateBestEfforts($ride->fresh());

    expect(BestEffort::query()->where('activity_id', $ride->id)->count())->toBe(0);

    // Fallback: when local archive is absent, recomputes road_biking efforts from stored stream series
    Storage::disk('local')->delete($service->archivePath($ride));
    $ride->update(['discipline_override' => 'road']);
    $fallbackCount = $service->recalculateBestEfforts($ride->fresh());

    expect($fallbackCount)->toBe(2)
        ->and(
            BestEffort::query()
                ->where('activity_id', $ride->id)
                ->where('sport', 'road_biking')
                ->count(),
        )->toBe(2);
});

it('executes SyncActivityStreamJob for valid activities and safely skips missing activities', function () {
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 555002,
        'sport_category' => 'running',
        'is_indoor' => false,
    ]);

    $zipBytes = createZippedActivityFit(distanceMeters: 1200.0, speedMps: 4.0);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(streamSyncFakeJwt(), 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/555002' => Http::response($zipBytes, 200),
    ]);

    (new SyncActivityStreamJob($activity->id))->handle(app(ActivityStreamSyncService::class));
    (new SyncActivityStreamJob(999999))->handle(app(ActivityStreamSyncService::class));

    expect($activity->refresh()->streams_synced_at)->not->toBeNull()
        ->and(ActivityStream::query()->where('activity_id', $activity->id)->exists())->toBeTrue();
});

it('syncs all non-manual activities newest-first by default via garmin:sync-streams and records a SyncRun', function () {
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $olderRun = Activity::factory()->create([
        'garmin_activity_id' => 600001,
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'streams_synced_at' => null,
    ]);

    $newestRoad = Activity::factory()->roadBiking()->create([
        'garmin_activity_id' => 600002,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'streams_synced_at' => null,
    ]);

    $mtb = Activity::factory()->mountainBiking()->create([
        'garmin_activity_id' => 600003,
        'started_at_local' => CarbonImmutable::parse('2026-11-20 08:00:00'),
        'streams_synced_at' => null,
    ]);

    // Generic cycling and walking should now be included by default
    $genericCycling = Activity::factory()->otherBiking()->create([
        'garmin_activity_id' => 600004,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 10:00:00'),
        'streams_synced_at' => null,
    ]);
    $walk = Activity::factory()->create([
        'garmin_activity_id' => 600005,
        'sport_category' => 'walking',
        'activity_type_key' => 'walking',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 11:00:00'),
        'streams_synced_at' => null,
    ]);

    // Manual activities should be skipped in batch sync
    $manualActivity = Activity::factory()->otherBiking()->create([
        'garmin_activity_id' => 600007,
        'is_manual' => true,
        'started_at_local' => CarbonImmutable::parse('2026-11-21 11:30:00'),
        'streams_synced_at' => null,
    ]);

    // Already synced (should be skipped without --force)
    $alreadySynced = Activity::factory()->create([
        'garmin_activity_id' => 600006,
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 12:00:00'),
        'streams_synced_at' => CarbonImmutable::parse('2026-11-21 13:00:00'),
    ]);

    $zipBytes = createZippedActivityFit(distanceMeters: 5200.0, speedMps: 5.0);

    Http::fake(function (Request $request) use ($zipBytes) {
        if (str_contains($request->url(), '/personalapitoken-service/')) {
            return Http::response(streamSyncFakeJwt(), 200);
        }

        if (str_contains($request->url(), '/download-service/files/activity/')) {
            return Http::response($zipBytes, 200);
        }

        return Http::response('', 404);
    });

    // Limit to 2 => picks walk (Nov 21 11:00) and genericCycling (Nov 21 10:00), skipping manual and alreadySynced
    $this->artisan('garmin:sync-streams', ['--limit' => 2])
        ->assertSuccessful();

    expect($walk->refresh()->streams_synced_at)->not->toBeNull()
        ->and($genericCycling->refresh()->streams_synced_at)->not->toBeNull()
        ->and($newestRoad->refresh()->streams_synced_at)->toBeNull()
        ->and($manualActivity->refresh()->streams_synced_at)->toBeNull();

    $syncRun = SyncRun::query()->where('type', 'streams')->latest('id')->first();
    expect($syncRun)->not->toBeNull()
        ->and($syncRun->status)->toBe('completed')
        ->and($syncRun->items_synced)->toBe(2)
        ->and($syncRun->finished_at)->not->toBeNull();

    // Without --limit => syncs all remaining non-manual activities (newestRoad, mtb, olderRun)
    $this->artisan('garmin:sync-streams')
        ->assertSuccessful();

    expect($newestRoad->refresh()->streams_synced_at)->not->toBeNull()
        ->and($mtb->refresh()->streams_synced_at)->not->toBeNull()
        ->and($olderRun->refresh()->streams_synced_at)->not->toBeNull()
        ->and($manualActivity->refresh()->streams_synced_at)->toBeNull();

    // --activity by Garmin ID still targets a specific activity
    $this->artisan('garmin:sync-streams', ['--activity' => '600001'])
        ->assertSuccessful();

    // --force + --queue + --redownload dispatches jobs with forceDownload=true onto the streams queue
    Queue::fake([SyncActivityStreamJob::class]);

    $this->artisan('garmin:sync-streams', ['--force' => true, '--redownload' => true, '--limit' => 2, '--queue' => true])
        ->assertSuccessful();

    Queue::assertPushed(SyncActivityStreamJob::class, 2);
    Queue::assertPushedOn(
        'streams',
        SyncActivityStreamJob::class,
        fn (SyncActivityStreamJob $job): bool => $job->activityId === $alreadySynced->id && $job->forceDownload === true,
    );

    // Invalid --limit fails validation
    $this->artisan('garmin:sync-streams', ['--limit' => '0'])
        ->assertFailed();
});

it('records a failed SyncRun when stream download fails and errors on missing --activity IDs', function () {
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 600099,
        'sport_category' => 'running',
        'streams_synced_at' => null,
    ]);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(streamSyncFakeJwt(), 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/600099' => Http::response('Not found', 404),
    ]);

    $this->artisan('garmin:sync-streams', ['--activity' => (string) $activity->id])
        ->assertFailed();

    $syncRun = SyncRun::query()->where('type', 'streams')->latest('id')->first();
    expect($syncRun)->not->toBeNull()
        ->and($syncRun->status)->toBe('failed')
        ->and($syncRun->items_synced)->toBe(0)
        ->and($syncRun->error_message)->toContain('600099');

    $this->artisan('garmin:sync-streams', ['--activity' => '99999999'])
        ->assertFailed();
});

it('releases SyncActivityStreamJob back to the queue when Garmin rate limits persist after retries', function () {
    $this->freezeTime();
    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();
    configureGarminForStreamTest();

    config(['services.garmin.rate_limit.max_retries' => 0]);

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 600123,
        'sport_category' => 'running',
        'streams_synced_at' => null,
    ]);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(streamSyncFakeJwt(), 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/600123' => Http::response([
            'count' => 101,
            'limit' => 100,
            'retryInSeconds' => 14,
        ], 429),
    ]);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('release')->once()->with(15);

    $job = (new SyncActivityStreamJob($activity->id))->setJob($queueJob);
    $job->handle(app(ActivityStreamSyncService::class));

    expect($activity->refresh()->streams_synced_at)->toBeNull();
});

it('skips missing FIT archives (HTTP 404) without retrying and deduplicates SyncActivityStreamJob dispatches per activity', function () {
    Http::preventStrayRequests();
    Queue::fake([SyncActivityStreamJob::class]);
    configureGarminForStreamTest();

    $activity = Activity::factory()->create([
        'garmin_activity_id' => 600124,
        'sport_category' => 'running',
        'streams_synced_at' => null,
    ]);

    SyncActivityStreamJob::dispatch($activity->id);
    SyncActivityStreamJob::dispatch($activity->id);

    Queue::assertPushed(SyncActivityStreamJob::class, 1);

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response(streamSyncFakeJwt(), 200),
        'https://personal.connectapi.garmin.com/download-service/files/activity/600124' => Http::response('Not found', 404),
    ]);

    (new SyncActivityStreamJob($activity->id))->handle(app(ActivityStreamSyncService::class));

    expect($activity->refresh()->streams_synced_at)->toBeNull();
});
