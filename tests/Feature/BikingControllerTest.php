<?php

use App\Models\Activity;
use App\Models\ActivityStream;
use App\Models\BestEffort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the cycling overview page with discipline splits and unclassified rides', function () {
    Activity::factory()->roadBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 50000.0,
    ]);
    Activity::factory()->mountainBiking()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'elevation_gain' => 800.0,
    ]);
    $other = Activity::factory()->otherBiking()->create([
        'name' => 'Generic Cycling Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
    ]);

    $this->get('/cycling')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Overview')
            ->where('family.key', 'cycling')
            ->has('disciplineSummaries', 2)
            ->has('weeklyVolume', 24)
            ->has('reclassifyInbox', 1)
            ->where('reclassifyInboxTotal', 1)
            ->where('reclassifyInbox.0.id', $other->id),
        );
});

it('clears a ride from the reclassification inbox when explicitly classified as other cycling', function () {
    Activity::factory()->roadBiking()->create();
    Activity::factory()->mountainBiking()->create();

    $familyRide = Activity::factory()->otherBiking()->create([
        'name' => 'Slow Family Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 10:00:00'),
    ]);
    $stillUnreviewed = Activity::factory()->otherBiking()->create([
        'name' => 'Unreviewed Cycling Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 10:00:00'),
    ]);

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [$familyRide->id],
            'discipline' => 'other',
        ])
        ->assertRedirect('/cycling')
        ->assertSessionHas('status', 'Reclassified 1 activity as Other Cycling.');

    expect($familyRide->fresh()?->discipline_override)->toBe('other');

    $this->get('/cycling')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Overview')
            ->has('reclassifyInbox', 1)
            ->where('reclassifyInboxTotal', 1)
            ->where('reclassifyInbox.0.id', $stillUnreviewed->id),
        );
});

it('renders the road cycling page and respects the environment query filter', function () {
    Activity::factory()->roadBiking()->create([
        'name' => 'Outdoor Loop',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 60000.0,
    ]);
    Activity::factory()->roadBiking()->indoor()->create([
        'name' => 'Trainer Intervals',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 30000.0,
    ]);

    $this->get('/cycling/road?environment=indoor')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('filters.environment', 'indoor')
            ->where('summary.ytd_sessions', 1)
            ->where('summary.ytd_distance_meters', 30000)
            ->has('recentActivities', 1)
            ->where('recentActivities.0.name', 'Trainer Intervals'),
        );
});

it('renders the mountain biking page with climbing, offroad dynamics, and trail region props', function () {
    Activity::factory()->mountainBiking()->create([
        'name' => 'Redwoods Loop',
        'location_name' => 'Rotorua',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 09:00:00'),
        'elevation_gain' => 950.0,
        'grit' => 65.0,
        'avg_flow' => 2.9,
        'jump_count' => 12,
    ]);

    $this->get('/cycling/mtb')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('summary.total_sessions', 1)
            ->where('summary.total_elevation_gain', 950)
            ->where('modules.offroad.total_jumps', 12)
            ->has('weeklyVolume', 12)
            ->has('modules.climbing.regions', 1)
            ->where('modules.climbing.regions.0.name', 'Rotorua')
            ->has('recentActivities', 1),
        );
});

it('bulk reclassifies selected cycling activities and validates request inputs', function () {
    $first = Activity::factory()->otherBiking()->create();
    $second = Activity::factory()->otherBiking()->create();
    $untouched = Activity::factory()->otherBiking()->create();

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [$first->id, $second->id],
            'discipline' => 'mtb',
        ])
        ->assertRedirect('/cycling')
        ->assertSessionHas('status', 'Reclassified 2 activities as Mountain Biking.');

    expect($first->fresh()?->discipline_override)->toBe('mtb')
        ->and($second->fresh()?->discipline_override)->toBe('mtb')
        ->and($untouched->fresh()?->discipline_override)->toBeNull();

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [$first->id],
            'discipline' => 'road',
        ])
        ->assertRedirect('/cycling')
        ->assertSessionHas('status', 'Reclassified 1 activity as Road Cycling.');

    expect($first->fresh()?->discipline_override)->toBe('road');

    // Validation failures
    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [],
            'discipline' => 'invalid_discipline',
        ])
        ->assertRedirect('/cycling')
        ->assertSessionHasErrors(['activity_ids', 'discipline']);

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [999999],
            'discipline' => 'road',
        ])
        ->assertRedirect('/cycling')
        ->assertSessionHasErrors(['activity_ids.0']);
});

it('recalculates and clears road cycling best efforts during bulk reclassification', function () {
    $ride = Activity::factory()->otherBiking()->create();

    ActivityStream::factory()->create([
        'activity_id' => $ride->id,
        'series' => [
            ['time' => 0.0, 'distance' => 0.0, 'speed' => 8.0],
            ['time' => 320.0, 'distance' => 2600.0, 'speed' => 8.0],
            ['time' => 650.0, 'distance' => 5200.0, 'speed' => 8.0],
        ],
    ]);

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [$ride->id],
            'discipline' => 'road',
        ])
        ->assertRedirect('/cycling');

    expect(
        BestEffort::query()
            ->where('activity_id', $ride->id)
            ->where('sport', 'road_biking')
            ->where('distance_key', '5k')
            ->exists(),
    )->toBeTrue();

    $this->from('/cycling')
        ->post('/sports/reclassify', [
            'activity_ids' => [$ride->id],
            'discipline' => 'mtb',
        ])
        ->assertRedirect('/cycling');

    expect(BestEffort::query()->where('activity_id', $ride->id)->count())->toBe(0);
});
