<?php

use App\Jobs\GeocodeSegmentJob;
use App\Jobs\RefreshSubClimbFlagsJob;
use App\Models\Activity;
use App\Models\Segment;
use App\Models\SyncRun;
use App\Support\AppSettings;
use App\Support\SportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the unified settings page with units, timezone, provider status, and sports settings', function () {
    config([
        'app.display_timezone' => 'Pacific/Auckland',
        'app.default_units' => 'metric',
        'services.garmin.key' => 'test-garmin-key',
    ]);

    Activity::factory()->create([
        'sport_category' => 'hiking',
    ]);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('unit_system', 'metric')
            ->where('timezone', 'Pacific/Auckland')
            ->where('provider.name', 'Garmin Connect')
            ->where('provider.configured', true)
            ->where('backfill.can_resume', false)
            ->where('backfill.resume_cursor_date', null)
            ->where('sports_settings.config.mode', 'auto')
            ->where('sports_settings.counts.hiking', 1)
            ->has('sports_settings.hints', 1)
            ->where('sports_settings.hints.0.key', 'hiking'),
        );
});

it('reports provider as unconfigured when GARMIN_KEY is empty', function () {
    config([
        'services.garmin.key' => '',
    ]);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('provider.name', 'Garmin Connect')
            ->where('provider.configured', false),
        );
});

it('exposes resumable backfill metadata on settings when the latest backfill failed with a cursor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    SyncRun::factory()->create([
        'type' => 'backfill',
        'status' => 'completed',
        'started_at' => CarbonImmutable::now()->subDays(2),
        'finished_at' => CarbonImmutable::now()->subDays(2)->addMinutes(10),
        'cursor_date' => null,
    ]);

    SyncRun::factory()->create([
        'type' => 'backfill',
        'status' => 'failed',
        'started_at' => CarbonImmutable::now()->subHour(),
        'finished_at' => CarbonImmutable::now()->subMinutes(50),
        'cursor_date' => '2025-03-10',
        'until_date' => '2025-01-01',
        'error_message' => 'Temporary Garmin API outage',
    ]);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('backfill.can_resume', true)
            ->where('backfill.resume_cursor_date', '2025-03-10')
            ->where('backfill.resume_until_date', '2025-01-01')
            ->where('backfill.last_error', 'Temporary Garmin API outage')
            ->where('backfill.last_completed_at', fn ($value): bool => is_string($value) && $value !== ''),
        );
});

it('reports a stale running backfill as interrupted in shared sync state so Resume is immediately visible', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 15:00:00'));

    $staleRun = SyncRun::factory()->create([
        'type' => 'backfill',
        'status' => 'running',
        'started_at' => CarbonImmutable::now()->subMinutes(30),
        'finished_at' => null,
        'cursor_date' => '2025-08-01',
        'until_date' => '2025-01-01',
    ]);
    $staleRun->forceFill(['updated_at' => CarbonImmutable::now()->subMinutes(15)])->save();

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('sync.id', $staleRun->id)
            ->where('sync.status', 'interrupted')
            ->where('backfill.can_resume', true)
            ->where('backfill.resume_cursor_date', '2025-08-01')
            ->where('backfill.resume_until_date', '2025-01-01'),
        );
});

it('redirects the legacy appearance route to unified settings', function () {
    $this->get(route('appearance.edit'))
        ->assertRedirect('/settings');
});

