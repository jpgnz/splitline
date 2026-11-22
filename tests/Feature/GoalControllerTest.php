<?php

use App\Models\Activity;
use App\Models\Goal;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-21 10:00:00', 'Pacific/Auckland'));
});

it('renders the goals and streaks index page with active goals, archived goals, and streaks', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'distance_meters' => 12000.0,
    ]);

    $activeGoal = Goal::factory()->weekly()->create([
        'title' => 'Weekly Running Target',
        'sport' => 'running',
        'metric' => 'distance_km',
        'target_value' => 40.0,
    ]);
    $archivedGoal = Goal::factory()->monthly()->archived()->create([
        'title' => 'Archived Cycling Target',
        'sport' => 'road_biking',
        'metric' => 'distance_km',
        'target_value' => 500.0,
    ]);

    $this->get('/goals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Goals/Index')
            ->has('active', 1)
            ->where('active.0.id', $activeGoal->id)
            ->where('active.0.current_value', 12)
            ->has('archived', 1)
            ->where('archived.0.id', $archivedGoal->id)
            ->where('streaks.current_daily_streak', 1)
            ->where('streaks.by_sport.running.current_weekly_streak', 1),
        );
});

it('creates, updates, archives, restores, and deletes custom goals', function () {
    // Store
    $this->from('/goals')
        ->post('/goals', [
            'title' => 'Annual Climb Challenge',
            'sport' => 'mtb_biking',
            'metric' => 'elevation_m',
            'period' => 'yearly',
            'target_value' => 25000,
            'year' => 2026,
        ])
        ->assertRedirect('/goals')
        ->assertSessionHas('status', 'Goal created.');

    $goal = Goal::query()->firstOrFail();

    expect($goal->title)->toBe('Annual Climb Challenge')
        ->and($goal->sport)->toBe('mtb_biking')
        ->and($goal->metric)->toBe('elevation_m')
        ->and($goal->period)->toBe('yearly')
        ->and($goal->target_value)->toBe(25000.0)
        ->and($goal->year)->toBe(2026)
        ->and($goal->is_active)->toBeTrue();

    // Update (switching to weekly clears year)
    $this->from('/goals')
        ->patch("/goals/{$goal->id}", [
            'title' => 'Weekly Trail Vert',
            'sport' => 'mtb_biking',
            'metric' => 'elevation_m',
            'period' => 'weekly',
            'target_value' => 1200,
            'year' => 2026,
        ])
        ->assertRedirect('/goals')
        ->assertSessionHas('status', 'Goal updated.');

    $goal->refresh();

    expect($goal->title)->toBe('Weekly Trail Vert')
        ->and($goal->period)->toBe('weekly')
        ->and($goal->target_value)->toBe(1200.0)
        ->and($goal->year)->toBeNull();

    // Archive toggle -> archive
    $this->from('/goals')
        ->post("/goals/{$goal->id}/archive")
        ->assertRedirect('/goals')
        ->assertSessionHas('status', 'Goal archived.');

    expect($goal->fresh()?->is_active)->toBeFalse();

    // Archive toggle -> restore
    $this->from('/goals')
        ->post("/goals/{$goal->id}/archive")
        ->assertRedirect('/goals')
        ->assertSessionHas('status', 'Goal restored.');

    expect($goal->fresh()?->is_active)->toBeTrue();

    // Destroy
    $this->from('/goals')
        ->delete("/goals/{$goal->id}")
        ->assertRedirect('/goals')
        ->assertSessionHas('status', 'Goal deleted.');

    expect(Goal::query()->find($goal->id))->toBeNull();
});

it('validates goal creation and update requests', function () {
    $this->from('/goals')
        ->post('/goals', [
            'title' => '',
            'sport' => 'skydiving',
            'metric' => 'calories',
            'period' => 'daily',
            'target_value' => 0,
            'year' => 1995,
        ])
        ->assertRedirect('/goals')
        ->assertSessionHasErrors([
            'title',
            'sport',
            'metric',
            'period',
            'target_value',
            'year',
        ]);

    $goal = Goal::factory()->create();

    $this->from('/goals')
        ->patch("/goals/{$goal->id}", [
            'title' => str_repeat('a', 121),
            'sport' => 'invalid',
            'metric' => 'invalid',
            'period' => 'invalid',
            'target_value' => 1000001,
            'year' => 2101,
        ])
        ->assertRedirect('/goals')
        ->assertSessionHasErrors([
            'title',
            'sport',
            'metric',
            'period',
            'target_value',
            'year',
        ]);
});
