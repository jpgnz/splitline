<?php

use App\Jobs\SyncActivityStreamJob;
use App\Jobs\SyncActivityWeatherJob;
use App\Models\Activity;
use App\Models\ActivityRouteFingerprint;
use App\Models\ActivityStream;
use App\Models\ActivityWeather;
use App\Services\Sync\QueueStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

it('summarizes queued database jobs by queue and pending activity enrichments', function () {
    config(['queue.default' => 'database']);

    // Queued jobs across default, streams, and weather queues
    $now = CarbonImmutable::now()->getTimestamp();

    DB::table('jobs')->insert([
        ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityWeatherJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    // 1. Outdoor non-manual activity awaiting streams AND missing weather
    Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    // 2. Manual activity without streams — must NOT count as awaiting streams
    Activity::factory()->create([
        'is_manual' => true,
        'is_indoor' => true,
        'start_lat' => null,
        'start_lng' => null,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    // 3. Streamed outdoor activity with start_point weather (upgradeable -> counts as missing weather) and unfingerprinted route
    $streamedUpgradeable = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'distance_meters' => 5000.0,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => CarbonImmutable::now(),
    ]);

    $longPolyline = [];
    for ($i = 0; $i < 15; $i++) {
        $longPolyline[] = [-36.85 + ($i * 0.001), 174.76 + ($i * 0.001)];
    }

    ActivityStream::factory()->create([
        'activity_id' => $streamedUpgradeable->id,
        'polyline' => $longPolyline,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $streamedUpgradeable->id,
        'sampling_mode' => 'start_point',
    ]);

    // 4. Fully enriched outdoor activity (route-sampled weather + fingerprinted) — counts in none
    $fullyEnriched = Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'distance_meters' => 8000.0,
        'start_lat' => -36.86,
        'start_lng' => 174.77,
        'streams_synced_at' => CarbonImmutable::now(),
    ]);

    ActivityStream::factory()->create([
        'activity_id' => $fullyEnriched->id,
        'polyline' => $longPolyline,
    ]);

    ActivityWeather::factory()->create([
        'activity_id' => $fullyEnriched->id,
        'sampling_mode' => 'route',
    ]);

    ActivityRouteFingerprint::factory()->create([
        'activity_id' => $fullyEnriched->id,
    ]);

    $summary = app(QueueStatusService::class)->summary();

    expect($summary)->toBe([
        'jobs' => [
            'default' => 1,
            'streams' => 2,
            'weather' => 1,
            'total' => 4,
        ],
        'pending' => [
            'streams' => 1,
            'weather' => 2,
            'routes' => 1,
        ],
        'has_active_work' => true,
    ]);
});

it('returns zero queued job counts gracefully when queue driver is not database', function () {
    config(['queue.default' => 'sync']);

    $now = CarbonImmutable::now()->getTimestamp();
    DB::table('jobs')->insert([
        ['queue' => 'streams', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    $summary = app(QueueStatusService::class)->summary();

    expect($summary['jobs'])->toBe([
        'default' => 0,
        'streams' => 0,
        'weather' => 0,
        'total' => 0,
    ])->and($summary['has_active_work'])->toBeFalse();
});

it('exposes only cheap queued-job counts on the sync status endpoint and shared props, and full pending counts on the settings page', function () {
    config(['queue.default' => 'database']);

    $now = CarbonImmutable::now()->getTimestamp();
    DB::table('jobs')->insert([
        ['queue' => SyncActivityStreamJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ['queue' => SyncActivityWeatherJob::QUEUE, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
    ]);

    Activity::factory()->create([
        'is_manual' => false,
        'is_indoor' => false,
        'start_lat' => -36.85,
        'start_lng' => 174.76,
        'streams_synced_at' => null,
        'streams_unavailable_at' => null,
    ]);

    $this->getJson(route('sync.status'))
        ->assertOk()
        ->assertJsonPath('enrichments.jobs.streams', 1)
        ->assertJsonPath('enrichments.jobs.weather', 1)
        ->assertJsonPath('enrichments.jobs.default', 0)
        ->assertJsonPath('enrichments.jobs.total', 2)
        ->assertJsonPath('enrichments.has_active_work', true)
        ->assertJsonMissingPath('enrichments.pending');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('enrichment_queue.jobs.streams', 1)
            ->where('enrichment_queue.jobs.weather', 1)
            ->where('enrichment_queue.jobs.total', 2)
            ->where('enrichment_queue.pending.streams', 1)
            ->where('enrichment_queue.pending.weather', 1)
            ->where('enrichment_queue.has_active_work', true)
            ->where('sync.enrichments.jobs.total', 2)
            ->where('sync.enrichments.has_active_work', true)
            ->missing('sync.enrichments.pending'),
        );
});
