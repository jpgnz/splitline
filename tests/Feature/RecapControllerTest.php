<?php

use App\Models\Activity;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Pacific/Auckland'));
});

it('renders the Recap/Index Inertia page defaulting to the most recent active year', function () {
    Activity::factory()->create([
        'name' => 'Morning Tempo',
        'started_at_local' => CarbonImmutable::parse('2026-04-10 07:00:00'),
        'distance_meters' => 12000.0,
        'duration_seconds' => 3300.0,
        'elevation_gain' => 110.0,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Autumn Century',
        'started_at_local' => CarbonImmutable::parse('2025-05-12 08:00:00'),
        'distance_meters' => 100000.0,
        'duration_seconds' => 12000.0,
        'elevation_gain' => 950.0,
    ]);

    $this->get('/recap')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap/Index')
            ->where('selected_year', 2026)
            ->where('available_years', [2026, 2025])
            ->where('is_current_year', true)
            ->where('has_data', true)
            ->where('totals.activities', 1)
            ->where('totals.distance_meters', 12000)
            ->where('yoy.is_ytd_comparison', true)
            ->has('sports', 1)
            ->has('monthly', 12)
            ->has('peaks')
            ->has('consistency')
            ->has('milestones')
            ->has('weather')
            ->has('health')
            ->has('annual_summary'),
        );
});

it('renders the requested past year when year query parameter is provided', function () {
    Activity::factory()->create([
        'name' => '2026 Run',
        'started_at_local' => CarbonImmutable::parse('2026-03-01 07:00:00'),
        'distance_meters' => 10000.0,
        'duration_seconds' => 3000.0,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => '2025 Coastal Ride',
        'started_at_local' => CarbonImmutable::parse('2025-10-05 08:30:00'),
        'distance_meters' => 85000.0,
        'duration_seconds' => 10800.0,
        'elevation_gain' => 820.0,
    ]);

    $this->get('/recap?year=2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap/Index')
            ->where('selected_year', 2025)
            ->where('is_current_year', false)
            ->where('has_data', true)
            ->where('totals.activities', 1)
            ->where('totals.distance_meters', 85000)
            ->where('yoy.previous_year', 2024)
            ->where('yoy.is_ytd_comparison', false)
            ->where('peaks.longest_activity.name', '2025 Coastal Ride'),
        );
});

it('falls back to the default year when the year query parameter is invalid', function () {
    Activity::factory()->create([
        'started_at_local' => CarbonImmutable::parse('2025-07-04 07:00:00'),
        'distance_meters' => 15000.0,
        'duration_seconds' => 4500.0,
    ]);

    $this->get('/recap?year=invalid')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Recap/Index')
            ->where('selected_year', 2025)
            ->where('has_data', true),
        );
});
