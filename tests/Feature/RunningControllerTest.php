<?php

use App\Models\Activity;
use App\Models\BestEffort;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the running hub page with filtered Inertia props', function () {
    $outdoorRun = Activity::factory()->create([
        'name' => 'Mission Bay 10K',
        'sport_category' => 'running',
        'is_indoor' => false,
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 2700.0,
    ]);

    Activity::factory()->indoor()->create([
        'name' => 'Treadmill Easy 5K',
        'activity_type_key' => 'treadmill_running',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-19 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1500.0,
    ]);

    BestEffort::factory()->create([
        'activity_id' => $outdoorRun->id,
        'sport' => 'running',
        'distance_key' => '10k',
        'distance_meters' => 10000.0,
        'elapsed_seconds' => 2680.0,
        'achieved_at' => $outdoorRun->started_at_local,
    ]);

    $this->get('/running?environment=outdoor&volumeRange=6m&year=2026&zoneRange=90d')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('filters.environment', 'outdoor')
            ->where('filters.volumeRange', '6m')
            ->where('filters.year', 2026)
            ->where('filters.zoneRange', '90d')
            ->where('summary.ytd_sessions', 1)
            ->where('summary.ytd_distance_meters', 10000)
            ->has('modules.pace.bestEfforts.distances', 14)
            ->has('weeklyVolume', 26)
            ->where('hrZones.range', '90d')
            ->where('modules.pace.racePredictor.source_label', '10K')
            ->has('recentActivities', 1)
            ->where('recentActivities.0.name', 'Mission Bay 10K'),
        );
});

it('supports stepped and custom volume and HR zone windows on endurance and general sport hubs', function () {
    Activity::factory()->create([
        'name' => 'Spring 15K',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-06-18 07:00:00'),
        'distance_meters' => 15000.0,
        'duration_seconds' => 4200.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 3000.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 1200.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    Activity::factory()->create([
        'name' => 'November 5K',
        'sport_category' => 'running',
        'started_at_local' => CarbonImmutable::parse('2026-11-18 07:00:00'),
        'distance_meters' => 5000.0,
        'duration_seconds' => 1400.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 1000.0,
        'hr_zone_3_seconds' => 0.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    Activity::factory()->create([
        'name' => 'June Pool Session',
        'sport_category' => 'swimming',
        'activity_type_key' => 'lap_swimming',
        'started_at_local' => CarbonImmutable::parse('2026-06-16 07:00:00'),
        'distance_meters' => 2000.0,
        'duration_seconds' => 2400.0,
        'hr_zone_1_seconds' => 0.0,
        'hr_zone_2_seconds' => 0.0,
        'hr_zone_3_seconds' => 1800.0,
        'hr_zone_4_seconds' => 0.0,
        'hr_zone_5_seconds' => 0.0,
    ]);

    $this->get('/running?volumeRange=12w&volumeEnd=2026-08-30&zoneRange=custom&zoneFrom=2026-06-01&zoneTo=2026-06-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/Endurance')
            ->where('filters.volumeRange', '12w')
            ->where('filters.volumeWindow.isLatest', false)
            ->where('filters.volumeWindow.end', '2026-08-30')
            ->where('filters.zoneRange', 'custom')
            ->where('filters.zoneWindow.from', '2026-06-01')
            ->where('filters.zoneWindow.to', '2026-06-30')
            ->has('weeklyVolume', 12)
            ->where('hrZones.total_seconds', 4200),
        );

    $this->get('/sports/swimming?volumeRange=custom&volumeFrom=2026-06-01&volumeTo=2026-06-30&zoneRange=30d&zoneEnd=2026-06-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Sports/General')
            ->where('filters.volumeRange', 'custom')
            ->where('filters.volumeWindow.from', '2026-06-01')
            ->where('filters.volumeWindow.to', '2026-06-30')
            ->where('filters.zoneRange', '30d')
            ->where('filters.zoneWindow.isLatest', false)
            ->where('hrZones.total_seconds', 1800),
        );
});

it('updates, clears, excludes, restores, and validates best efforts', function () {
    $effort = BestEffort::factory()->create([
        'sport' => 'running',
        'distance_key' => '5k',
        'distance_meters' => 5000.0,
        'elapsed_seconds' => 1210.0,
        'override_seconds' => null,
        'is_excluded' => false,
    ]);

    // Set manual chip time override
    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'override_seconds' => 1195.5,
        ])
        ->assertRedirect('/running')
        ->assertSessionHas('status', 'Best effort updated.');

    expect($effort->fresh()?->override_seconds)->toBe(1195.5)
        ->and($effort->fresh()?->is_excluded)->toBeFalse();

    // Exclude effort without clearing override_seconds
    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'is_excluded' => true,
        ])
        ->assertRedirect('/running')
        ->assertSessionHas('status', 'Best effort updated.');

    expect($effort->fresh()?->is_excluded)->toBeTrue()
        ->and($effort->fresh()?->override_seconds)->toBe(1195.5);

    // Restore effort and clear override_seconds
    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'override_seconds' => null,
            'is_excluded' => false,
        ])
        ->assertRedirect('/running')
        ->assertSessionHas('status', 'Best effort updated.');

    expect($effort->fresh()?->is_excluded)->toBeFalse()
        ->and($effort->fresh()?->override_seconds)->toBeNull();

    // Validation failures
    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'override_seconds' => 0,
        ])
        ->assertRedirect('/running')
        ->assertSessionHasErrors(['override_seconds']);

    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'override_seconds' => 90000,
        ])
        ->assertRedirect('/running')
        ->assertSessionHasErrors(['override_seconds']);

    $this->from('/running')
        ->patch("/best-efforts/{$effort->id}", [
            'is_excluded' => 'not-a-boolean',
        ])
        ->assertRedirect('/running')
        ->assertSessionHasErrors(['is_excluded']);
});
