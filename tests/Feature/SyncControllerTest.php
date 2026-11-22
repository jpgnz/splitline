<?php

use App\Jobs\SyncGarminJob;
use App\Models\SyncRun;
use App\Services\Garmin\GarminSyncService;
use Carbon\CarbonImmutable;
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
