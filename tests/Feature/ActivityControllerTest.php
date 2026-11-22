<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use App\Models\HeartRateZone;
use App\Services\Garmin\ActivityStreamSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    Storage::fake('local');
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the activities index with filtered activities, summary, and calendar props', function () {
    Activity::factory()->create([
        'name' => 'Port Hills Tempo',
        'location_name' => 'Christchurch',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 06:30:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3200.0,
        'training_load' => 115.0,
    ]);

    Activity::factory()->mountainBiking()->create([
        'name' => 'Woodhill Forest Loop',
        'location_name' => 'Auckland',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 08:00:00'),
        'distance_meters' => 28000.0,
        'duration_seconds' => 5400.0,
        'training_load' => 160.0,
    ]);

    $this->get('/activities?sport=running&search=hills')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Index')
            ->where('filters.sport', 'running')
            ->where('filters.search', 'hills')
            ->where('summary.count', 1)
            ->where('summary.total_distance_meters', 12000)
            ->has('activities.data', 1)
            ->where('activities.data.0.name', 'Port Hills Tempo')
            ->where('calendar.month', '2026-11'),
        );
});

it('renders the activity detail page with zones, stream, and best efforts', function () {
    HeartRateZone::factory()->create([
        'sport' => 'RUNNING',
        'max_hr' => 192,
        'zone1_floor' => 115,
        'zone2_floor' => 132,
        'zone3_floor' => 148,
        'zone4_floor' => 164,
        'zone5_floor' => 178,
    ]);

    $activity = Activity::factory()->create([
        'name' => 'Auckland Waterfront 10K',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'hr_zone_1_seconds' => 120.0,
        'hr_zone_2_seconds' => 600.0,
        'hr_zone_3_seconds' => 1200.0,
        'hr_zone_4_seconds' => 480.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    ActivityStream::factory()->create(['activity_id' => $activity->id]);

    BestEffort::factory()->create([
        'activity_id' => $activity->id,
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1185.0,
    ]);

    $this->get("/activities/{$activity->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Activities/Show')
            ->where('activity.id', $activity->id)
            ->where('activity.name', 'Auckland Waterfront 10K')
            ->has('hrZones', 5)
            ->where('hrZones.0.min_hr', 115)
            ->has('stream.polyline', 3)
            ->has('bestEfforts', 1)
            ->where('bestEfforts.0.label', '5K'),
        );
});

it('validates and persists discipline reclassification overrides and syncs road best efforts', function () {
    $activity = Activity::factory()->otherBiking()->create();

    ActivityStream::factory()->create([
        'activity_id' => $activity->id,
        'series' => [
            ['time' => 0.0, 'distance' => 0.0, 'speed' => 8.0],
            ['time' => 625.0, 'distance' => 5200.0, 'speed' => 8.0],
        ],
    ]);

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/discipline", [
            'discipline_override' => 'road',
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Activity discipline updated.');

    expect($activity->fresh()?->discipline_override)->toBe('road')
        ->and($activity->fresh()?->effectiveDiscipline())->toBe('road')
        ->and(
            BestEffort::query()
                ->where('activity_id', $activity->id)
                ->where('sport', 'road_biking')
                ->where('distance_key', '5k')
                ->exists(),
        )->toBeTrue();

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/discipline", [
            'discipline_override' => 'mtb',
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Activity discipline updated.');

    expect($activity->fresh()?->discipline_override)->toBe('mtb')
        ->and($activity->fresh()?->effectiveDiscipline())->toBe('mtb')
        ->and(BestEffort::query()->where('activity_id', $activity->id)->count())->toBe(0);

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/discipline", [
            'discipline_override' => null,
        ])
        ->assertRedirect("/activities/{$activity->id}");

    expect($activity->fresh()?->discipline_override)->toBeNull()
        ->and($activity->fresh()?->effectiveDiscipline())->toBe('other');

    $this->from("/activities/{$activity->id}")
        ->patch("/activities/{$activity->id}/discipline", [
            'discipline_override' => 'gravel_invalid',
        ])
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHasErrors(['discipline_override']);
});

it('toggles activity favorite state and redirects back', function () {
    $activity = Activity::factory()->create(['is_favorite' => false]);

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/favorite")
        ->assertRedirect("/activities/{$activity->id}");

    expect($activity->fresh()?->is_favorite)->toBeTrue();

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/favorite")
        ->assertRedirect("/activities/{$activity->id}");

    expect($activity->fresh()?->is_favorite)->toBeFalse();
});

it('syncs activity streams and flashes status on success and failure', function () {
    $activity = Activity::factory()->create();
    $stream = ActivityStream::factory()->make(['activity_id' => $activity->id]);

    $this->mock(ActivityStreamSyncService::class, function (MockInterface $mock) use ($activity, $stream): void {
        $mock->shouldReceive('sync')
            ->once()
            ->withArgs(fn (Activity $arg): bool => $arg->is($activity))
            ->andReturn($stream);
    });

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/sync-streams")
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Activity streams synced.');

    $this->mock(ActivityStreamSyncService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new RuntimeException('FIT archive unavailable'));
    });

    $this->from("/activities/{$activity->id}")
        ->post("/activities/{$activity->id}/sync-streams")
        ->assertRedirect("/activities/{$activity->id}")
        ->assertSessionHas('status', 'Failed to sync activity streams: FIT archive unavailable');
});