it('updates the preferred unit system', function () {
    $this->from(route('settings.index'))
        ->patch(route('settings.units.update'), [
            'unit_system' => 'imperial',
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('status', 'Unit preferences updated.');

    $settings = app(AppSettings::class);
    $settings->flushMemo();

    expect($settings->unitSystem())->toBe('imperial');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('unit_system', 'imperial')
            ->where('units', 'imperial'),
        );
});

it('validates the unit system update request', function (mixed $value) {
    $this->from(route('settings.index'))
        ->patch(route('settings.units.update'), [
            'unit_system' => $value,
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors('unit_system');
})->with([
    'missing' => [null],
    'empty' => [''],
    'unknown' => ['nautical'],
]);

it('updates custom sports settings and resets back to auto mode', function () {
    $payload = [
        'mode' => 'custom',
        'pin_sub_disciplines' => true,
        'order' => ['cycling', 'running', 'hiking', 'walking', 'swimming', 'cardio'],
        'families' => [
            'running' => [
                'mode' => 'split',
                'disciplines' => ['road_running', 'trail_running'],
            ],
            'cycling' => [
                'mode' => 'split',
                'disciplines' => ['road_cycling', 'gravel_cycling', 'mtb_cycling'],
            ],
        ],
        'secondary' => ['hiking', 'swimming'],
        'rollups' => [
            'trail_running' => 'own',
            'track_running' => 'road_running',
            'gravel_cycling' => 'own',
            'indoor_cycling' => 'own',
            'ebike_cycling' => 'exclude',
        ],
        'best_efforts' => [
            'trail_in_running_prs' => false,
            'cycling_disciplines' => ['road', 'gravel'],
        ],
    ];

    $this->from(route('settings.index'))
        ->patch(route('settings.sports.update'), $payload)
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('status', 'Sports configuration updated.');

    $registry = app(SportRegistry::class);
    app(AppSettings::class)->flushMemo();
    $registry->flushMemo();

    $resolved = $registry->resolvedConfig();

    expect($resolved['mode'])->toBe('custom')
        ->and($resolved['pin_sub_disciplines'])->toBeTrue()
        ->and($resolved['order'])->toBe(['cycling', 'running', 'hiking', 'walking', 'swimming', 'cardio'])
        ->and($resolved['families']['running'])->toBe([
            'mode' => 'split',
            'disciplines' => ['road_running', 'trail_running'],
        ])
        ->and($resolved['families']['cycling'])->toBe([
            'mode' => 'split',
            'disciplines' => ['road_cycling', 'gravel_cycling', 'mtb_cycling'],
        ])
        ->and($resolved['secondary'])->toBe(['hiking', 'swimming'])
        ->and($resolved['rollups'])->toBe([
            'trail_running' => 'own',
            'track_running' => 'road_running',
            'gravel_cycling' => 'own',
            'indoor_cycling' => 'own',
            'ebike_cycling' => 'exclude',
        ])
        ->and($resolved['best_efforts'])->toBe([
            'trail_in_running_prs' => false,
            'cycling_disciplines' => ['road', 'gravel'],
        ]);

    // Switch back to auto mode
    $this->from(route('settings.index'))
        ->patch(route('settings.sports.update'), [
            'mode' => 'auto',
            'pin_sub_disciplines' => false,
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('status', 'Sports configuration updated.');

    app(AppSettings::class)->flushMemo();
    $registry->flushMemo();

    expect($registry->resolvedConfig()['mode'])->toBe('auto')
        ->and($registry->resolvedConfig()['pin_sub_disciplines'])->toBeFalse();
});

it('validates the sports settings update request', function () {
    $this->from(route('settings.index'))
        ->patch(route('settings.sports.update'), [
            'mode' => 'invalid',
            'pin_sub_disciplines' => 'not-a-bool',
            'order' => ['running', 'rowing'],
            'families' => [
                'running' => [
                    'mode' => 'nested',
                    'disciplines' => ['ultra_running'],
                ],
                'cycling' => [
                    'mode' => 'nested',
                    'disciplines' => ['bmx_cycling'],
                ],
            ],
            'secondary' => ['skiing'],
            'rollups' => [
                'trail_running' => 'invalid',
                'track_running' => 'invalid',
                'gravel_cycling' => 'invalid',
                'indoor_cycling' => 'invalid',
                'ebike_cycling' => 'invalid',
            ],
            'best_efforts' => [
                'trail_in_running_prs' => 'nope',
                'cycling_disciplines' => ['commute'],
            ],
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors([
            'mode',
            'pin_sub_disciplines',
            'order.1',
            'families.running.mode',
            'families.running.disciplines.0',
            'families.cycling.mode',
            'families.cycling.disciplines.0',
            'secondary.0',
            'rollups.trail_running',
            'rollups.track_running',
            'rollups.gravel_cycling',
            'rollups.indoor_cycling',
            'rollups.ebike_cycling',
            'best_efforts.trail_in_running_prs',
            'best_efforts.cycling_disciplines.0',
        ]);
});

it('updates segments minimum efforts threshold, validates bounds, and queues geocoding for newly visible climbs', function () {
    Queue::fake([GeocodeSegmentJob::class, RefreshSubClimbFlagsJob::class]);
    config([
        'services.nominatim.enabled' => true,
        'services.nominatim.auto_sync' => true,
    ]);

    $threeEffortClimb = Segment::factory()->climb('cat4')->create([
        'effort_count' => 3,
        'detection_count' => 3,
        'geocoded_at' => null,
    ]);

    Segment::factory()->climb('cat4')->create([
        'effort_count' => 1,
        'detection_count' => 1,
        'geocoded_at' => null,
    ]);

    // Default min_efforts is 5 -> 0 visible of 2 tracked
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('segments_settings.min_efforts', 5)
            ->where('segments_settings.default_min_efforts', 5)
            ->where('segments_settings.visible_climbs_count', 0)
            ->where('segments_settings.tracked_climbs_count', 2),
        );

    // Invalid bounds rejected
    $this->from(route('settings.index'))
        ->patch(route('settings.segments.update'), ['min_efforts' => 0])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors('min_efforts');

    $this->from(route('settings.index'))
        ->patch(route('settings.segments.update'), ['min_efforts' => 51])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors('min_efforts');

    // Lower threshold to 3 -> immediately surfaces $threeEffortClimb and queues its geocoding
    $this->from(route('settings.index'))
        ->patch(route('settings.segments.update'), ['min_efforts' => 3])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHas('status', 'Auto-detected climb threshold updated to 3 efforts.');

    $settings = app(AppSettings::class);
    $settings->flushMemo();
    expect($settings->segmentsMinEfforts())->toBe(3);

    Queue::assertPushed(GeocodeSegmentJob::class, 1);
    Queue::assertPushed(GeocodeSegmentJob::class, fn (GeocodeSegmentJob $job): bool => $job->segmentId === $threeEffortClimb->id);
    Queue::assertPushed(RefreshSubClimbFlagsJob::class, 1);
    Queue::assertPushed(RefreshSubClimbFlagsJob::class, fn (RefreshSubClimbFlagsJob $job): bool => $job->sportCategory === 'biking');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('segments_settings.min_efforts', 3)
            ->where('segments_settings.visible_climbs_count', 1)
            ->where('segments_settings.tracked_climbs_count', 2),
        );
});
