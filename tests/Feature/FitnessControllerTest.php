<?php

use App\Models\Activity;
use App\Models\DailyMetric;
use App\Models\HrvRecord;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.display_timezone' => 'Pacific/Auckland']);
    $this->travelTo(CarbonImmutable::parse('2026-11-26 10:00:00', 'Pacific/Auckland'));
});

it('renders the Fitness/Index Inertia page with default 180d range and unified cross-sport props', function () {
    Activity::factory()->create([
        'name' => 'Threshold Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-26 07:00:00'),
        'tss' => null,
        'training_load' => 92.0,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Endurance Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-24 08:00:00'),
        'tss' => 115.0,
        'training_load' => null,
    ]);

    HrvRecord::factory()->create([
        'calendar_date' => '2026-11-26',
        'last_night_avg' => 58,
    ]);

    DailyMetric::factory()->create([
        'calendar_date' => '2026-11-26',
        'resting_hr' => 49,
    ]);

    $this->get('/fitness')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fitness/Index')
            ->where('has_data', true)
            ->where('filters.sport', 'all')
            ->where('filters.range', '180d')
            ->where('filters.projection_days', 14)
            ->where('dateWindow.range', '180d')
            ->where('dateWindow.end', '2026-11-26')
            ->where('today', '2026-11-26')
            ->where('health_availability.hrv', true)
            ->where('health_availability.resting_hr', true)
            ->has('daily', 180)
            ->has('projections.rest', 14)
            ->has('projections.maintenance', 14)
            ->has('weekly_sport_load', 12)
            ->has('recent_sessions', 2)
            ->has('available_sports')
            ->has('summary', fn (Assert $summary) => $summary
                ->has('ctl')
                ->has('atl')
                ->has('tsb')
                ->has('acwr')
                ->has('ramp_rate_7d')
                ->where('load_7d', 207)
                ->has('load_28d')
                ->has('form_zone.key')
                ->has('acwr_status.key')
                ->where('load_source_mix.tss_count', 1)
                ->where('load_source_mix.garmin_load_count', 1)
                ->has('maintenance_daily_load')
                ->has('projected_peak_date')
                ->has('projected_peak_dates'),
            ),
        );
});

it('applies sport, range, custom bounds, and clamped projection_days query parameters', function () {
    Activity::factory()->create([
        'name' => 'Morning Run',
        'started_at_local' => CarbonImmutable::parse('2026-11-20 07:00:00'),
        'tss' => 75.0,
        'training_load' => null,
    ]);

    Activity::factory()->roadBiking()->create([
        'name' => 'Grinder Ride',
        'started_at_local' => CarbonImmutable::parse('2026-11-21 08:00:00'),
        'tss' => 130.0,
        'training_load' => null,
    ]);

    $this->get('/fitness?sport=running&range=custom&from=2026-11-15&to=2026-11-26&projection_days=21')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fitness/Index')
            ->where('filters.sport', 'running')
            ->where('filters.range', 'custom')
            ->where('filters.projection_days', 21)
            ->where('dateWindow.from', '2026-11-15')
            ->where('dateWindow.to', '2026-11-26')
            ->has('daily', 12)
            ->has('projections.rest', 21)
            ->has('recent_sessions', 1)
            ->where('recent_sessions.0.name', 'Morning Run'),
        );

    $this->get('/fitness?sport=unknown_sport&range=90d&projection_days=99')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fitness/Index')
            ->where('filters.sport', 'all')
            ->where('filters.range', '90d')
            ->where('filters.projection_days', 21)
            ->has('daily', 90),
        );
});
