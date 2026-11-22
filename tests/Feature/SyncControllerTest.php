<?php

use App\Jobs\SyncGarminBackfillJob;
use App\Jobs\SyncGarminJob;
use App\Models\SyncRun;
use App\Services\Garmin\GarminSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('returns idle status with provider configuration state when no sync runs exist', function () {
    config(['services.garmin.key' => '']);

    $this->getJson(route('sync.status'))
        ->assertOk()
        ->assertJson([
            'status' => 'idle',
            'type' => null,
            'items_synced' => 0,
            'last_synced_at' => null,
            'provider_configured' => false,
        ]);

    config(['services.garmin.key' => 'test-garmin-key']);

    $this->getJson(route('sync.status'))
        ->assertOk()
        ->assertJson([
            'status' => 'idle',
            'provider_configured' => true,
        ]);
});

it('rejects sync requests when GARMIN_KEY is not configured', function () {
    Queue::fake([SyncGarminJob::class]);
    config(['services.garmin.key' => '']);

    $expectedMessage = 'Garmin API key is not configured. Set GARMIN_KEY in your environment.';

    $this->postJson(route('sync.store'))
        ->assertUnprocessable()
        ->assertJson([
            'message' => $expectedMessage,
            'status' => 'idle',
            'provider_configured' => false,
        ]);

    $this->from('/')
        ->post(route('sync.store'))
        ->assertRedirect('/')
        ->assertSessionHas('syncStatus', $expectedMessage);

    expect(SyncRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('guards against concurrent running or queued sync runs within ten minutes', function (string $activeStatus) {
    Queue::fake([SyncGarminJob::class]);
    config(['services.garmin.key' => 'test-garmin-key']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    SyncRun::factory()->create([
        'type' => 'incremental',
        'status' => $activeStatus,
        'started_at' => CarbonImmutable::now()->subMinutes(2),
        'finished_at' => null,
    ]);

    $this->postJson(route('sync.store'))
        ->assertOk()
        ->assertJson([
            'already_running' => true,
            'status' => $activeStatus,
            'provider_configured' => true,
        ]);

    expect(SyncRun::query()->count())->toBe(1);
    Queue::assertNotPushed(SyncGarminJob::class);
})->with([
    'running' => ['running'],
    'queued' => ['queued'],
]);

it('queues a SyncGarminJob and returns 202 immediately on POST /sync', function () {
    Queue::fake([SyncGarminJob::class]);
    config(['services.garmin.key' => 'test-garmin-key']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    $this->postJson(route('sync.store'))
        ->assertStatus(202)
        ->assertJson([
            'message' => 'Garmin sync started in the background.',
            'status' => 'running',
            'type' => 'incremental',
            'items_synced' => 0,
            'provider_configured' => true,
        ]);

    $syncRun = SyncRun::query()->firstOrFail();

    expect($syncRun->type)->toBe('incremental')
        ->and($syncRun->status)->toBe('running')
        ->and($syncRun->items_synced)->toBe(0);

    Queue::assertPushed(SyncGarminJob::class, function (SyncGarminJob $job) use ($syncRun): bool {
        return $job->syncRunId === $syncRun->id
            && $job->tries === 1
            && $job->timeout === 600;
    });
});

it('redirects back with a flash message for web POST /sync requests', function () {
    Queue::fake([SyncGarminJob::class]);
    config(['services.garmin.key' => 'test-garmin-key']);

    $this->from('/')
        ->post(route('sync.store'))
        ->assertRedirect('/')
        ->assertSessionHas('syncStatus', 'Garmin sync started in the background.');

    Queue::assertPushed(SyncGarminJob::class);
});

it('executes SyncGarminJob against the existing SyncRun without creating duplicates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));
    Http::preventStrayRequests();

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);

    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => CarbonImmutable::now()->addHours(24)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $jwt = "{$header}.{$payload}.sig";

    Http::fake([
        'https://personal.connectapi.garmin.com/personalapitoken-service/*' => Http::response($jwt, 200),
        '*' => Http::response([], 200),
    ]);

    $syncRun = SyncRun::query()->create([
        'type' => 'incremental',
        'status' => 'running',
        'started_at' => CarbonImmutable::now(),
        'items_synced' => 0,
    ]);

    $job = new SyncGarminJob($syncRun->id);
    $job->handle(app(GarminSyncService::class));

    expect(SyncRun::query()->count())->toBe(1);

    $syncRun->refresh();

    expect($syncRun->status)->toBe('completed')
        ->and($syncRun->type)->toBe('incremental')
        ->and($syncRun->finished_at)->not->toBeNull();

    $this->getJson(route('sync.status'))
        ->assertOk()
        ->assertJson([
            'status' => 'completed',
            'type' => 'incremental',
            'provider_configured' => true,
        ]);
});

it('marks the SyncRun as failed when SyncGarminJob fails terminally', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    $syncRun = SyncRun::query()->create([
        'type' => 'incremental',
        'status' => 'running',
        'started_at' => CarbonImmutable::now(),
        'items_synced' => 0,
    ]);

    $job = new SyncGarminJob($syncRun->id);
    $job->failed(new RuntimeException('Worker timeout exceeded'));

    $syncRun->refresh();

    expect($syncRun->status)->toBe('failed')
        ->and($syncRun->finished_at)->not->toBeNull()
        ->and($syncRun->error_message)->toBe('Worker timeout exceeded');
});

it('rejects backfill requests when GARMIN_KEY is missing or range is invalid', function () {
    Queue::fake([SyncGarminBackfillJob::class]);
    config(['services.garmin.key' => '']);

    $this->postJson(route('sync.backfill'), ['range' => '1y'])
        ->assertUnprocessable()
        ->assertJson([
            'provider_configured' => false,
        ]);

    config(['services.garmin.key' => 'test-garmin-key']);

    $this->postJson(route('sync.backfill'), ['range' => 'forever'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');

    Queue::assertNothingPushed();
});

it('queues a chunked SyncGarminBackfillJob for each supported range', function (string $range, ?string $expectedUntil) {
    Queue::fake([SyncGarminBackfillJob::class]);
    config([
        'services.garmin.key' => 'test-garmin-key',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));

    $this->postJson(route('sync.backfill'), ['range' => $range])
        ->assertStatus(202)
        ->assertJson([
            'status' => 'running',
            'type' => 'backfill',
            'cursor_date' => '2026-11-21',
            'provider_configured' => true,
        ]);

    $syncRun = SyncRun::query()->latest('id')->firstOrFail();

    Queue::assertPushed(SyncGarminBackfillJob::class, function (SyncGarminBackfillJob $job) use ($syncRun, $expectedUntil): bool {
        return $job->syncRunId === $syncRun->id
            && $job->untilDate === $expectedUntil
            && $job->includeInitialSetup === true
            && $job->tries === 1
            && $job->timeout === 600;
    });
})->with([
    '90d' => ['90d', '2026-08-24'],
    '1y' => ['1y', '2025-11-22'],
    '3y' => ['3y', '2023-11-21'],
    'all' => ['all', null],
]);

it('resumes from the latest failed backfill cursor when range is resume', function () {
    Queue::fake([SyncGarminBackfillJob::class]);
    config([
        'services.garmin.key' => 'test-garmin-key',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));

    SyncRun::factory()->create([
        'type' => 'backfill',
        'status' => 'failed',
        'started_at' => CarbonImmutable::now()->subHour(),
        'finished_at' => CarbonImmutable::now()->subMinutes(50),
        'cursor_date' => '2025-04-15',
    ]);

    $this->postJson(route('sync.backfill'), ['range' => 'resume'])
        ->assertStatus(202)
        ->assertJson([
            'status' => 'running',
            'type' => 'backfill',
            'cursor_date' => '2025-04-15',
        ]);

    Queue::assertPushed(SyncGarminBackfillJob::class, function (SyncGarminBackfillJob $job): bool {
        return $job->untilDate === null && $job->includeInitialSetup === true;
    });
});

it('blocks overlapping syncs while a long-running chunked backfill is still actively updating', function () {
    Queue::fake([SyncGarminJob::class, SyncGarminBackfillJob::class]);
    config(['services.garmin.key' => 'test-garmin-key']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    // Started 25 minutes ago, but updated 1 minute ago by the latest chunk
    $activeBackfill = SyncRun::factory()->create([
        'type' => 'backfill',
        'status' => 'running',
        'started_at' => CarbonImmutable::now()->subMinutes(25),
        'finished_at' => null,
        'cursor_date' => '2025-01-10',
    ]);
    $activeBackfill->forceFill(['updated_at' => CarbonImmutable::now()->subMinute()])->save();

    $this->postJson(route('sync.backfill'), ['range' => '1y'])
        ->assertOk()
        ->assertJson([
            'already_running' => true,
            'type' => 'backfill',
            'cursor_date' => '2025-01-10',
        ]);

    $this->postJson(route('sync.store'))
        ->assertOk()
        ->assertJson(['already_running' => true]);

    Queue::assertNothingPushed();
});

it('self-chains SyncGarminBackfillJob across chunks until the target date is reached and preserves cursor on failure', function () {
    Queue::fake([SyncGarminBackfillJob::class]);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00', 'Pacific/Auckland'));

    config([
        'services.garmin.key' => 'test-garmin-key',
        'services.garmin.base_url' => 'https://personal.connectapi.garmin.com',
        'app.display_timezone' => 'Pacific/Auckland',
    ]);

    $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['exp' => CarbonImmutable::now()->addHours(24)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $jwt = "{$header}.{$payload}.sig";

    Http::fake(function (Request $request) use ($jwt) {
        $url = $request->url();

        if (str_contains($url, '/personalapitoken-service/')) {
            return Http::response($jwt, 200);
        }

        if (str_contains($url, '/fitnessage-service/fitnessage/v2/')) {
            return Http::response([
                'chronologicalAge' => 43,
                'fitnessAge' => 35.2,
            ], 200);
        }

        return Http::response([], 200);
    });

    $syncRun = SyncRun::query()->create([
        'type' => 'backfill',
        'status' => 'running',
        'started_at' => CarbonImmutable::now(),
        'cursor_date' => '2026-11-21',
        'items_synced' => 0,
    ]);

    // Chunk 1: processes 1 window (2026-10-25..2026-11-21) -> cursor moves to 2026-10-24 and dispatches Chunk 2
    $chunk1 = new SyncGarminBackfillJob(
        syncRunId: $syncRun->id,
        untilDate: '2026-10-01',
        includeInitialSetup: true,
        windowsPerChunk: 1,
    );
    $chunk1->handle(app(GarminSyncService::class));

    $syncRun->refresh();
    expect($syncRun->status)->toBe('running')
        ->and($syncRun->cursor_date)->toBe('2026-10-24')
        ->and($syncRun->items_synced)->toBeGreaterThan(0);

    Queue::assertPushed(SyncGarminBackfillJob::class, function (SyncGarminBackfillJob $next) use ($syncRun): bool {
        return $next->syncRunId === $syncRun->id
            && $next->untilDate === '2026-10-01'
            && $next->includeInitialSetup === false
            && $next->windowsPerChunk === 1;
    });

    // Chunk 2: finishes 2026-10-01..2026-10-24 -> marks SyncRun completed and clears cursor
    $chunk2 = new SyncGarminBackfillJob(
        syncRunId: $syncRun->id,
        untilDate: '2026-10-01',
        includeInitialSetup: false,
        windowsPerChunk: 1,
    );
    $chunk2->handle(app(GarminSyncService::class));

    $syncRun->refresh();
    expect($syncRun->status)->toBe('completed')
        ->and($syncRun->cursor_date)->toBeNull()
        ->and($syncRun->finished_at)->not->toBeNull();

    // Terminal failure preserves cursor_date for resume
    $failedRun = SyncRun::query()->create([
        'type' => 'backfill',
        'status' => 'running',
        'started_at' => CarbonImmutable::now(),
        'cursor_date' => '2025-07-01',
        'items_synced' => 42,
    ]);

    (new SyncGarminBackfillJob($failedRun->id))->failed(new RuntimeException('Rate limit exhausted'));

    $failedRun->refresh();
    expect($failedRun->status)->toBe('failed')
        ->and($failedRun->cursor_date)->toBe('2025-07-01')
        ->and($failedRun->error_message)->toBe('Rate limit exhausted');
});
